<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionEvaluator;

/**
 * B-AS-ARGUMENT (09.08, ЭКСП-022i): парсер должен знать B-операторы
 * (динамические, из БД) — иначе (x0B7a7aee(x1)) → null → 9.99 →
 * B-кандидаты мёртвы → reuse=0 (технический провал, не концептуальный).
 */
class BOperatorParsingTest extends TestCase
{
    public function testBOperatorParsesAndEvaluates(): void
    {
        // B7a7aee = (x0+x1): (x0B7a7aee(x1)) должно вычислиться
        $X = [[2.0, 3.0], [5.0, 7.0]];
        $r = ExpressionEvaluator::evaluateFormula('(x0B7a7aee(x1))', $X, null, ['B7a7aee'], ['B7a7aee' => '(x0addx1)']);
        $this->assertNotNull($r, 'B-operator must parse');
        $this->assertEqualsWithDelta([5.0, 12.0], $r, 1e-9,
            'B7a7aee(x0,x1) = x0+x1');
    }

    public function testBOperatorInComposition(): void
    {
        // ((x0B7a7aee(x1))mulx2) = (x0+x1)×x2
        $X = [[2.0, 3.0, 4.0], [5.0, 7.0, 2.0]];
        $r = ExpressionEvaluator::evaluateFormula('((x0B7a7aee(x1))mulx2)', $X, null, ['B7a7aee'], ['B7a7aee' => '(x0addx1)']);
        $this->assertNotNull($r, 'B-operator composition must parse');
        $this->assertEqualsWithDelta([20.0, 24.0], $r, 1e-9,
            '(x0+x1)×x2');
    }

    public function testUnknownBPrefixStillNull(): void
    {
        // Неизвестный B-оператор: null (честный отказ, не мусор)
        $X = [[1.0, 2.0]];
        $r = ExpressionEvaluator::evaluateFormula('(x0Bdeadbe(x1))', $X, null, ['B7a7aee'], ['B7a7aee' => '(x0addx1)']);
        $this->assertNull($r, 'unknown B-operator must be null');
    }

    public function testNestedBOperators(): void
    {
        // B1 = (x0+x1), B2 = (x0×x1): (B2(B1(x0,x1),x2)) = (x0+x1)×x2
        $X = [[2.0, 3.0, 4.0], [5.0, 7.0, 2.0]];
        $defs = ['B1' => '(x0addx1)', 'B2' => '(x0mulx1)'];
        $r = ExpressionEvaluator::evaluateFormula('(x0B2(x1)B1(x2))', $X, null, ['B1', 'B2'], $defs);
        // упрощённо: B2 с аргументами (B1(...), ...) — проверим через композицию
        $r2 = ExpressionEvaluator::evaluateFormula('((x0B1(x1))B2(x2))', $X, null, ['B1', 'B2'], $defs);
        $this->assertNotNull($r2, 'nested B must parse: ' . json_encode($r2));
        $this->assertEqualsWithDelta([20.0, 24.0], $r2, 1e-9, '(x0+x1)×x2');
    }

    public function testSelfReferentialBReturnsNull(): void
    {
        // Bself содержит себя — depth-guard: null, не бесконечность
        $X = [[2.0, 3.0]];
        $defs = ['Bself' => '(x0Bself(x1))'];
        $r = ExpressionEvaluator::evaluateFormula('(x0Bself(x1))', $X, null, ['Bself'], $defs);
        $this->assertNull($r, 'self-referential B must be null (depth guard)');
    }
}
