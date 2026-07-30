<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * Story S0-BOOTSTRAP: Bootstrap Phase (§0.6 + §0.6-бис)
 */
class BootstrapTest extends TestCase
{
    /**
     * §0.6: При первом запуске (популяция пуста) система создаёт
     * 3 seed-пчелы с попарно разными грамматиками (Жаккар < 1.0).
     */
    public function testBootstrapCreatesThreeSeedBees(): void
    {
        $hive = new Hive(maxTicks: 1);
        $hive->run();
        $bees = $hive->getBees();
        $this->assertCount(3, $bees, 'Bootstrap must create 3 seed bees');
    }

    /**
     * §0.6: Грамматики seed-пчёл попарно различны.
     */
    public function testSeedBeesHavePairwiseDifferentGrammars(): void
    {
        $hive = new Hive(maxTicks: 1);
        $hive->run();
        $bees = $hive->getBees();
        $this->assertCount(3, $bees);

        $grammars = array_map(fn (Bee $b) => $b->grammar(), $bees);
        $this->assertNotEquals($grammars[0], $grammars[1], 'G₁ ≠ G₂');
        $this->assertNotEquals($grammars[1], $grammars[2], 'G₂ ≠ G₃');
        $this->assertNotEquals($grammars[0], $grammars[2], 'G₁ ≠ G₃');
    }

    /**
     * §0.6: Seed-пчёлы имеют E₀ = 10.0.
     */
    public function testSeedBeesHaveInitialEnergy(): void
    {
        $hive = new Hive(maxTicks: 1);
        $hive->run();
        $bees = $hive->getBees();
        foreach ($bees as $bee) {
            $this->assertEqualsWithDelta(10.0, $bee->energy(), 0.001);
        }
    }

    /**
     * §0.6-бис: Система логирует DATA_BOOTSTRAP_CORPUS и DATA_BOOTSTRAP_GRAMMAR.
     */
    public function testBootstrapLogsDataBootstrapTags(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'bs_log_');
        $hive = new Hive(maxTicks: 1, logFile: $logFile);
        $hive->run();
        $log = file_get_contents($logFile);
        $this->assertStringContainsString('BOOTSTRAP', $log, 'Must log BOOTSTRAP');
        $this->assertStringContainsString('DATA_BOOTSTRAP_CORPUS', $log);
        $this->assertStringContainsString('DATA_BOOTSTRAP_GRAMMAR', $log);
        unlink($logFile);
    }
}
