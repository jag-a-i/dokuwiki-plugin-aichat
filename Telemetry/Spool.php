<?php

namespace dokuwiki\plugin\aichat\Telemetry;

/**
 * Bounded on-disk queue for traces that could not be delivered.
 * Limits: max number of files, max age; oldest entries are dropped first.
 * Stores exactly the payload that would have been sent (metadata only unless capture is enabled).
 */
class Spool
{
    protected string $dir;
    protected int $maxItems;
    protected int $maxAge;
    /** @var callable(): int */
    protected $clock;

    public function __construct(string $dir, int $maxItems = 100, int $maxAgeSeconds = 259200, ?callable $clock = null)
    {
        $this->dir = rtrim($dir, '/');
        $this->maxItems = max(0, $maxItems);
        $this->maxAge = max(60, $maxAgeSeconds);
        $this->clock = $clock ?: static fn() => time();
    }

    public function push(string $payload): bool
    {
        if ($this->maxItems === 0) return false;
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0770, true)) return false;
        $this->purge();
        $files = $this->files();
        while (count($files) >= $this->maxItems) {
            @unlink(array_shift($files));
        }
        $name = sprintf('%s/%013d-%s.json', $this->dir, ($this->clock)() * 1000, bin2hex(random_bytes(4)));
        return @file_put_contents($name, $payload, LOCK_EX) !== false;
    }

    /** @return string[] up to $limit oldest file paths */
    public function oldest(int $limit): array
    {
        $this->purge();
        return array_slice($this->files(), 0, $limit);
    }

    public function read(string $file): ?string
    {
        $data = @file_get_contents($file);
        return $data === false ? null : $data;
    }

    public function remove(string $file): void
    {
        @unlink($file);
    }

    public function count(): int
    {
        return count($this->files());
    }

    /** drop entries older than the maximum age */
    public function purge(): int
    {
        $n = 0;
        $limit = (($this->clock)() - $this->maxAge) * 1000;
        foreach ($this->files() as $f) {
            if ((int)basename($f) < $limit) {
                @unlink($f);
                $n++;
            }
        }
        return $n;
    }

    protected function files(): array
    {
        $files = glob($this->dir . '/*.json') ?: [];
        sort($files);
        return $files;
    }

    /**
     * Bounded cleanup of spool namespaces other than the current one (and legacy flat files without
     * provenance). Their payloads are NEVER sent; entries older than $maxAgeSeconds are deleted, empty
     * namespaces removed, and at most $maxNamespaces other namespaces are kept (oldest removed first).
     *
     * @return int number of deleted payload files
     */
    public static function cleanupObsolete(string $root, string $current, int $maxAgeSeconds, int $maxNamespaces = 5,
                                           ?callable $clock = null): int
    {
        $now = $clock ? $clock() : time();
        $limit = ($now - max(60, $maxAgeSeconds)) * 1000;
        $deleted = 0;
        $root = rtrim($root, '/');
        // legacy files from versions without namespaces: no provenance -> never sent, deleted by age
        foreach (glob($root . '/*.json') ?: [] as $f) {
            if ((int)basename($f) < $limit) { @unlink($f); $deleted++; }
        }
        $others = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) === $current || !preg_match('/^[0-9a-f]{32}$/', basename($dir))) continue;
            $files = glob($dir . '/*.json') ?: [];
            foreach ($files as $f) {
                if ((int)basename($f) < $limit) { @unlink($f); $deleted++; }
            }
            $left = glob($dir . '/*.json') ?: [];
            if (!$left) { @rmdir($dir); continue; }
            sort($left);
            $others[$dir] = (int)basename(end($left)); // newest entry
        }
        arsort($others);
        foreach (array_slice(array_keys($others), max(0, $maxNamespaces)) as $dir) {
            foreach (glob($dir . '/*.json') ?: [] as $f) { @unlink($f); $deleted++; }
            @rmdir($dir);
        }
        return $deleted;
    }
}
