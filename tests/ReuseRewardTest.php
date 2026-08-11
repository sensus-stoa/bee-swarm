<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;

/**
 * REUSE-REWARD ф1 (11.08): бонус кооперации — закон, ИСПОЛЬЗУЮЩИЙ
 * B-атом (reuse!), кормит пчелу ×1.5 (социум выгоднее одиночества).
 * touchAtom (e18d553) сделал reuse достоверной метрикой — теперь
 * экономика награждает культуру.
 */
class ReuseRewardTest extends TestCase
{
    private function beeWithEnergy(float $e): Bee
    {
        $b = new Bee(['add', 'mul'], $e);
        $b->setBirthTick(0);

        return $b;
    }

    public function testLawWithBAtomGetsBonus(): void
    {
        $b = $this->beeWithEnergy(10.0);
        $b->rewardDiscovery(1.0, '((x0B5x1)mulx2)', true);
        // 2.0 × 1.5 (reuse-бонус) = 3.0
        $this->assertEqualsWithDelta(13.0, $b->energy(), 0.0001,
            'закон с B-атомом кормит ×1.5');
    }

    public function testShadowWithBAtomGetsNoReward(): void
    {
        // CONCERNS deleg_7e2b29a3: порядок «гейт vs бонус» — тень
        // (x0B5x1 — без операторов!) с B-атомом НЕ кормит: гейт
        // NO-REWARD-FOR-NONBUILDERS срабатывает ДО начисления
        $b = $this->beeWithEnergy(10.0);
        $b->rewardDiscovery(1.0, 'B5', true);
        $this->assertEqualsWithDelta(10.0, $b->energy(), 0.0001,
            'тень с B-атомом: бонус не применяется (гейт до начисления)');
    }

    public function testPlainLawNoBonus(): void
    {
        $b = $this->beeWithEnergy(10.0);
        $b->rewardDiscovery(1.0, '((x0+x1)×x2)', true);
        $this->assertEqualsWithDelta(12.0, $b->energy(), 0.0001,
            'закон без B-атома — стандартный reward');
    }
}
