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
        $endpoint = trim((string)($conf['telemetry_endpoint'] ?? ''));
        if (isset(self::$factories[$backend])) {
            return (self::$factories[$backend])($conf, $http, $spool);
        }
        switch ($backend) {
            case 'langfuse':
                $pk = (string)($conf['telemetry_langfuse_public'] ?? '');
                $sk = (string)($conf['telemetry_langfuse_secret'] ?? '');
                if ($endpoint === '' || $pk === '' || $sk === '') return null;
                return LangfuseExporter::create($endpoint, $pk, $sk, $http, $timeout, $retries, $spool);
            case 'otlp':
                if ($endpoint === '') return null;
                $headers = [];
                $auth = (string)($conf['telemetry_otlp_authorization'] ?? '');
                if ($auth !== '') $headers['Authorization'] = $auth;
                return new OtlpHttpExporter($endpoint, $headers, $http, $timeout, $retries, $spool);
            default:
                return null;
        }
    }

    /**
     * Default transport: DokuWiki's HTTP client with a hard timeout.
     *
     * Redirects are NEVER followed: DokuWiki's client would re-send persistent headers (the
     * Langfuse Basic secret, OTLP Authorization) and, for 307/308, the trace body to any host the
     * Location header names, including http:// downgrades. A 3xx is returned as-is and treated as
     * a non-retryable failure by the exporter (fail closed). Only http(s) URLs are accepted.
     */
    public static function dokuHttp(): callable
    {
        return static function (string $url, array $headers, string $body, int $timeout): int {
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) return 0;
            $http = new \dokuwiki\HTTP\DokuHTTPClient();
            $http->timeout = $timeout;
            $http->keep_alive = false;
            $http->max_redirect = 0;
            $http->headers = array_merge($http->headers, $headers);
            $http->sendRequest($url, $body, 'POST');
            return (int)$http->status;
        };
    }
}
