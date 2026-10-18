<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\RouteGenome;
use BeeSwarm\Hive\RouteLedger;

/**
 * EXP-045 S4: quota-account — учёт исходов, revoke неудачников, скоринг.
 */
class RouteLedgerTest extends TestCase
{
    private function genome(string $id): array
    {
        return RouteGenome::create(
            $id,
            '',
            0,
            [
                'kind' => 'ALWAYS',
            ],
            [[
                'op' => 'FLIGHT',
                'depth' => 2,
                'grammar' => 'base',
                'beam' => 'default',
            ]],
            0.2,
            1
        );
    }

    public function testOutcomeUpdatesRecord(): void
    {
        $l = new RouteLedger();
        $l->register($this->genome('route_aaaa1111'));
        $l->recordOutcome('route_aaaa1111', 'taskA', 0.10, false, 2.0, 0.3);
        $g = $l->get('route_aaaa1111');
        $this->assertSame(1, $g['record']['tasks_seen']);
        $this->assertEqualsWithDelta(0.10, $g['record']['delta_L_valid_sum'], 1e-12);
        $this->assertSame(1, $g['record']['wins']);
        $this->assertEqualsWithDelta(0.3, $g['record']['unspent_quota'], 1e-12);
        $this->assertFalse($l->isRevoked('route_aaaa1111'));
    }

    public function testRevokeAfterTwoFails(): void
    {
        $l = new RouteLedger();
        $l->register($this->genome('route_bbbb2222'));
        $l->recordOutcome('route_bbbb2222', 'taskA', -0.01, false, 1.0, 0.0);
        $this->assertFalse($l->isRevoked('route_bbbb2222'), 'один fail не отзывает');
        $l->recordOutcome('route_bbbb2222', 'taskB', 0.00, false, 1.0, 0.0);
        $this->assertTrue($l->isRevoked('route_bbbb2222'), 'два fail = revoke');
        $this->assertSame([], $l->active(), 'отозванный теряет право на ресурс');
    }

    public function testCertifiedRunIsWinEvenWithSmallDelta(): void
    {
        $l = new RouteLedger();
        $l->register($this->genome('route_cccc3333'));
        $l->recordOutcome('route_cccc3333', 'taskA', 0.001, true, 3.0, 0.0);
        $g = $l->get('route_cccc3333');
        $this->assertSame(1, $g['record']['wins']);
        $this->assertSame(0, $g['record']['fails']);
    }

    public function testScorePrefersHigherDeltaAndPenalizesComplexity(): void
    {
        $l = new RouteLedger();
        $short = $this->genome('route_dddd4444');
        $l->register($short);
        $l->recordOutcome('route_dddd4444', 't', 0.20, false, 1.0, 0.0);

        $long = RouteGenome::create('route_eeee5555', '', 0, [
            'kind' => 'ALWAYS',
        ], [
            [
                'op' => 'FLIGHT',
                'depth' => 1,
                'grammar' => 'base',
                'beam' => 'default',
            ],
            [
                'op' => 'USE_MARKS',
                'k' => 3,
            ],
            [
                'op' => 'LOCAL_BIRTH',
            ],
            [
                'op' => 'FLIGHT',
                'depth' => 3,
                'grammar' => 'diffprod',
                'beam' => 'beam40',
            ],
        ], 0.2, 4);
        $l->register($long);
        $l->recordOutcome('route_eeee5555', 't', 0.20, false, 1.0, 0.0);

        $this->assertGreaterThan(
            $l->score($long),
            $l->score($short),
            'при равном delta более сложный маршрут получает меньший скор',
        );
    }

    public function testTopForReproductionRequiresTasksSeen(): void
    {
        $l = new RouteLedger();
        $fresh = $this->genome('route_ffff6666'); // ноль наблюдений
        $l->register($fresh);
        $seen = $this->genome('route_aaaa7777');
        $l->register($seen);
        $l->recordOutcome('route_aaaa7777', 't', 0.30, false, 1.0, 0.0);
        $top = $l->topForReproduction(5);
        $this->assertNotEmpty($top);
        foreach ($top as $g) {
            $this->assertGreaterThan(0, $g['record']['tasks_seen']);
        }
    }

    public function testExportShape(): void
    {
        $l = new RouteLedger();
        $l->register($this->genome('route_aaaa8888'));
        $l->recordOutcome('route_aaaa8888', 't', -0.5, false, 1.0, 0.1);
        $l->recordOutcome('route_aaaa8888', 't2', -0.5, false, 1.0, 0.1);
        $exp = $l->export();
        $this->assertCount(1, $exp['routes']);
        $this->assertSame(['route_aaaa8888'], $exp['revoked']);
        $this->assertCount(2, $exp['outcomes']['route_aaaa8888']);
    }

    public function testUnknownRouteRejected(): void
    {
        $l = new RouteLedger();
        $this->expectException(\InvalidArgumentException::class);
        $l->recordOutcome('route_nope0000', 't', 0.1, false, 1.0, 0.0);
    }
}
