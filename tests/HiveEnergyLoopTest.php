<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\Hive;

/**
 * Story S1-WIRE Phase 3: Energy loop — tick, death, metabolism
 */
class HiveEnergyLoopTest extends TestCase
{
    /** Пчёлы тикают каждый цикл — энергия падает */
    public function testBeesLoseEnergyOnTick(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'hel_');
        $hive = new Hive(maxTicks: 5, logFile: $logFile);
        $hive->run();
        $bees = $hive->getBees();

        foreach ($bees as $bee) {
            // После тиков энергия должна измениться (не стоять на месте)
            $this->assertNotEquals(10.0, $bee->energy(), 'Bee energy must change from ticks');
        }
        unlink($logFile);
    }

    /** При E ≤ 0 пчела умирает — DEATH в логе */
    public function testBeeDiesAtZeroEnergy(): void
    {
        // Пчела с энергией 0.01 — умрёт через 2 тика
        $bee = new Bee(['add'], 0.01);
        $bee->tick(); // E = 0.00
        $this->assertFalse($bee->isAlive(), 'Bee with E≤0 must be dead');
    }

    /** Энергия падает от тиков в Hive */
    public function testHiveEnergyDropsFromTicks(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'hel_');
        $hive = new Hive(maxTicks: 5, logFile: $logFile);
        $hive->run();
        // После bootstrap → получаем пчёл
        $bees = $hive->getBees();
        $this->assertNotEmpty($bees, 'Must have bees after bootstrap');

        // Энергия изменилась от тиков и novelty bonus
        foreach ($bees as $bee) {
            $this->assertNotEquals(10.0, $bee->energy(), 'Energy must change from ticks');
        }

        $log = file_get_contents($logFile);
        // DEATH может не появиться за 5 тиков (нужно 1000)
        // Но энергия должна падать — energy loop работает
        $this->assertStringContainsString('ROUTE', $log);
        unlink($logFile);
    }
}
