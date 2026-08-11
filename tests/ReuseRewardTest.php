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

    public function testTransferAtomGetsDoubleBonus(): void
    {
        // ф2: атом с reuse в ≥2 РЕАЛЬНЫХ доменах (перенос! transfer²) → ×2.0
        \BeeSwarm\Infra\Database::get()->exec("DELETE FROM grammar_ops WHERE source = 'birth'");
        \BeeSwarm\Core\Grammar::staticAdd('B4', 'birth', '(x0mulx1)', 'foraged_a');
        \BeeSwarm\Core\Grammar::registerReuse('B4', 'search');      // технический!
        \BeeSwarm\Core\Grammar::registerReuse('B4', 'foraged_b');
        \BeeSwarm\Core\Grammar::registerReuse('B4', 'foraged_c');

        $b = $this->beeWithEnergy(10.0);
        $b->rewardDiscovery(1.0, '((x0B4x1)mulx2)', true);
        // 2.0 × 2.0 (transfer: 2 реальных домена, search исключён!) = 4.0
        $this->assertEqualsWithDelta(14.0, $b->energy(), 0.0001,
            'transfer-атом (2 реальных домена) кормит ×2.0');
    }

    public function testSearchOnlyAtomGetsBaseBonus(): void
    {
        // BLOCK deleg_8458f590: search+1 реальный = НЕ transfer (×1.5!)
        \BeeSwarm\Infra\Database::get()->exec("DELETE FROM grammar_ops WHERE source = 'birth'");
        \BeeSwarm\Core\Grammar::staticAdd('B3', 'birth', '(x0subx1)', 'foraged_a');
        \BeeSwarm\Core\Grammar::registerReuse('B3', 'search');
        \BeeSwarm\Core\Grammar::registerReuse('B3', 'foraged_b');

        $b = $this->beeWithEnergy(10.0);
        $b->rewardDiscovery(1.0, '((x0B3x1)mulx2)', true);
        $this->assertEqualsWithDelta(13.0, $b->energy(), 0.0001,
            'search+1 реальный домен = ×1.5 (не transfer!)');
    }

    public function testPlainLawNoBonus(): void
    {
        $b = $this->beeWithEnergy(10.0);
        $b->rewardDiscovery(1.0, '((x0+x1)×x2)', true);
        $this->assertEqualsWithDelta(12.0, $b->energy(), 0.0001,
            'закон без B-атома — стандартный reward');
    }
}
