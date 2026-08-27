<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\DormantPool;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Hive\ResourceScheduler;

/**
 * SPAWN-POOL-INTEGRATION Фаза B (27.08): Materialization Loop.
 *
 * Hive::materializeFromPool() — awaken top-K по квотам и вставка
 * материализованных пчёл в популяцию за счёт бюджета роя.
 */
class MaterializationLoopTest extends TestCase
{
    /** Hive с выключенным bootstrap-сканированием (быстрый) */
    private function makeShortHive(): Hive
    {
        putenv('FORAGER_SOURCES=:');
        $hive = new Hive(maxTicks: 0, logFile: tempnam(sys_get_temp_dir(), 'hive_'));
        $hive->run(); // bootstrap: seed-пчёлы + dormantPool init
        return $hive;
    }

    public function testHiveHasDormantPoolAccessor(): void
    {
        $hive = $this->makeShortHive();
        $pool = $hive->dormantPool();
        $this->assertInstanceOf(DormantPool::class, $pool);
    }

    public function testDepositAndMaterializeAddsBees(): void
    {
        $hive = $this->makeShortHive();
        $pool = $hive->dormantPool();

        $before = count($hive->getBees());

        // 5 рецептов в пул
        for ($i = 0; $i < 5; $i++) {
            $pool->deposit(['op' => '+', 'operand' => 'x0'], 'ADDITIVE', 0.5);
        }

        // Материализуем 3
        $added = $hive->materializeFromPool(3);
        $this->assertSame(3, $added, 'материализовано ровно 3 пчелы');
        $this->assertCount($before + 3, $hive->getBees());

        // Пул опустел (awakened+removed)
        $this->assertLessThanOrEqual(2, $pool->size());
    }

    public function testMaterializedBeesHaveGrammarFromRecipe(): void
    {
        $hive = $this->makeShortHive();
        $pool = $hive->dormantPool();

        $pool->deposit(['op' => 'sq', 'operand' => 'x1'], 'POWER', 0.9);
        $pool->deposit(['op' => '/', 'operand' => 'x2'], 'RATIO', 0.8);

        $hive->materializeFromPool(2);

        $bees = $hive->getBees();
        $grammars = array_map(fn (Bee $b) => $b->grammar(), $bees);
        $allOps = array_merge(...$grammars);
        $this->assertContains('sq', $allOps, 'оп из рецепта обязан быть в грамматике потомка');
        $this->assertContains('/', $allOps);
    }

    public function testMaterializeRespectsPoolSize(): void
    {
        $hive = $this->makeShortHive();
        $pool = $hive->dormantPool();

        // Пул пуст — материализация не добавляет ничего и не фаталит
        $added = $hive->materializeFromPool(5);
        $this->assertSame(0, $added);
    }
}
