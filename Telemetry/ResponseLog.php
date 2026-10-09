<?php

namespace dokuwiki\plugin\aichat\Telemetry;

/**
 * Local, metadata-only record per chat response, used for feedback binding and the admin summary.
 *
 * Storage: one small JSON file per response in {metadir}/aichat/responses/ (not web-accessible
 * in a standard DokuWiki setup). Only allow-listed fields are written: never questions, answers,
 * retrieved text, page ids, IP addresses, usernames or secrets. The owner is a keyed hash of the
 * user (or guest session) so feedback can be bound to the person who received the answer.
 * Finite retention: files older than $retentionDays are deleted by cleanup().
 */
class ResponseLog
{
    public const VOTES = ['helpful', 'not_helpful'];
    public const CATEGORIES = ['wrong_answer', 'missing_information', 'wrong_source', 'unclear'];

    /** fields allowed in a record (all metadata) */
    protected const FIELDS = [
        'id', 'ts', 'owner', 'outcome', 'model', 'conf_rev', 'chunks', 'sources', 'options',
        'timings', 'error_category', 'redacted', 'clarify_round', 'trace_id', 'export',
    ];
    /** fields kept when diagnostics are disabled but feedback is enabled */
    protected const MINIMAL = ['id', 'ts', 'owner', 'outcome'];

    protected string $dir;
    protected int $retentionDays;
    protected bool $diagnostics;
    /** @var callable(): int */
    protected $clock;

    public function __construct(string $dir, int $retentionDays = 30, bool $diagnostics = true, ?callable $clock = null)
    {
        $this->dir = rtrim($dir, '/');
        $this->retentionDays = max(1, min(3650, $retentionDays));
        $this->diagnostics = $diagnostics;
        $this->clock = $clock ?: static fn() => time();
    }

    public static function isValidId(string $id): bool
    {
        return (bool)preg_match('/^[0-9a-f]{24}$/', $id);
    }

    protected function file(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }

    /** write a new record; returns false (never throws) on any storage problem */
    public function record(array $data): bool
    {
        try {
            if (!self::isValidId((string)($data['id'] ?? ''))) return false;
            $keep = array_flip($this->diagnostics ? self::FIELDS : self::MINIMAL);
            $rec = array_intersect_key($data, $keep);
            $rec['ts'] = (int)($rec['ts'] ?? ($this->clock)());
            if (!is_dir($this->dir) && !@mkdir($this->dir, 0770, true)) return false;
            $ok = @file_put_contents($this->file($rec['id']), json_encode($rec), LOCK_EX) !== false;
            $this->maybeCleanup();
            return $ok;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function get(string $id): ?array
    {
        if (!self::isValidId($id)) return null;
        $raw = @file_get_contents($this->file($id));
        if ($raw === false) return null;
        $rec = json_decode($raw, true);
        if (!is_array($rec)) return null;
        if (($this->clock)() - (int)$rec['ts'] > $this->retentionDays * 86400) return null;
        return $rec;
    }

    /**
     * Set or change the vote for a response (one vote per response: changes overwrite).
     *
     * @return string ok | not_found | not_allowed | invalid | error
     *         (a response of another owner is reported as not_found, so ids cannot be probed)
     */
    public function setFeedback(string $id, string $owner, string $vote, string $category = ''): string
    {
        if (!self::isValidId($id) || $owner === '') return 'invalid';
        if (!in_array($vote, self::VOTES, true)) return 'invalid';
        if ($category !== '' && ($vote !== 'not_helpful' || !in_array($category, self::CATEGORIES, true))) return 'invalid';
        $file = $this->file($id);
        $fh = @fopen($file, 'c+');
        if (!$fh) return 'not_found';
        try {
            if (!flock($fh, LOCK_EX)) return 'error';
            $raw = stream_get_contents($fh);
            $rec = json_decode((string)$raw, true);
            if (!is_array($rec) || !hash_equals((string)($rec['owner'] ?? ''), $owner)) {
                if ($raw === '') { ftruncate($fh, 0); }
                return 'not_found';
            }
            if (($this->clock)() - (int)$rec['ts'] > $this->retentionDays * 86400) return 'not_found';
            if (($rec['outcome'] ?? '') !== 'ANSWER') return 'not_allowed';
            $rec['feedback'] = ['vote' => $vote, 'category' => $category, 'ts' => ($this->clock)()];
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($rec));
            fflush($fh);
            return 'ok';
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
            clearstatcache();
            if (@filesize($file) === 0) @unlink($file); // fopen 'c+' created an empty file for an unknown id
        }
    }

    /** @return array[] records newer than $since (unix time) */
    public function since(int $since): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            $rec = json_decode((string)@file_get_contents($f), true);
            if (is_array($rec) && (int)($rec['ts'] ?? 0) >= $since) $out[] = $rec;
        }
        return $out;
    }

    /** delete records older than the retention period */
    public function cleanup(): int
    {
        $limit = ($this->clock)() - $this->retentionDays * 86400;
        $n = 0;
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            $rec = json_decode((string)@file_get_contents($f), true);
            $ts = is_array($rec) ? (int)($rec['ts'] ?? 0) : 0;
            if ($ts < $limit) {
                @unlink($f);
                $n++;
            }
        }
        @touch($this->dir . '/.cleanup');
        return $n;
    }

    /** run cleanup at most once per day */
    protected function maybeCleanup(): void
    {
        $marker = $this->dir . '/.cleanup';
        $last = @filemtime($marker) ?: 0;
        if (($this->clock)() - $last > 86400) $this->cleanup();
    }
}
