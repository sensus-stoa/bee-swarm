<?php

declare(strict_types=1);

namespace BeeSwarm\Infra;

/**
 * GlobalStateGuard — ловит утечки глобального состояния PHP.
 *
 * D19.1. Отслеживает: ini_set, error_reporting, date_default_timezone_set.
 * Для RNG: использует RngIsolation::hasUnrestoredGuards().
 */
class GlobalStateGuard
{
    private static ?string $savedMemoryLimit = null;
    private static ?int $savedErrorReporting = null;
    private static ?string $savedTimezone = null;

    public static function snapshot(): void
    {
        self::$savedMemoryLimit = ini_get('memory_limit') ?: null;
        self::$savedErrorReporting = error_reporting();
        self::$savedTimezone = date_default_timezone_get();
    }

    /**
     * @throws \RuntimeException
     */
    public static function assertClean(): void
    {
        $dirty = [];

        if (ini_get('memory_limit') !== self::$savedMemoryLimit) {
            $dirty[] = 'ini_set(memory_limit)';
        }
        if (error_reporting() !== self::$savedErrorReporting) {
            $dirty[] = 'error_reporting()';
        }
        if (date_default_timezone_get() !== self::$savedTimezone) {
            $dirty[] = 'date_default_timezone_set()';
        }
        // RNG check delegated to RngIsolation
        if (method_exists(RngIsolation::class, 'hasUnrestoredGuards')
            && RngIsolation::hasUnrestoredGuards()) {
            $dirty[] = 'srand() without restore (RNG)';
        }

        if (! empty($dirty)) {
            throw new \RuntimeException(
                'Global state leak: ' . implode('; ', $dirty)
            );
        }
    }
}
