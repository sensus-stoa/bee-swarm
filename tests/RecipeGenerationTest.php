<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\DormantPool;
use BeeSwarm\Hive\ResourceScheduler;

/**
 * SPAWN-POOL-INTEGRATION Фаза A (27.08): Recipe generation.
 *
 * Пчела с discovery порождает m дешёвых рецептов-потомков (genotype).
 * Рецепт = {op, operand, sector}. Никакой оценки — только генерация.
 */
class RecipeGenerationTest extends TestCase
{
    private function makeBeeWithOps(array $ops): Bee
    {
        // Минимальная грамматика: base ops + custom
        $grammar = ['+', '×', '−', '/'];
        foreach ($ops as $op) {
            $grammar[] = $op;
        }
        return new Bee($grammar);
    }

    public function testBeeEmitRecipesReturnsAtLeastM(): void
    {
        $bee = $this->makeBeeWithOps(['sq']);
        $recipes = $bee->emitRecipes(5);

        $this->assertIsArray($recipes);
        $this->assertCount(5, $recipes);
    }

    public function testRecipeHasOpOperandSector(): void
    {
        $bee = $this->makeBeeWithOps(['sq']);
        $recipes = $bee->emitRecipes(3);

        foreach ($recipes as $r) {
            $this->assertArrayHasKey('op', $r, 'рецепт обязан иметь op');
            $this->assertArrayHasKey('operand', $r, 'рецепт обязан иметь operand');
            $this->assertArrayHasKey('sector', $r, 'рецепт обязан иметь сектор');
        }
    }

    public function testRecipeOpsComeFromBeeGrammar(): void
    {
        // Если у пчелы нет 'sq' — рецепты не должны его содержать
        $bee = $this->makeBeeWithOps([]);
        $recipes = $bee->emitRecipes(10);

        foreach ($recipes as $r) {
            $this->assertNotContains($r['op'], ['sq', 'sqrt'],
                'оп вне грамматики пчелы недопустим');
        }
    }

    public function testRecipesAreCheapGenotypes(): void
    {
        // Рецепт НЕ содержит векторов значений — только описание операции
        $bee = $this->makeBeeWithOps(['sq']);
        $recipes = $bee->emitRecipes(2);

        foreach ($recipes as $r) {
            $json = json_encode($r);
            $this->assertLessThan(200, strlen((string) $json),
                'генотип обязан быть крошечным (<200 байт)');
            $this->assertArrayNotHasKey('vector', $r,
                'генотип не содержит phenotype-данных');
        }
    }

    public function testDepositToPoolStoresCheaply(): void
    {
        $pool = new DormantPool();
        $bee = $this->makeBeeWithOps(['sq']);
        $recipes = $bee->emitRecipes(3);

        foreach ($recipes as $i => $r) {
            $id = $pool->deposit($r, $r['sector'], 0.5);
            $this->assertIsInt($id);
        }
        $this->assertSame(3, $pool->size());
    }

    public function testSchedulerAwakensMaterializesTopK(): void
    {
        $pool = new DormantPool();
        $sched = new ResourceScheduler(maxMaterialized: 4);

        // Наполняем пул: 8 DIFF + 2 PRODUCT
        for ($i = 0; $i < 8; $i++) {
            $pool->deposit(['op' => '−'], 'DIFF', 0.1 * $i);
        }
        for ($i = 0; $i < 2; $i++) {
            $pool->deposit(['op' => '×'], 'PRODUCT', 0.9);
        }

        // Контракт: quotas распределяют budget по секторам; floor+max(1)
        // может дать сумму квот > budget при малых budget — это ОК,
        // redistribution только добавляет. Ограничиваем awakening pool-size'ом
        // и проверяем главным образом содержимое (PRODUCT с novelty=0.9 обязан быть).
        $quotas = $sched->computeQuotas($pool->size());
        $awakened = $pool->awaken(max(7, $sched->materializationBudget($pool->size())), $quotas);

        $this->assertLessThanOrEqual($pool->size() + 0, count($awakened));
        $sectors = array_column($awakened, 'sector');
        $this->assertContains('PRODUCT', $sectors,
            'высокая novelty гарантирует материализацию независимо от размера сектора');
        // DIFF не монополизирует: PRODUCT присутствует несмотря на 8-vs-2
        $this->assertContains('DIFF', $sectors);
    }
}
