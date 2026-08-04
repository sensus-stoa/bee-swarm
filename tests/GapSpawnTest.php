<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\SpawnManager;

/**
 * Story S1.2 Phase 4: Gap-Triggered Spawn — unit-тесты механики.
 *
 * История: интеграционный вариант (полный Hive, 25 тиков) стал флаки
 * после srand-фиксов — улей открывает законы почти каждый тик, плато
 * не набирает 20 тиков подряд, GAP_SPAWN не успевает сработать.
 * Механика тестируется напрямую: детерминированно, без реальных данных.
 */
class GapSpawnTest extends TestCase
{
    /**
     * @return Bee[]
     */
    private function liveBees(): array
    {
        return [new Bee(['+'], 10.0), new Bee(['×'], 8.0), new Bee(['−'], 5.0)];
    }

    /**
     * Плато 20+ тиков без новых данных → fallback spawn (10×P, P=2).
     */
    public function testGapSpawnFiresOnFallbackThreshold(): void
    {
        $sm = new SpawnManager();
        $bees = $this->liveBees();

        $spawned = $sm->tryGapSpawn($bees, ['+', '×'], true, 20, false, 2);

        $this->assertSame(1, $spawned, 'Fallback threshold (10×P) must trigger gap-spawn');
        $this->assertCount(4, $bees, 'Child bee must be added to population');
    }

    /**
     * Плато 10+ тиков + новые данные → spawn по new-data порогу (5×P).
     */
    public function testGapSpawnFiresOnNewDataThreshold(): void
    {
        $sm = new SpawnManager();
        $bees = $this->liveBees();

        $spawned = $sm->tryGapSpawn($bees, ['+', '×'], true, 10, true, 2);

        $this->assertSame(1, $spawned, 'New-data threshold (5×P) must trigger gap-spawn');
    }

    /**
     * Ниже порогов — никакого спавна.
     */
    public function testNoGapSpawnBelowThreshold(): void
    {
        $sm = new SpawnManager();
        $bees = $this->liveBees();

        $this->assertSame(0, $sm->tryGapSpawn($bees, ['+'], true, 9, true, 2));
        $this->assertSame(0, $sm->tryGapSpawn($bees, ['+'], true, 19, false, 2));
        $this->assertCount(3, $bees, 'No spawn below thresholds');
    }

    /**
     * Нет плато → нет gap-spawn, даже при большом счётчике.
     */
    public function testNoGapSpawnWithoutPlateau(): void
    {
        $sm = new SpawnManager();
        $bees = $this->liveBees();

        $this->assertSame(0, $sm->tryGapSpawn($bees, ['+'], false, 999, true, 2));
    }

    /**
     * Все пчёлы мертвы → нет родителя → нет gap-spawn.
     */
    public function testNoGapSpawnWhenAllDead(): void
    {
        $sm = new SpawnManager();
        $bees = [new Bee(['+'], 0.0)];

        $this->assertSame(0, $sm->tryGapSpawn($bees, ['+'], true, 999, true, 2));
    }

    /**
     * Cooldown: один gap-spawn за плато-период.
     */
    public function testGapSpawnCooldownOncePerPlateau(): void
    {
        $sm = new SpawnManager();
        $bees = $this->liveBees();

        $this->assertSame(1, $sm->tryGapSpawn($bees, ['+'], true, 20, false, 2));
        $this->assertSame(0, $sm->tryGapSpawn($bees, ['+'], true, 999, false, 2),
            'Cooldown: only one gap-spawn per plateau period');
    }
}
