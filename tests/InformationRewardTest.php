<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;

/**
 * Information Reward: бонус энергии за сам акт поиска,
 * независимо от результата. Внутренняя ценность информации.
 *
 * Nature Neuroscience (Bussell et al., 2026): мыши жертвуют водой
 * ради информации. Поиск — сам по себе награда.
 */
class InformationRewardTest extends TestCase
{
    public function testSearchGivesInformationReward(): void
    {
        $bee = new Bee(['add'], 10.0, informationReward: 0.1);
        $this->assertSame(10.0, $bee->energy());

        $bee->rewardInformation();
        $this->assertEqualsWithDelta(10.1, $bee->energy(), 0.0001, 'Information reward +0.1');

        $bee->rewardInformation();
        $this->assertEqualsWithDelta(10.2, $bee->energy(), 0.0001);
    }

    public function testDefaultInformationRewardIsZero(): void
    {
        $bee = new Bee(['add'], 10.0);
        $this->assertSame(Bee::DEFAULT_INFORMATION_REWARD, $bee->informationReward());
        $bee->rewardInformation();
        $this->assertSame(10.0, $bee->energy(), 'Default reward = 0, no effect');
    }

    public function testDeadBeeIgnoresInformationReward(): void
    {
        $bee = new Bee(['add'], 0.0, informationReward: 0.1);
        $this->assertFalse($bee->isAlive());
        $bee->rewardInformation();
        $this->assertSame(0.0, $bee->energy(), 'Dead bee must not get reward');
    }

    public function testInformationRewardIsHeritable(): void
    {
        $parent = new Bee(['add', 'mul', 'sub'], 15.0, informationReward: 0.1);
        $child = $parent->spawn(['add', 'mul', 'sub', 'sq']);

        $this->assertNotNull($child);
        // Ребёнок наследует с мутацией ±20% → [0.08, 0.12]
        $child->rewardInformation();
        $this->assertGreaterThan(7.0, $child->energy());
        $this->assertLessThan(7.2, $child->energy(), 'Child info reward must be around 0.1');
    }

    public function testInformationRewardAccessor(): void
    {
        $bee = new Bee(['add'], 10.0, informationReward: 0.05);
        $this->assertSame(0.05, $bee->informationReward());
    }

    public function testInfoRewardCanEvolveFromMinimum(): void
    {
        // Минимальное значение > 0 — мутация может изменить
        $parent = new Bee(['add', 'mul'], 15.0, informationReward: 0.001);
        $child = $parent->spawn(['add', 'mul', 'sq']);
        $this->assertNotNull($child);
        // Даже от минимума мутация может уйти
        $this->assertGreaterThanOrEqual(0.0008, $child->informationReward());
    }
}
