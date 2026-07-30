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
    private function createHive(): array
    {
        $logFile = tempnam(sys_get_temp_dir(), 'bs_');
        $hive = new Hive(maxTicks: 1, logFile: $logFile);
        $hive->run();
        return [$hive, $logFile];
    }

    /**
     * §0.6: При первом запуске (популяция пуста) система создаёт
     * 3 seed-пчелы с попарно разными грамматиками (Жаккар < 1.0).
     */
    public function testBootstrapCreatesThreeSeedBees(): void
    {
        [$hive, $logFile] = $this->createHive();
        $bees = $hive->getBees();
        $this->assertCount(3, $bees, 'Bootstrap must create 3 seed bees');
        unlink($logFile);
    }

    /**
     * §0.6: Грамматики seed-пчёл попарно различны.
     */
    public function testSeedBeesHavePairwiseDifferentGrammars(): void
    {
        [$hive, $logFile] = $this->createHive();
        $bees = $hive->getBees();
        $this->assertCount(3, $bees);

        $grammars = array_map(fn (Bee $b) => $b->grammar(), $bees);
        $this->assertNotEquals($grammars[0], $grammars[1], 'G₁ ≠ G₂');
        $this->assertNotEquals($grammars[1], $grammars[2], 'G₂ ≠ G₃');
        $this->assertNotEquals($grammars[0], $grammars[2], 'G₁ ≠ G₃');
        unlink($logFile);
    }

    /**
     * §0.6: Seed-пчёлы имеют E₀ = 10.0.
     */
    public function testSeedBeesHaveInitialEnergy(): void
    {
        [$hive, $logFile] = $this->createHive();
        $bees = $hive->getBees();
        foreach ($bees as $bee) {
            $this->assertEqualsWithDelta(10.0, $bee->energy(), 0.001);
        }
        unlink($logFile);
    }

    /**
     * §0.6-бис: Система логирует BOOTSTRAP и DATA_BOOTSTRAP_*.
     */
    public function testBootstrapLogsDataBootstrapTags(): void
    {
        [$hive, $logFile] = $this->createHive();
        $log = file_get_contents($logFile);
        $this->assertStringContainsString('BOOTSTRAP', $log, 'Must log BOOTSTRAP');
        $this->assertStringContainsString('DATA_BOOTSTRAP_CORPUS', $log);
        $this->assertStringContainsString('DATA_BOOTSTRAP_GRAMMAR', $log);
        unlink($logFile);
    }
}
