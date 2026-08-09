<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;

/**
 * SHADOW-NO-REWARD (09.08, exploitation trap, ЭКСП-022g): на монотонных
 * данных ЛЮБАЯ монотонная функция фичи (abs, floor, ceil, sq, x0) даёт
 * CV=0 — «тени масштаба» (y∝x0). Каждая уникальная тень кормит пчелу →
 * рой заполняется охотниками за мусором. Фикс: простые атомы с CV≈0
 * НЕ дают энергию (составные законы — да).
 */
class ShadowNoRewardTest extends TestCase
{
    public function testShadowAtomDoesNotFeed(): void
    {
        $bee = new Bee(['+'], 10.0);
        $bee->rewardDiscovery(1.0, 'abs'); // тень: простой атом, CV≈0
        $this->assertEqualsWithDelta(10.0, $bee->energy(), 1e-9,
            'shadow atom (abs) must not feed');
    }

    public function testComposedLawFeeds(): void
    {
        $bee = new Bee(['+'], 10.0);
        $bee->rewardDiscovery(1.0, '(x0addx1)'); // составной закон
        $this->assertEqualsWithDelta(12.0, $bee->energy(), 1e-9,
            'composed law must feed (default reward 2.0)');
    }

    public function testConstantCompositionDoesNotFeed(): void
    {
        $bee = new Bee(['+'], 10.0);
        $bee->rewardDiscovery(1.0, '×(min)', hasFeatures: false); // без фич — мусор
        $this->assertEqualsWithDelta(10.0, $bee->energy(), 1e-9,
            'constant composition (×(min)) must not feed');
    }

    public function testFeatureLawFeeds(): void
    {
        $bee = new Bee(['+'], 10.0);
        $bee->rewardDiscovery(1.0, '(x0addx1)'); // закон с фичами
        $this->assertEqualsWithDelta(12.0, $bee->energy(), 1e-9,
            'feature law must feed');
    }
}
