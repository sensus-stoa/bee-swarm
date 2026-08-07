<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\SpawnManager;
use BeeSwarm\Infra\PlateauDetector;
use BeeSwarm\Infra\Database;

/**
 * GRAMMAR-DEGRADATION (P1, 06.08): монокультура |G|=1 самоподдерживается —
 * GAP_SPAWN рожает клонов. Фикс: при низком разнообразии грамматик
 * спавн обязан рожать РАЗНООБРАЗНЫЕ seed, а не клонов.
 */
class GrammarDegradationTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = tempnam(sys_get_temp_dir(), 'degrad_') . '.db';
        Database::setPath($this->dbPath);
        Database::get();
    }

    protected function tearDown(): void
    {
        Database::reset();
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
        Database::setPath(':memory:');
        parent::tearDown();
    }

    public function testGapSpawnDiversifiesMonoculture(): void
    {
        // Монокультура: 5 пчёл с ОДИНАКОВОЙ грамматикой |G|=1
        $hive = new Hive(
            plateau: new PlateauDetector(50, plateauSleepUs: 0),
            maxTicks: 0,
            logFile: tempnam(sys_get_temp_dir(), 'gd_'),
        );

        // Создаём монокультуру через рефлексию
        $ref = new \ReflectionClass(Hive::class);
        $bees = $ref->getProperty('bees');
        $bees->setAccessible(true);
        $monoBees = [];
        for ($i = 0; $i < 5; $i++) {
            $monoBees[] = new Bee(['add'], 10.0);
        }
        $bees->setValue($hive, $monoBees);

        // Уникальных грамматик: 1 (все 'add') → diversity низкая
        $sm = new SpawnManager();
        $bees = $hive->getBees();
        $spawned = $sm->tryGapSpawn($bees, ['+', '×', '−', '/', 'min', 'max'],
            true, 500, false, 50);

        $this->assertGreaterThan(0, $spawned, 'gap-spawn must fire on plateau');
        // GAP_SPAWN при монокультуре ОБЯЗАН родить seed-разнообразие
        // (+, ×, min) — не клоны 'add'. Иначе цикл |G|=1 самоподдерживается.
        $grammars = [];
        foreach ($bees as $bee) {
            $grammars[] = implode(',', $bee->grammar());
        }
        $unique = count(array_unique($grammars));
        $this->assertGreaterThanOrEqual(3, $unique,
            'gap-spawn must force seed diversity on monoculture; got: '
            . json_encode($grammars));
        // Среди уникальных должны быть +, ×, min (seed-набор)
        $joined = implode('|', $grammars);
        foreach (['+', '×', 'min'] as $seedOp) {
            $this->assertStringContainsString($seedOp, $joined,
                "seed op {$seedOp} must appear in gap-spawn result");
        }
    }

    public function testMutationNeverShrinksBelowTwoOps(): void
    {
        // Анти-вырождение: GrammarMutator при |G|=1 ОБЯЗАН чаще добавлять
        // (add), чем заменять (replace) — иначе грамматика схлопывается.
        $allOps = ['add', 'sub', 'mul', 'div', 'min', 'max', 'sq'];
        $grew = 0;
        mt_srand(7);
        for ($i = 0; $i < 100; $i++) {
            $mutated = \BeeSwarm\Hive\GrammarMutator::mutate(['add'], $allOps);
            if (count($mutated) > 1) {
                $grew++;
            }
        }

        $this->assertGreaterThan(0, $grew,
            'with |G|=1, mutation must add operators at least sometimes');
    }
}
