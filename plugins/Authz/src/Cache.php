<?php

namespace Playbloom\Plugin\Authz;

/**
 * APCu, which every worker in the PHP-FPM pool shares, so one verification
 * serves a whole build. Where APCu is not enabled, as in the CLI unless
 * apc.enable_cli is on, nothing is cached.
 */
final class Cache
{
    public static function fetch(string $key, ?bool &$found = null): mixed
    {
        $found = false;
        if (!self::enabled()) {
            return null;
        }
        $value = apcu_fetch($key, $success);
        $found = true === $success;

        return $found ? $value : null;
    }

    public static function store(string $key, mixed $value, int $ttl): void
    {
        if (self::enabled()) {
            apcu_store($key, $value, $ttl);
        }
    }

    public static function exists(string $key): bool
    {
        return self::enabled() && apcu_exists($key);
    }

    public static function delete(string $key): void
    {
        if (self::enabled()) {
            apcu_delete($key);
        }
    }

    public static function clear(): bool
    {
        return self::enabled() && apcu_clear_cache();
    }

    public static function enabled(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }
}
