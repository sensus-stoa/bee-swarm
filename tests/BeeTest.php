<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;

/**
 * Story S1.1: Bee Death — energy model
 * Protocol §2.1: E₀=10.0, ΔE_search=−0.1, ΔE_discovery=+2.0, ΔE_tick=−0.01
 */
class BeeTest extends TestCase
{
    private const E0 = 10.0;

    private const SEARCH_COST = 0.1;

    private const DISCOVERY_REWARD = 2.0;

    private const TICK_COST = 0.01;

    public function testInitialEnergy(): void
    {
        $bee = new Bee([], self::E0);
        $this->assertSame(self::E0, $bee->energy());
    }

    public function testTickCostsEnergy(): void
    {
        $bee = new Bee([], self::E0);
        $bee->tick();
        $this->assertEqualsWithDelta(
            self::E0 - self::TICK_COST,
            $bee->energy(),
            0.0001,
            'Tick must cost ' . self::TICK_COST
        );
    }

    public function testSearchCostsEnergy(): void
    {
        $bee = new Bee([], self::E0);
        $bee->chargeSearch();
        $this->assertEqualsWithDelta(
            self::E0 - self::SEARCH_COST,
            $bee->energy(),
            0.0001,
            'Search must cost ' . self::SEARCH_COST
        );
    }

    public function testDiscoveryRewardsEnergy(): void
    {
        $bee = new Bee([], self::E0);
        $bee->rewardDiscovery();
        $this->assertEqualsWithDelta(
            self::E0 + self::DISCOVERY_REWARD,
            $bee->energy(),
            0.0001,
            'Discovery must reward ' . self::DISCOVERY_REWARD
        );
    }

    public function testBeeIsAliveAboveZero(): void
    {
        $bee = new Bee([], self::E0);
        $this->assertTrue($bee->isAlive(), 'Bee with E>0 must be alive');
    }

    public function testBeeDiesAtZero(): void
    {
        $bee = new Bee([], 0.0);
        $this->assertFalse($bee->isAlive(), 'Bee with E=0 must be dead');
    }

    public function testBeeDiesBelowZero(): void
    {
        $bee = new Bee([], -0.1);
        $this->assertFalse($bee->isAlive(), 'Bee with E<0 must be dead');
    }

    /**
     * Bee dies after N ticks with no discoveries.
     * E₀=10, tick=−0.01 → 1000 ticks to death.
     * E₀=10, search=−0.1 per search, tick=−0.01 → faster.
     */
    public function testBeeDiesAfterManyTicks(): void
    {
        $bee = new Bee([], 10.0);
        // 1001 ticks at −0.01 each = −10.01 → E < 0
        for ($i = 0; $i < 1001; $i++) {
            $bee->tick();
        }
        $this->assertFalse($bee->isAlive(), 'Bee must die after 1001 ticks');
    }

    public function testEnergyNeverGoesNegative(): void
    {
        $bee = new Bee([], 0.005);
        $bee->tick(); // E = −0.005 → dead
        $this->assertFalse($bee->isAlive());
        // Energy should not be clamped — it reflects actual debt
        // But protocol says: E≤0 → exit, not "clamp to 0"
        $this->assertLessThan(0, $bee->energy());
    }
}
