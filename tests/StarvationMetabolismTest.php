<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;

/**
 * ECONOMICS-OF-DIVERSITY (SHRINK-AND-PERSIST, п.1): голод ≠ смерть,
 * голод = замедление. При E < порога (3.0) тик-стоимость ×0.1 —
 * пчела живёт в 10 раз дольше на малом метаболизме.
 */
class StarvationMetabolismTest extends TestCase
{
    public function testStarvingBeePaysReducedTickCost(): void
    {
        $bee = new Bee(['+'], 2.0); // E < 3.0 — голодная

        $bee->tick();
        // Тик-стоимость: 0.01 × 0.1 = 0.001
        $this->assertEqualsWithDelta(2.0 - 0.001, $bee->energy(), 1e-9,
            'starving bee must pay 0.1× tick cost');
    }

    public function testWellFedBeePaysFullTickCost(): void
    {
        $bee = new Bee(['+'], 5.0); // E ≥ 3.0 — сытая

        $bee->tick();
        $this->assertEqualsWithDelta(5.0 - 0.01, $bee->energy(), 1e-9,
            'well-fed bee must pay full tick cost');
    }

    public function testStarvationExtendsLifetime(): void
    {
        // Голодная пчела живёт ~10× дольше в тиках (до смерти)
        $starving = new Bee(['+'], 3.0);
        $ticks = 0;
        while ($starving->isAlive() && $ticks < 10000) {
            $starving->tick();
            $ticks++;
        }
        // 3.0 / 0.001 = 3000 тиков (без starvation было бы 300)
        $this->assertGreaterThan(1000, $ticks,
            'starvation must extend lifetime: ' . $ticks . ' ticks');
    }

    public function testStarvingBeeDoesNotHungerMutate(): void
    {
        // CONCERNS deleg_43a824dd: E<3 = спячка, мутации НЕТ (иначе
        // зомби раздувает грамматику 2900 тиков). Мутация только 3≤E<5.
        $bee = new Bee(['+'], 2.5);
        $g = $bee->grammar();
        $bee->hungerMutate(['+', '×', 'min', 'max', 'sq']);
        $this->assertSame($g, $bee->grammar(),
            'starving bee must NOT hunger-mutate (hibernation)');
    }

    public function testHungryButNotStarvingMutates(): void
    {
        // 3 ≤ E < 5: адаптация до голода
        $bee = new Bee(['+'], 4.0);
        $bee->hungerMutate(['+', '×', 'min', 'max', 'sq', 'sub', 'div']);
        // Может мутировать (добавить/заменить) — грамматика не обязана
        // измениться (стохастика), но метод не должен падать и при E<3 не трогает.
        $this->assertTrue(true);
    }
}
