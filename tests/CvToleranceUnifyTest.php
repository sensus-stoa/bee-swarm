<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;
use BeeSwarm\Math\CvCalculator;

/**
 * L1: Единая толерантность CV (unify Search::cv vs CvCalculator::compute).
 *
 * Search::cv (EXP-036, SCALE-INVARIANCE): exact-check eps ОТНОСИТЕЛЬНЫЙ —
 * abs(d) > 0.0001 * max(1.0, |y_i|). CvCalculator::compute: eps АБСОЛЮТНЫЙ
 * 0.0001 → отвергает точный закон с масштабом y (остаток ≤1e-3, K3
 * kill-кейс): один и тот же вектор законен в поиске и незаконен у
 * LawValidator / AtomRegistry::retrospectiveValidate — два источника истины.
 *
 * GREEN: относительный eps в CvCalculator, сигнатура не меняется
 * (unify = ЕДИНОЕ поведение, не опциональный параметр).
 */
final class CvToleranceUnifyTest extends TestCase
{
    /**
     * Точный закон y = 10·x с остатком 5e-4: |vec-y| = 5e-4 > 1e-4 (abs-eps
     * отвергает) но ≤ 1e-4·10 = 1e-3 (relative-eps принимает). Оба источника
     * обязаны давать exact 0.0.
     */
    public function testScaledExactLawAcceptedLikeSearchCv(): void
    {
        $y = [];
        $vec = [];
        for ($i = 0; $i < 8; $i++) {
            $x = 2.0 + 0.5 * $i;
            $y[] = 10.0 * $x;
            $vec[] = 10.0 * $x + 5e-4;
        }

        $cvSearch = Search::cv($vec, $y);
        $cvCalc = CvCalculator::compute($vec, $y);

        $this->assertSame(0.0, $cvSearch, 'Search::cv (референс EXP-036): точный закон с масштабом 10 = exact 0.0');
        $this->assertSame(0.0, $cvCalc, 'CvCalculator обязан давать exact 0.0 как Search::cv (единая толерантность, L1)');
    }

    /**
     * Обратная сторона: закон, реально отстоящий от допуска, обязан
     * отвергаться ОБАМИ источниками — unify не вправе ослабить допуск.
     *
     * Per-element семантика max(1,|y_i|) (review#5): нарушение ТОЛЬКО на
     * последнем элементе (малый y=10 → порог 1.1e-3 < 1e-2) — регрессия
     * «порог посчитан один раз по |y_0|=1e6» ловится; голова y=1e6 с
     * остатком ровно 0.0001*1e6 = граница допуска (строгое неравенство →
     * первый элемент проходит — пин равенства).
     */
    public function testOffLawStillRejectedByBoth(): void
    {
        $y = [1.0e6, 10.0, 20.0, 40.0, 80.0, 160.0, 320.0, 640.0];
        $vec = [];
        foreach ($y as $i => $yi) {
            // Голова: точная граница допуска. Хвост: нарушение 9x порога.
            $vec[] = $yi + ($i === 0 ? 0.0001 * 1.0e6 : 1.0e-2);
        }

        $cvSearch = Search::cv($vec, $y);
        $cvCalc = CvCalculator::compute($vec, $y);

        $this->assertGreaterThan(0.0, $cvSearch, 'Search::cv: остаток за допуском на хвосте = не exact');
        $this->assertGreaterThan(0.0, $cvCalc, 'CvCalculator: остаток за допуском на хвосте = не exact (unify не ослабляет)');
    }
}
