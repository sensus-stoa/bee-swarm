<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;

/**
 * S1.9-FIX: run(maxTicks=0) = bootstrap только, без тика.
 *
 * Флак BootstrapTest: static $seenFingerprints + array_rand дают
 * novelty bonus (+0.5) в первом тике → энергия 10.49 ≠ E₀ 10.0.
 * maxTicks=0 позволяет проверить E₀ ДО тика, детерминированно.
 */
class HiveZeroTicksTest extends TestCase
{
    /**
     * run(maxTicks=0) создаёт seed-пчёл, но НЕ выполняет doTick.
     */
    public function testRunZeroTicksBootstrapsWithoutTicking(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'zt_');
        $hive = new Hive(maxTicks: 0, logFile: $logFile);
        $ticks = $hive->run();

        $this->assertSame(0, $ticks, 'run(maxTicks=0) must return 0 ticks');
        $this->assertNotEmpty($hive->getBees(), 'Bootstrap must create seed bees');
        unlink($logFile);
    }

    /**
     * После run(maxTicks=0) энергия seed-пчёл = E₀ ровно (никаких тиков,
     * никакого novelty). Детерминированная проверка §0.6.
     */
    public function testRunZeroTicksLeavesEnergyAtE0(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'zt_');
        $hive = new Hive(maxTicks: 0, logFile: $logFile);
        $hive->run();

        foreach ($hive->getBees() as $bee) {
            $this->assertSame(10.0, $bee->energy(), 'E₀ must be exactly 10.0 after zero ticks');
        }
        unlink($logFile);
    }

    /**
     * Лог не содержит ROUTE/NOVELTY — тик не выполнялся.
     */
    public function testRunZeroTicksLogsNoTickActivity(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'zt_');
        $hive = new Hive(maxTicks: 0, logFile: $logFile);
        $hive->run();

        $log = file_get_contents($logFile);
        $this->assertStringContainsString('BOOTSTRAP', $log);
        $this->assertStringNotContainsString('ROUTE:', $log);
        $this->assertStringNotContainsString('NOVELTY:', $log);
        unlink($logFile);
    }
}
