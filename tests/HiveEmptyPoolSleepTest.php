<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * TEST-HYGIENE (fast2min, 17.10): пустой пул — usleep(1_000_000) на тик —
 * периодические tick-тесты (Monoculture 105 тиков, Population-персист и т.п.)
 * платили N секунд чистого сна. Контракт: EMPTY_POOL_SLEEP_US гейтит длительность
 * (дефолт 1_000_000 = прежнее поведение прода; '0' — валидный ноль, PHP-falsy
 * гвард по канону).
 */
final class HiveEmptyPoolSleepTest extends TestCase
{
    protected function setUp(): void
    {
        Database::reset();
        Database::get();
    }

    protected function tearDown(): void
    {
        putenv('EMPTY_POOL_SLEEP_US');
        Database::setPath(':memory:');
        Database::reset();
    }

    public function testDefaultSleepIsOneSecond(): void
    {
        putenv('EMPTY_POOL_SLEEP_US');
        $hive = new Hive(maxTicks: 0, logFile: tempnam(sys_get_temp_dir(), 'eps_'));

        $p = new \ReflectionProperty(Hive::class, 'emptyPoolSleepUs');
        $p->setAccessible(true);

        self::assertSame(1_000_000, $p->getValue($hive), 'без env — прежнее поведение прода');
    }

    public function testEnvZeroDisablesSleep(): void
    {
        putenv('EMPTY_POOL_SLEEP_US=0');
        try {
            $hive = new Hive(maxTicks: 0, logFile: tempnam(sys_get_temp_dir(), 'eps_'));

            $p = new \ReflectionProperty(Hive::class, 'emptyPoolSleepUs');
            $p->setAccessible(true);

            self::assertSame(0, $p->getValue($hive), "env '0' — валидный ноль (PHP-falsy гвард)");
        } finally {
            putenv('EMPTY_POOL_SLEEP_US');
        }
    }

    /**
     * Agent-review M1 (18.10): мусор/пусто/подчёркивания → ДЕФОЛТ, не 0.
     * '(int)"1_000_000"' = 1 (1µs = busy-spin), '(int)""' = 0 — молчаливый
     * спин из опечатки в .env. Контракт: только непустая числовая строка
     * задаёт значение; всё прочее = дефолт 1_000_000.
     */
    public function testGarbageEnvFallsBackToDefault(): void
    {
        $p = new \ReflectionProperty(Hive::class, 'emptyPoolSleepUs');
        $p->setAccessible(true);

        foreach (['', 'abc', '1_000_000', '-5', '12 000'] as $garbage) {
            putenv('EMPTY_POOL_SLEEP_US=' . $garbage);
            try {
                $hive = new Hive(maxTicks: 0, logFile: tempnam(sys_get_temp_dir(), 'eps_'));
                self::assertSame(
                    1_000_000,
                    $p->getValue($hive),
                    "мусорный env '" . $garbage . "' → дефолт, не busy-spin"
                );
            } finally {
                putenv('EMPTY_POOL_SLEEP_US');
            }
        }
    }
}
