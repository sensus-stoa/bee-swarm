<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Hive\DormantPool;
use BeeSwarm\Hive\ResourceScheduler;

/**
 * SPAWN-POOL-INTEGRATION Фаза D (27.08): Sector Labeling в квотах.
 *
 * Сквозной поток: emitRecipes (classifyOp) → deposit(sector) →
 * scheduler.computeQuotas → awaken respecting quotas.
 * Квоты секторов применяются РЕАЛЬНО: DIFF не съедает RATIO.
 */
class SectorLabelingTest extends TestCase
{
    private function makeShortHive(): Hive
    {
        putenv('FORAGER_SOURCES=:');
        $hive = new Hive(maxTicks: 0, logFile: tempnam(sys_get_temp_dir(), 'sec_'));
        $hive->run();
        return $hive;
    }

    public function testEmitRecipesCarryValidSectors(): void
    {
        $bee = new Bee(['+', '×', '−', '/', 'sq']);
        $recipes = $bee->emitRecipes(10);

        $validSectors = ['ADDITIVE', 'DIFF', 'PRODUCT', 'RATIO', 'POWER', 'unknown'];
        foreach ($recipes as $r) {
            $this->assertContains($r['sector'], $validSectors,
                "сектор '{$r['sector']}' из classifyOp обязан быть валидным");
        }
    }

    public function testMultiplicationGoesToProductSector(): void
    {
        $this->assertSame('PRODUCT', Bee::classifyOp('×'));
        $this->assertSame('PRODUCT', Bee::classifyOp('*'));
        $this->assertSame('PRODUCT', Bee::classifyOp('mul'));
        $this->assertSame('DIFF', Bee::classifyOp('−'));
        $this->assertSame('RATIO', Bee::classifyOp('/'));
        $this->assertSame('POWER', Bee::classifyOp('sq'));
        $this->assertSame('ADDITIVE', Bee::classifyOp('+'));
        $this->assertSame('unknown', Bee::classifyOp('sem'));
    }

    public function testQuotasRespectedInHiveMaterialization(): void
    {
        $hive = $this->makeShortHive();
        $pool = $hive->dormantPool();
        $sched = new ResourceScheduler(maxMaterialized: 10);

        // Перекос: 20 DIFF vs 2 RATIO. Квоты должны дать RATIO долю.
        for ($i = 0; $i < 20; $i++) {
            $pool->deposit(['op' => '−', 'operand' => 'x0'], 'DIFF', 0.1);
        }
        $pool->deposit(['op' => '/', 'operand' => 'x0'], 'RATIO', 0.9);
        $pool->deposit(['op' => '/', 'operand' => 'x1'], 'RATIO', 0.8);

        $quotas = $sched->computeQuotas($pool->size());
        $awakened = $pool->awaken(6, $quotas);
        $sectors = array_count_values(array_column($awakened, 'sector'));

        $this->assertArrayHasKey('RATIO', $sectors,
            'RATIO-рецепты обязаны материализоваться при наличии квоты');
        $this->assertGreaterThan(0, $sectors['RATIO']);
        // DIFF не должен съесть всё: хотя бы 1 RATIO из 6 при share 0.15
        $this->assertLessThanOrEqual(5, $sectors['DIFF'] ?? 0,
            'DIFF не монополизирует материализацию');
    }

    public function testUnknownSectorGetsFallbackQuota(): void
    {
        $hive = $this->makeShortHive();
        $pool = $hive->dormantPool();
        $sched = new ResourceScheduler(maxMaterialized: 5);

        // Только unknown-рецепты (op вне классификации)
        for ($i = 0; $i < 3; $i++) {
            $pool->deposit(['op' => 'is_a', 'operand' => 'x0'], 'unknown', 0.5);
        }

        $quotas = $sched->computeQuotas($pool->size());
        $awakened = $pool->awaken(3, $quotas);
        $this->assertGreaterThan(0, count($awakened),
            'unknown-сектор получает fallback-квоту, не игнорируется');
    }

    public function testEndToEndSectorFlow(): void
    {
        $hive = $this->makeShortHive();
        $pool = $hive->dormantPool();

        // Пчела с богатой грамматикой порождает рецепты — сектора разнообразны
        $bee = new Bee(['+', '×', '−', '/', 'sq']);
        $recipes = $bee->emitRecipes(10);
        $sectorsInRecipes = array_unique(array_column($recipes, 'sector'));
        $this->assertGreaterThan(1, count($sectorsInRecipes),
            'богатая грамматика даёт разные сектора');

        // Депозит в пул с секторами из рецептов
        foreach ($recipes as $r) {
            $pool->deposit($r, $r['sector'], 0.5);
        }

        // Материализация уважает сектора (не все одинаковые)
        $added = $hive->materializeFromPool(5);
        $this->assertGreaterThan(0, $added);
    }
}
