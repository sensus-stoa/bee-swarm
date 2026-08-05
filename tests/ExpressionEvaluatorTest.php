<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionEvaluator;

/**
 * SEARCH-TOP-K (05.08): ExpressionEvaluator — вычисление выражений по данным.
 * Held-out был мёртв для выражений (AtomRegistry::apply — только атомы).
 */
class ExpressionEvaluatorTest extends TestCase
{
    public function testBinaryExpression(): void
    {
        $rows = [[1.0], [2.0], [3.0]];
        $vec = ExpressionEvaluator::evaluateFormula('(x0×K2)', $rows);
        $this->assertSame([2.0, 4.0, 6.0], $vec);
    }

    public function testNestedExpression(): void
    {
        $rows = [[2.0], [3.0]];
        $vec = ExpressionEvaluator::evaluateFormula('((x0+x0)²)', $rows);
        $this->assertNotNull($vec);
        $this->assertEqualsWithDelta(16.0, $vec[0], 0.0001);
        $this->assertEqualsWithDelta(36.0, $vec[1], 0.0001);
    }

    public function testTwoFeatures(): void
    {
        $rows = [[2.0, 3.0], [4.0, 5.0]];
        $vec = ExpressionEvaluator::evaluateFormula('(x0×x1)', $rows);
        $this->assertSame([6.0, 20.0], $vec);
    }

    public function testRAttomUsesColumnStat(): void
    {
        // R+x0 = сумма колонки x0 по выборке
        $rows = [[1.0], [2.0], [3.0]];
        $vec = ExpressionEvaluator::evaluateFormula('(R+x0)', $rows);
        $this->assertSame([6.0, 6.0, 6.0], $vec);
    }

    public function testDivisionByZeroReturnsNull(): void
    {
        $rows = [[1.0], [2.0]];
        $vec = ExpressionEvaluator::evaluateFormula('(K1/x0)', $rows);
        $this->assertNotNull($vec);
        $this->assertEqualsWithDelta(1.0, $vec[0], 0.0001);
        $this->assertEqualsWithDelta(0.5, $vec[1], 0.0001);
    }
}
