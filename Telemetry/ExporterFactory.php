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

    /** default transport: DokuWiki's HTTP client with a hard timeout */
    public static function dokuHttp(): callable
    {
        return static function (string $url, array $headers, string $body, int $timeout): int {
            $http = new \dokuwiki\HTTP\DokuHTTPClient();
            $http->timeout = $timeout;
            $http->keep_alive = false;
            $http->headers = array_merge($http->headers, $headers);
            $http->sendRequest($url, $body, 'POST');
            return (int)$http->status;
        };
    }
}
