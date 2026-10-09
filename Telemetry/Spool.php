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
}
