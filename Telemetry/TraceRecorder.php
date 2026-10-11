<?php

namespace dokuwiki\plugin\aichat\Telemetry;

/**
 * Collects correlated spans for one chat turn. Metadata only: span attributes must be
 * counts, categories, ids or model names. Content is only kept via content() when the
 * administrator explicitly enabled capture for that kind of content.
 */
class TraceRecorder
{
    public const CONTENT_KINDS = ['question', 'answer', 'context'];
    public const MAX_SPANS = 64;
    public const MAX_CONTENT = 8000;

    protected string $traceId;
    protected string $rootId;
    protected int $startNs;     // wall clock (unix epoch ns) at start
    protected int $startHr;     // monotonic at start
    /** @var array[] */
    protected array $spans = [];
    protected array $rootAttrs = [];
    protected ?int $endNs = null;
    protected string $status = 'ok';
    /** @var string[] content kinds allowed to be captured */
    protected array $capture;
    protected array $content = [];
    /** @var callable(): int monotonic ns */
    protected $hr;

    public function __construct(array $capture = [], ?callable $hrClock = null, ?int $startEpochNs = null)
    {
        $this->traceId = bin2hex(random_bytes(16));
        $this->rootId = bin2hex(random_bytes(8));
        $this->hr = $hrClock ?: static fn() => hrtime(true);
        $this->startHr = ($this->hr)();
        $this->startNs = $startEpochNs ?? (int)round(microtime(true) * 1e9);
        $this->capture = array_values(array_intersect($capture, self::CONTENT_KINDS));
    }

    protected function now(): int
    {
        return $this->startNs + (($this->hr)() - $this->startHr);
    }

    /** @return int span handle */
    public function start(string $name, array $attrs = []): int
    {
        if (count($this->spans) >= self::MAX_SPANS) return -1;
        $this->spans[] = [
            'id' => bin2hex(random_bytes(8)),
            'name' => $name,
            'start' => $this->now(),
            'end' => null,
            'attrs' => self::scalars($attrs),
            'status' => 'ok',
        ];
        return count($this->spans) - 1;
    }

    public function end(int $span, array $attrs = []): void
    {
        if (!isset($this->spans[$span])) return;
        $this->spans[$span]['end'] = $this->now();
        $this->spans[$span]['attrs'] = array_merge($this->spans[$span]['attrs'], self::scalars($attrs));
    }

    /** record a sanitized error category (never a message) */
    public function error(string $category): void
    {
        $i = $this->start('error', ['error.category' => $category]);
        if ($i >= 0) {
            $this->spans[$i]['status'] = 'error';
            $this->end($i);
        }
        $this->status = 'error';
    }

    /** keep content only if the administrator enabled capture for this kind */
    public function content(string $kind, string $text): void
    {
        if (!in_array($kind, $this->capture, true)) return;
        $this->content[$kind] = mb_substr($text, 0, self::MAX_CONTENT);
    }

    public function captures(string $kind): bool
    {
        return in_array($kind, $this->capture, true);
    }

    public function finish(array $rootAttrs = []): void
    {
        foreach ($this->spans as &$s) {
            if ($s['end'] === null) $s['end'] = $this->now();
        }
        unset($s);
        $this->rootAttrs = array_merge($this->rootAttrs, self::scalars($rootAttrs));
        $this->endNs = $this->now();
    }

    /** total duration per span name in milliseconds (for local diagnostics) */
    public function timingsMs(): array
    {
        $out = [];
        foreach ($this->spans as $s) {
            if ($s['end'] === null) continue;
            $out[$s['name']] = round(($out[$s['name']] ?? 0) + ($s['end'] - $s['start']) / 1e6, 1);
        }
        $out['total'] = round((($this->endNs ?? $this->now()) - $this->startNs) / 1e6, 1);
        return $out;
    }

    /** immutable export view */
    public function toArray(): array
    {
        return [
            'traceId' => $this->traceId,
            'rootId' => $this->rootId,
            'start' => $this->startNs,
            'end' => $this->endNs ?? $this->now(),
            'status' => $this->status,
            'rootAttrs' => $this->rootAttrs,
            'spans' => $this->spans,
            'content' => $this->content,
        ];
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    /** only scalar attribute values survive; strings are length-limited */
    protected static function scalars(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $k => $v) {
            if (is_bool($v) || is_int($v) || is_float($v)) {
                $out[(string)$k] = $v;
            } elseif (is_string($v)) {
                $out[(string)$k] = mb_substr($v, 0, 200);
            }
        }
        return $out;
    }
}
