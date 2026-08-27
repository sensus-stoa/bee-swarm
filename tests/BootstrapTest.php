<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;

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
     * G₁ = B, G₂ = mutate(B), G₃ = mutate(mutate(B)).
     */
    public function testBootstrapCreatesThreeSeedBees(): void
    {
        [$hive, $logFile] = $this->createHive();
        $bees = $hive->getBees();
        $this->assertCount(3, $bees, 'Bootstrap must create 3 seed bees');
        unlink($logFile);
    }

    /**
     * §0.6: Грамматики seed-пчёл попарно различны (все 3 пары).
     */
    public function testSeedBeesHavePairwiseDifferentGrammars(): void
    {
        [$hive, $logFile] = $this->createHive();
        $bees = $hive->getBees();
        $this->assertCount(3, $bees);

        $grammars = array_map(fn (Bee $b) => $b->grammar(), $bees);
        // Проверяем что все три грамматики попарно различны
        $this->assertNotEquals($grammars[0], $grammars[1], 'G₁ ≠ G₂');
        $this->assertNotEquals($grammars[0], $grammars[2], 'G₁ ≠ G₃');
        $this->assertNotEquals($grammars[1], $grammars[2], 'G₂ ≠ G₃');
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
        // ISOLATION (26.08): :memory: БД — иначе на ноуте подтягиваются
        // персистентные пчёлы из data/swarm.db (E=1.52 вместо E₀=10)
        Database::setPath(':memory:');
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
