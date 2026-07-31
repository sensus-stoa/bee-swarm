<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\Hive;

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
     * 2 seed-пчелы с попарно разными грамматиками (Жаккар < 1.0).
     */
    public function testBootstrapCreatesThreeSeedBees(): void
    {
        [$hive, $logFile] = $this->createHive();
        $bees = $hive->getBees();
        $this->assertCount(2, $bees, 'Bootstrap must create 2 seed bees');
        unlink($logFile);
    }

    /**
     * §0.6: Грамматики seed-пчёл попарно различны.
     */
    public function testSeedBeesHavePairwiseDifferentGrammars(): void
    {
        [$hive, $logFile] = $this->createHive();
        $bees = $hive->getBees();
        $this->assertCount(2, $bees);

        $grammars = array_map(fn (Bee $b) => $b->grammar(), $bees);
        $this->assertNotEquals($grammars[0], $grammars[1], 'G₁ ≠ G₂');
        unlink($logFile);
    }

    /**
     * §0.6: Seed-пчёлы имеют E₀ = 10.0.
     *
     * maxTicks=0: bootstrap без тика — novelty/ROUTE не влияют на энергию.
     * (Флак-фикс 01.08: static $seenFingerprints + array_rand давали +0.5
     * в первом тике → 10.49 ≠ 10.0.)
     */
    public function testSeedBeesHaveInitialEnergy(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'bs_');
        $hive = new Hive(maxTicks: 0, logFile: $logFile);
        $hive->run();
        $bees = $hive->getBees();
        foreach ($bees as $bee) {
            $this->assertSame(10.0, $bee->energy(), 'Seed bee must have E₀ = 10.0');
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
