<?php

namespace DNDark\LogicMap\Support;

final class MemoryLimit
{
    /** Reserve released on OOM so the shutdown handler can still emit output. */
    private static ?string $oomReserve = null;

    /**
     * Raise memory_limit to at least $required. Never lowers an existing limit;
     * null/'' leaves the limit untouched, '-1' removes it entirely.
     */
    public static function ensureAtLeast(?string $required): void
    {
        if ($required === null || $required === '') {
            return;
        }

        $current = (string) ini_get('memory_limit');

        if ($current === '-1') {
            return;
        }

        if ($required === '-1' || self::toBytes($required) > self::toBytes($current)) {
            @ini_set('memory_limit', $required);
        }
    }

    /**
     * Make out-of-memory fatal errors loud: without this, an OOM while the
     * framework formats the error itself needs memory, so the process dies a
     * second time and exits 255 with zero output.
     */
    public static function registerOomGuard(string $commandName): void
    {
        if (self::$oomReserve !== null) {
            return;
        }

        self::$oomReserve = str_repeat("\0", 65536);

        register_shutdown_function(static function () use ($commandName): void {
            $error = error_get_last();

            if ($error === null || ! str_contains($error['message'], 'Allowed memory size')) {
                return;
            }

            self::$oomReserve = null;

            fwrite(STDERR, sprintf(
                "\n%s exhausted PHP memory_limit (%s).\n".
                "Re-run with a higher limit, e.g.:\n".
                "  php -d memory_limit=2G artisan %s\n".
                "or raise logic-map.indexing.memory_limit in config/logic-map.php.\n",
                $commandName,
                ini_get('memory_limit'),
                $commandName,
            ));
        });
    }

    /** Convert a php.ini shorthand value (e.g. "128M", "2G") to bytes. -1 => PHP_INT_MAX. */
    public static function toBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
