<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;

/**
 * DOMAIN-SATIETY (HHO5-аналог, 08.08): награда за закон-класс в домене
 * убывает с насыщением: первый класс ×1.5 (новизна домена), после K
 * классов ×0.1 («я сыт») — пчела переключается на новые домены.
 */
class DomainSatietyTest extends TestCase
{
    public function testFirstClassInDomainBoosted(): void
    {
        $bee = new Bee(['+'], 10.0);
        $bee->registerClass('alpha');
        $this->assertEqualsWithDelta(1.5, $bee->discoveryMultiplier('alpha'), 1e-9,
            'first class in domain must be boosted ×1.5');
    }

    public function testSatiatedDomainRewardDiminished(): void
    {
        $bee = new Bee(['+'], 10.0);
        // 3 класса в ОДНОМ домене — ещё нормальная зона (K=3)
        foreach (['a', 'a', 'a'] as $d) {
            $bee->registerClass($d);
        }
        $this->assertEqualsWithDelta(1.0, $bee->discoveryMultiplier('a'), 1e-9,
            'K classes = normal reward');
        // 4-й класс — насыщение
        $bee->registerClass('a');
        $this->assertEqualsWithDelta(0.1, $bee->discoveryMultiplier('a'), 1e-9,
            'beyond K classes = satiety ×0.1');
    }

    public function testSatietyIsPerDomain(): void
    {
        $bee = new Bee(['+'], 10.0);
        $bee->registerClass('alpha');
        $bee->registerClass('alpha');
        $bee->registerClass('alpha');
        $bee->registerClass('alpha');
        $this->assertEqualsWithDelta(0.1, $bee->discoveryMultiplier('alpha'), 1e-9,
            'satiated domain');
        $this->assertEqualsWithDelta(1.0, $bee->discoveryMultiplier('beta'), 1e-9,
            'unvisited domain = normal');
        $bee->registerClass('beta');
        $this->assertEqualsWithDelta(1.5, $bee->discoveryMultiplier('beta'), 1e-9,
            'fresh domain first class boosted');
    }

    public function testBoundaryClasses(): void
    {
        $bee = new Bee(['+'], 10.0);
        // n=0: домен не посещён — нормальный множитель (default 1.0)
        $this->assertEqualsWithDelta(1.0, $bee->discoveryMultiplier('none'), 1e-9);
        // n=2, n=3: нейтральная зона
        $bee->registerClass('a');
        $bee->registerClass('a');
        $this->assertEqualsWithDelta(1.0, $bee->discoveryMultiplier('a'), 1e-9, 'n=2 normal');
        $bee->registerClass('a');
        $this->assertEqualsWithDelta(1.0, $bee->discoveryMultiplier('a'), 1e-9, 'n=3 normal (K)');
        // n=4: насыщение
        $bee->registerClass('a');
        $this->assertEqualsWithDelta(0.1, $bee->discoveryMultiplier('a'), 1e-9, 'n=4 satiety');
    }

    public function testRewardDiscoveryBackwardCompatible(): void
    {
        // Вызов без множителя = прежнее поведение (+2.0 при дефолтном reward)
        $bee = new Bee(['+'], 10.0);
        $bee->rewardDiscovery();
        $this->assertEqualsWithDelta(12.0, $bee->energy(), 1e-9);
        // С множителем 1.5: +3.0
        $bee2 = new Bee(['+'], 10.0);
        $bee2->rewardDiscovery(1.5);
        $this->assertEqualsWithDelta(13.0, $bee2->energy(), 1e-9);
    }
}
