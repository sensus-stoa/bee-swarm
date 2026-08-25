<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionEvaluator;

/**
 * Rnorm-stats баг (25.08.2026, PYSR-бенчмарк):
 * collectReduceStats для 'norm' клал $stats['Rnormx11'] = null →
 * evalNode isset(null) → return null → evaluateFormula(Rnorm-закон) = NULL
 * → heldout-валидация Rnorm-законов ВСЕГДА 9.99 («heldout-слепота»!).
 *
 * Фикс: для 'norm' собирать Rmin/Rrange (поточечная ветка Rnorm в evalNode).
 */
class RnormStatsTest extends TestCase
{
    public function testEvaluateFormulaWithCollectStatsRnorm(): void
    {
        // Невырожденные данные: x11 = i*12+0.5 → range > 0
        $X = [];
        for ($i = 0; $i < 20; $i++) {
            $X[] = [$i, $i * 2, $i * 3, $i * 4, $i * 5, $i * 6, $i * 7, $i * 8, $i * 9, $i * 10, $i * 11, $i * 12 + 0.5];
        }

        $stats = ExpressionEvaluator::collectStats('((Rnormx11)addRmaxx0)', $X);
        $this->assertArrayHasKey('Rminx11', $stats, 'norm собирает Rmin');
        $this->assertArrayHasKey('Rrangex11', $stats, 'norm собирает Rrange');
        $this->assertArrayNotHasKey('Rnormx11', $stats, 'norm НЕ кладёт null-ключ (isset-ловушка!)');

        $pred = ExpressionEvaluator::evaluateFormula('((Rnormx11)addRmaxx0)', $X, $stats);
        $this->assertNotNull($pred, 'Rnorm-закон вычисляется (было: NULL → heldout 9.99!)');
        $this->assertCount(20, $pred);
        foreach ($pred as $v) {
            $this->assertIsFloat($v, 'значения float');
            $this->assertTrue(is_finite($v), 'значения конечные');
        }
    }

    public function testRnormBareAtomStillWorks(): void
    {
        $X = [[1.0, 2.0, 3.0], [2.0, 4.0, 6.0], [3.0, 6.0, 9.0]];
        $stats = ExpressionEvaluator::collectStats('Rnormx0', $X);
        $pred = ExpressionEvaluator::evaluateFormula('Rnormx0', $X, $stats);
        $this->assertNotNull($pred);
        $this->assertCount(3, $pred);
    }
}
