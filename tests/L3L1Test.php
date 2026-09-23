<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * L3L1 (25.08.2026, EXP-028): heat conduction P = κ·(T2−T1)·A/d
 * требует depth 4: ((L1)×фича)/фича — L2L1 даёт только (L1 op фича).
 * L3L1 = (L2 op фича) при depth>=4.
 *
 * 23.09 (v0.18 WU-1 хвост): инлайн-каскады L3L1 откатаны после OOM 14.5GB
 * (EXP-029); ре-энабл — предмет стори associative-culture (культурный атом
 * B1=(x0−x1) + chunk-direct цепочка). До этого момента текущее поведение
 * depth-4 на чистой грамматике = честный отказ — пиним его как регрессионный
 * контракт границы выразимости вместо markTestSkipped (мёртвая ветка:
 * условие skip всегда true, ассерт после недостижим).
 */
final class L3L1Test extends TestCase
{
    /**
     * Фикстура heat conduction: P = κ(T2−T1)·A/d, детерминизм mt_srand(42).
     */
    private function heatFixture(): array
    {
        $X = [];
        $y = [];
        mt_srand(42);
        for ($i = 0; $i < 300; $i++) {
            $kappa = mt_rand() / mt_getrandmax() * 10 + 0.1;
            $t2 = mt_rand() / mt_getrandmax() * 70 + 280;
            $t1 = mt_rand() / mt_getrandmax() * 90 + 250;
            $a = mt_rand() / mt_getrandmax() * 4.5 + 0.5;
            $d = mt_rand() / mt_getrandmax() * 1.9 + 0.1;
            $X[] = [$kappa, $t2, $t1, $a, $d];
            $y[] = $kappa * ($t2 - $t1) * $a / $d;
        }

        return [$X, $y];
    }

    private function restrictedGrammar(): Grammar
    {
        $g = new Grammar();
        $g->restrictTo(['add', 'sub', 'mul', 'div', 'sq']);

        return $g;
    }

    /**
     * DEPTH-граница: на depth 3 heat-закон не выражается — честный отказ.
     */
    public function testHeatConductionDepth3Refuses(): void
    {
        [$X, $y] = $this->heatFixture();
        $g = $this->restrictedGrammar();

        $res3 = Search::find($X, $y, $g, 3, null, 0.0, 0.15, 10.0);
        $this->assertFalse($res3[0], 'depth 3 не выражает (L2/фича)');
    }

    /**
     * Текущий контракт (L3L1 откатан): depth 4 на чистой грамматике
     * не достраивает композицию — отказ с диагнозом, без подмены бюджета.
     * Ре-энабл L3L1 перевернёт этот тест ОСОЗНАННО (associative-culture).
     */
    public function testHeatConductionDepth4RejectsWhileL3L1Disabled(): void
    {
        [$X, $y] = $this->heatFixture();
        $g = $this->restrictedGrammar();

        $res4 = Search::find($X, $y, $g, 4, null, 0.0, 0.15, 60.0);
        $this->assertFalse($res4[0], 'L3L1 откатан: depth 4 не находит (EXP-029)');
        $this->assertNotSame('TIMEOUT', $res4[5] ?? '', 'отказ должен быть переборный, не по бюджету');
        $this->assertNotSame('WALLCLOCK_CAP', $res4[5] ?? '', 'отказ должен быть переборный, не по wall-clock');
    }

    /**
     * Выразимость формулы ГРАММАТИКОЙ не потеряна: подстановка целевой
     * формы даёт R²=1 (граница — генерация L3L1, не evaluator).
     */
    public function testHeatFormulaRemainExpressibleByEvaluator(): void
    {
        [$X, $y] = $this->heatFixture();

        $fv = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula(
            '(((x0×(x1−x2))×x3)/x4)',
            $X,
            null
        );
        $this->assertNotNull($fv, 'канон-синтаксис формулы должен вычисляться');

        $err = 0.0;
        $ss = 0.0;
        $mean = array_sum($y) / count($y);
        foreach ($y as $i => $v) {
            $err += ((float) $v - $fv[$i]) ** 2;
            $ss += ((float) $v - $mean) ** 2;
        }
        $this->assertGreaterThan(0.9999, 1 - $err / $ss, 'R² формы = 1 (выразимость цела)');
    }
}
