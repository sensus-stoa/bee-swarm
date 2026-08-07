<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * ЭКСП-018b: микро-профиль Search::find.
 * Аккумулирует время секций; вывод при shutdown (SEARCH_PROFILE=1).
 */
final class SearchProfiler
{
    private static float $gen = 0.0;

    private static float $cv = 0.0;

    private static float $test = 0.0;

    private static int $calls = 0;

    public static function add(float $gen, float $cv, float $test): void
    {
        self::$gen += $gen;
        self::$cv += $cv;
        self::$test += $test;
        self::$calls++;
        // Периодический вывод: SIGTERM (timeout) не выполняет shutdown-функции
        if (getenv('SEARCH_PROFILE') === '1' && self::$calls % 100 === 0) {
            self::dump();
        }
    }

    private static function dump(): void
    {
        $total = self::$gen + self::$cv + self::$test;
        $total = $total > 0 ? $total : 1.0;
        fwrite(STDOUT, sprintf(
            "SEARCH_PROFILE: calls=%d GEN=%.1f%% CV=%.1f%% TEST=%.1f%% total=%.1fs\n",
            self::$calls,
            100 * self::$gen / $total,
            100 * self::$cv / $total,
            100 * self::$test / $total,
            $total
        ));
    }

    public static function registerShutdown(): void
    {
        register_shutdown_function(static function (): void {
            if (getenv('SEARCH_PROFILE') !== '1' || self::$calls === 0) {
                return;
            }
            self::dump();
        });
    }
}
