<?php

namespace dokuwiki\plugin\aichat\Telemetry;

/**
 * Builds the configured exporter. Other backends can be registered without touching chat logic:
 *   ExporterFactory::register('mybackend', fn(array $conf, callable $http, ?Spool $spool) => new MyExporter(...));
 */
class ExporterFactory
{
    /** @var callable[] */
    protected static array $factories = [];

    public static function register(string $name, callable $factory): void
    {
        self::$factories[$name] = $factory;
    }

    public static function unregister(string $name): void
    {
        unset(self::$factories[$name]);
    }

    /**
     * @param array $conf plugin configuration
     * @return ExporterInterface|null null when export is disabled or incompletely configured
     */
    public static function create(array $conf, callable $http, ?Spool $spool = null): ?ExporterInterface
    {
        $backend = (string)($conf['telemetry'] ?? 'off');
        $timeout = (int)($conf['telemetry_timeout'] ?? 2);
        $retries = (int)($conf['telemetry_retries'] ?? 1);
        if (isset(self::$factories[$backend])) {
            return (self::$factories[$backend])($conf, $http, $spool);
        }
        if (self::configError($conf) !== '') return null;
        $url = self::effectiveUrl($conf);
        switch ($backend) {
            case 'langfuse':
                return new LangfuseExporter($url, [
                    'Authorization' => 'Basic ' . base64_encode(
                        (string)$conf['telemetry_langfuse_public'] . ':' . (string)$conf['telemetry_langfuse_secret']
                    ),
                    'x-langfuse-ingestion-version' => '4',
                ], $http, $timeout, $retries, $spool);
            case 'otlp':
                $headers = [];
                $auth = (string)($conf['telemetry_otlp_authorization'] ?? '');
                if ($auth !== '') $headers['Authorization'] = $auth;
                return new OtlpHttpExporter($url, $headers, $http, $timeout, $retries, $spool);
            default:
                return null;
        }
    }

    /**
     * Why the built-in backends cannot be used with this configuration ('' = usable).
     * URLs with embedded credentials (user:pass@) are rejected: secrets belong in the key settings.
     */
    public static function configError(array $conf): string
    {
        $backend = (string)($conf['telemetry'] ?? 'off');
        if (!in_array($backend, ['langfuse', 'otlp'], true)) return 'disabled';
        $endpoint = trim((string)($conf['telemetry_endpoint'] ?? ''));
        if ($endpoint === '') return 'no_endpoint';
        $p = parse_url($endpoint);
        if ($p === false || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) {
            return 'invalid_endpoint';
        }
        if (isset($p['user']) || isset($p['pass'])) return 'credentials_in_url';
        if ($backend === 'langfuse' && (($conf['telemetry_langfuse_public'] ?? '') === '' || ($conf['telemetry_langfuse_secret'] ?? '') === '')) {
            return 'no_keys';
        }
        return '';
    }

    /** the exact URL the configured built-in backend POSTs to ('' if not usable) */
    public static function effectiveUrl(array $conf): string
    {
        $endpoint = trim((string)($conf['telemetry_endpoint'] ?? ''));
        switch ((string)($conf['telemetry'] ?? 'off')) {
            case 'langfuse':
                return $endpoint === '' ? '' : LangfuseExporter::ingestUrl($endpoint);
            case 'otlp':
                return $endpoint;
            default:
                return '';
        }
    }

    /** normalized list of enabled content capture kinds */
    public static function capturePolicy(array $conf): array
    {
        if (($conf['telemetry'] ?? 'off') === 'off') return [];
        $kinds = array_filter(array_map('trim', explode(',', (string)($conf['telemetry_capture'] ?? ''))));
        $kinds = array_values(array_unique(array_intersect($kinds, TraceRecorder::CONTENT_KINDS)));
        sort($kinds);
        return $kinds;
    }

    /**
     * Spool namespace = provenance of queued payloads: backend, endpoint, credential identity and
     * capture policy. Payloads are only ever flushed to EXACTLY the same combination, so changing the
     * destination, project keys, backend or capture policy never sends an old backlog anywhere.
     * Only a one-way hash is used; no URL or key is stored in clear text.
     */
    public static function spoolNamespace(array $conf): string
    {
        $backend = (string)($conf['telemetry'] ?? 'off');
        $endpoint = self::normalizeEndpoint(self::effectiveUrl($conf));
        $cred = $backend === 'langfuse'
            ? (string)($conf['telemetry_langfuse_public'] ?? '') . ':' . (string)($conf['telemetry_langfuse_secret'] ?? '')
            : (string)($conf['telemetry_otlp_authorization'] ?? '');
        $id = json_encode([
            'v' => 1,
            'backend' => $backend,
            'endpoint' => $endpoint,
            'credential' => hash('sha256', 'aichat-spool-cred|' . $cred),
            'capture' => self::capturePolicy($conf),
        ]);
        return substr(hash('sha256', 'aichat-spool|' . $id), 0, 32);
    }

    /**
     * Endpoint identity for spool namespacing, applied to the EXACT effective request URL: ONLY scheme
     * and host are lowercased. Port, path (incl. trailing slash), query and fragment are kept exactly.
     * The only other equivalence: an empty path equals "/" (the HTTP client requests "/" for both).
     */
    public static function normalizeEndpoint(string $url): string
    {
        $url = trim($url);
        $p = parse_url($url);
        if ($p === false || empty($p['host'])) return $url; // unparseable: exact string
        $out = strtolower($p['scheme'] ?? '') . '://';
        if (isset($p['user']) || isset($p['pass'])) $out .= 'userinfo-rejected@'; // never hashed or stored; configError() refuses
        $out .= strtolower($p['host']);
        if (isset($p['port'])) $out .= ':' . $p['port'];
        $path = $p['path'] ?? '';
        $out .= ($path === '' ? '/' : $path);
        if (isset($p['query'])) $out .= '?' . $p['query'];
        if (isset($p['fragment'])) $out .= '#' . $p['fragment'];
        return $out;
    }

    /**
     * Default transport for telemetry (credential-safe):
     *
     *  - Redirects are NEVER followed: the HTTP client would re-send persistent headers (Langfuse
     *    Basic secret, OTLP Authorization) and, for 307/308, the trace body to any Location host,
     *    including http:// downgrades. A 3xx is returned as-is and treated as a failure (fail closed).
     *  - Debug output is ALWAYS off: DokuHTTPClient enables request dumps (incl. headers and body) from
     *    ?httpdebug or a Referer when allowdebug is on. We use the base HTTPClient (that switch lives in
     *    the DokuHTTPClient constructor) and force debug = false.
     *  - The base client also does not trigger HTTPCLIENT_REQUEST_SEND, so other plugins never see
     *    the credential headers. DokuWiki's proxy settings are applied manually.
     *  - Only http(s) URLs are accepted.
     */
    public static function dokuHttp(): callable
    {
        return static function (string $url, array $headers, string $body, int $timeout): int|array {
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) return 0;
            $http = self::client($timeout);
            $http->headers = array_merge($http->headers, $headers);
            $http->sendRequest($url, $body, 'POST');
            return ['status' => (int)$http->status, 'body' => (string)$http->resp_body];
        };
    }

    /** a credential-safe HTTP client, see dokuHttp() */
    public static function client(int $timeout): \dokuwiki\HTTP\HTTPClient
    {
        global $conf;
        $http = new \dokuwiki\HTTP\HTTPClient();
        $proxy = $conf['proxy'] ?? [];
        $http->proxy_host = $proxy['host'] ?? '';
        $http->proxy_port = $proxy['port'] ?? '';
        $http->proxy_user = $proxy['user'] ?? '';
        $http->proxy_pass = isset($proxy['pass']) && function_exists('conf_decodeString') ? conf_decodeString($proxy['pass']) : '';
        $http->proxy_ssl = $proxy['ssl'] ?? false;
        $http->proxy_except = $proxy['except'] ?? '';
        $http->timeout = $timeout;
        $http->keep_alive = false;
        $http->max_redirect = 0;
        $http->debug = false;
        $http->max_bodysize = 65536;          // responses are small (OTLP ExportTraceServiceResponse)
        $http->max_bodysize_abort = true;
        return $http;
    }
}
