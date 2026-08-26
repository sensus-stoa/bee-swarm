<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\DormantPool;

/**
 * DORMANT-POOL (26.08, resource-bounded evolution):
 * Genotype/phenotype separation — рецепты дешёвы, материализация дорога.
 * «Не уничтожить правильную мысль до того, как она проявилась.»
 */
class DormantPoolTest extends TestCase
{
    public function testDepositAndSize(): void
    {
        $pool = new DormantPool();
        $this->assertSame(0, $pool->size());

        $id = $pool->deposit(['op' => '−', 'i' => 1, 'j' => 2], 'DIFF', 0.8);
        $this->assertSame(1, $pool->size());
        $this->assertIsInt($id);
    }

    public function testAwakenRespectsSectorQuotas(): void
    {
        $pool = new DormantPool();
        // 5 DIFF, 3 PRODUCT, 2 RATIO
        for ($i = 0; $i < 5; $i++) {
            $pool->deposit(['op' => '−'], 'DIFF', 0.5 + $i * 0.1);
        }
        for ($i = 0; $i < 3; $i++) {
            $pool->deposit(['op' => '×'], 'PRODUCT', 0.6 + $i * 0.1);
        }
        for ($i = 0; $i < 2; $i++) {
            $pool->deposit(['op' => '/'], 'RATIO', 0.7);
        }

        // Квота: DIFF=2, PRODUCT=1 → awaken 3
        $awakened = $pool->awaken(10, ['DIFF' => 2, 'PRODUCT' => 1]);
        $this->assertCount(3, $awakened);

        // Проверяем секторы
        $sectors = array_column($awakened, 'sector');
        $this->assertCount(2, array_filter($sectors, fn ($s) => $s === 'DIFF'));
        $this->assertCount(1, array_filter($sectors, fn ($s) => $s === 'PRODUCT'));
    }

    public function testAwakenTakesHighestNovelty(): void
    {
        $pool = new DormantPool();
        $pool->deposit(['op' => '−'], 'DIFF', 0.3); // low novelty
        $pool->deposit(['op' => '−'], 'DIFF', 0.9); // high novelty
        $pool->deposit(['op' => '−'], 'DIFF', 0.6); // medium

        $awakened = $pool->awaken(1, ['DIFF' => 1]);
        $this->assertCount(1, $awakened);
        $this->assertEqualsWithDelta(0.9, $awakened[0]['novelty'], 0.001);
    }

    public function testAgeRemovesOldEntries(): void
    {
        $pool = new DormantPool();
        $pool->deposit(['op' => '−'], 'DIFF', 0.5);
        $this->assertSame(1, $pool->size());

        // Age 5 раз — maxAge=3 → удалится
        for ($i = 0; $i < 5; $i++) {
            $pool->age(3);
        }
        $this->assertSame(0, $pool->size());
    }

    public function testAwakenedNotAged(): void
    {
        $pool = new DormantPool();
        $pool->deposit(['op' => '−'], 'DIFF', 0.9);
        $pool->awaken(1, ['DIFF' => 1]); // пометить как awakened

        // Age не должен удалить awakened
        for ($i = 0; $i < 20; $i++) {
            $pool->age(3);
        }
        $this->assertSame(1, $pool->size());
    }

    public function testSectorCounts(): void
    {
        $pool = new DormantPool();
        $pool->deposit(['op' => '−'], 'DIFF', 0.5);
        $pool->deposit(['op' => '−'], 'DIFF', 0.6);
        $pool->deposit(['op' => '×'], 'PRODUCT', 0.7);

        $counts = $pool->sectorCounts();
        $this->assertSame(2, $counts['DIFF']);
        $this->assertSame(1, $counts['PRODUCT']);
    }
}
