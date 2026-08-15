<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Trading\TradingHive;
use PHPUnit\Framework\TestCase;

/**
 * FIN-005 v11: НИШИ-ШТРАФ (fitness sharing) — пчела, чьи входы совпадают
 * с входами чемпиона, получает меньше энергии («ниша занята»).
 * RED: чистая функция overlap + штраф-фактор в ЯДРЕ.
 */
class NichePenaltyTest extends TestCase
{
    public function testOverlapFull(): void
    {
        $this->assertSame(1.0, TradingHive::nicheOverlap([10, 20, 30], [10, 20, 30]));
    }

    public function testOverlapEmpty(): void
    {
        $this->assertSame(0.0, TradingHive::nicheOverlap([10, 20, 30], [40, 50]));
        $this->assertSame(0.0, TradingHive::nicheOverlap([], [1, 2]));
    }

    public function testOverlapPartial(): void
    {
        $this->assertEqualsWithDelta(0.5, TradingHive::nicheOverlap([10, 20, 30, 40], [10, 20, 99]), 1e-9);
    }

    public function testPenaltyFactor(): void
    {
        $this->assertEqualsWithDelta(1.0, TradingHive::nichePenalty(0.0), 1e-9);
        $this->assertEqualsWithDelta(0.2, TradingHive::nichePenalty(1.0), 1e-9);
        $this->assertEqualsWithDelta(0.6, TradingHive::nichePenalty(0.5), 1e-9);
    }
}
