<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * L3L1 (25.08.2026, EXP-028): heat conduction P = κ·(T2−T1)·A/d
 * требует depth 4: ((L1)×фича)/фича — L2L1 даёт только (L1 op фича).
 * L3L1 = (L2 op фича) при depth>=4.
 */
class L3L1Test extends TestCase
{
    public function testHeatConductionDepth4(): void
    {
        // P = kappa*(T2-T1)*A/d (как feynman_heat_conduction.csv)
        $X = [];
        $y = [];
        $rng = mt_srand(42);
        for ($i = 0; $i < 300; $i++) {
            $kappa = mt_rand() / mt_getrandmax() * 10 + 0.1;
            $t2 = mt_rand() / mt_getrandmax() * 70 + 280;
            $t1 = mt_rand() / mt_getrandmax() * 90 + 250;
            $a = mt_rand() / mt_getrandmax() * 4.5 + 0.5;
            $d = mt_rand() / mt_getrandmax() * 1.9 + 0.1;
            $X[] = [$kappa, $t2, $t1, $a, $d];
            $y[] = $kappa * ($t2 - $t1) * $a / $d;
        }

        $g = new Grammar();
        $g->restrictTo(['add', 'sub', 'mul', 'div', 'sq']);

        // depth 3 (без L3L1) — НЕ выражает
        $res3 = Search::find($X, $y, $g, 3, null, 0.0, 0.15, 10.0);
        $this->assertFalse($res3[0], 'depth 3 не выражает (L2/фича)');

        // depth 4 (L3L1!) — находит
        $res4 = Search::find($X, $y, $g, 4, null, 0.0, 0.15, 60.0);
        echo "  depth4: found=" . var_export($res4[0], true) . " cv=" . round($res4[1], 4)
            . " formula={$res4[2]} class={$res4[4]}\n";
        $this->assertTrue($res4[0], 'depth 4 находит heat conduction (L3L1!)');
        $this->assertLessThan(0.10, $res4[1], 'CV найденного закона < 0.10');
        echo "\n  L3L1 heat: {$res4[2]} CV=" . round($res4[1], 4) . "\n";
    }
}
