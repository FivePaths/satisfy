<?php

namespace Playbloom\Plugin\Authz;

/**
 * The append-only audit log, one JSON object per line.
 */
final class Audit
{
    public function __construct(private readonly AuthzConfig $config)
    {
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function write(array $entry): void
    {
        $entry += ['ts' => time()];
        // LOCK_EX: concurrent builds append at once, and unlocked appends
        // interleave into unparseable half-lines.
        @file_put_contents(
            $this->config->getAuditPath(),
            json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Each site's authenticated requests (count and last time), and how many
     * requests came without a valid token, canary probes excluded.
     *
     * @return array{observed: array<string, array{count: int, last: int}>, unauth: int}
     */
    public function summary(): array
    {
        $observed = [];
        $unauth = 0;
        $handle = is_readable($this->config->getAuditPath()) ? fopen($this->config->getAuditPath(), 'r') : false;
        if (false === $handle) {
            return ['observed' => $observed, 'unauth' => $unauth];
        }
        while (false !== $line = fgets($handle)) {
            $entry = json_decode($line, true);
            if (!is_array($entry) || !array_key_exists('ok', $entry)) {
                continue;
            }
            if (true === $entry['ok']) {
                $site = (string) $entry['site'];
                $observed[$site]['count'] = ($observed[$site]['count'] ?? 0) + 1;
                $observed[$site]['last'] = max($observed[$site]['last'] ?? 0, (int) ($entry['ts'] ?? 0));
            } elseif (empty($entry['canary'])) {
                ++$unauth;
            }
        }
        fclose($handle);

        return ['observed' => $observed, 'unauth' => $unauth];
    }

    /**
     * @return string[] the last $count lines
     */
    public function tail(int $count): array
    {
        if (!is_readable($this->config->getAuditPath())) {
            return [];
        }
        $lines = file($this->config->getAuditPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_slice($lines, -$count);
    }
}
