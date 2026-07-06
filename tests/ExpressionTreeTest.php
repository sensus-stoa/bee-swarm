<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionTree;

class ExpressionTreeTest extends TestCase
{
    /**
     * Простое сложение через дерево
     */
    public function testAddTree(): void
    {
        $tree = new ExpressionTree([
            'op' => '+',
            'left' => 'a',
            'right' => 'b',
        ]);
        $this->assertSame(5.0, $tree->evaluate(2, 3));
    }

    /**
     * Умножение
     */
    public function testMulTree(): void
    {
        $tree = new ExpressionTree([
            'op' => '×',
            'left' => 'a',
            'right' => 'b',
        ]);
        $this->assertSame(6.0, $tree->evaluate(2, 3));
    }

    /**
     * Вычитание
     */
    public function testSubTree(): void
    {
        $tree = new ExpressionTree([
            'op' => '−',
            'left' => 'a',
            'right' => 'b',
        ]);
        $this->assertSame(-1.0, $tree->evaluate(2, 3));
    }

    /**
     * Деление
     */
    public function testDivTree(): void
    {
        $tree = new ExpressionTree([
            'op' => '/',
            'left' => 'a',
            'right' => 'b',
        ]);
        $this->assertSame(3.0, $tree->evaluate(6, 2));
    }

    /**
     * Деление на ноль → безопасно
     */
    public function testDivByZeroTree(): void
    {
        $tree = new ExpressionTree([
            'op' => '/',
            'left' => 'a',
            'right' => 0,
        ]);
        $result = $tree->evaluate(5, 0);
        $this->assertGreaterThan(1e6, $result); // 5 / 1e-8 ≈ 5e8
    }

    /**
     * Вложенное дерево: (a + b) * (a - b)
     */
    public function testNestedTree(): void
    {
        $tree = new ExpressionTree([
            'op' => '×',
            'left' => [
                'op' => '+',
                'left' => 'a',
                'right' => 'b',
            ],
            'right' => [
                'op' => '−',
                'left' => 'a',
                'right' => 'b',
            ],
        ]);
        // (5+3) * (5-3) = 8 * 2 = 16
        $this->assertSame(16.0, $tree->evaluate(5, 3));
    }

    /**
     * Унарная операция: abs
     */
    public function testAbsTree(): void
    {
        $tree = new ExpressionTree([
            'op' => 'abs',
            'arg' => [
                'op' => '−',
                'left' => 'a',
                'right' => 'b',
            ],
        ]);
        // abs(2 - 5) = 3
        $this->assertSame(3.0, $tree->evaluate(2, 5));
    }

    /**
     * Унарная операция: sq (квадрат)
     */
    public function testSqTree(): void
    {
        $tree = new ExpressionTree([
            'op' => 'sq',
            'arg' => 'a',
        ]);
        $this->assertSame(9.0, $tree->evaluate(3, 0));
    }

    /**
     * sqrt
     */
    public function testSqrtTree(): void
    {
        $tree = new ExpressionTree([
            'op' => 'sqrt',
            'arg' => 'a',
        ]);
        $this->assertSame(3.0, $tree->evaluate(9, 0));
    }

    /**
     * min
     */
    public function testMinTree(): void
    {
        $tree = new ExpressionTree([
            'op' => 'min',
            'left' => 'a',
            'right' => 'b',
        ]);
        $this->assertSame(3.0, $tree->evaluate(7, 3));
    }

    /**
     * max
     */
    public function testMaxTree(): void
    {
        $tree = new ExpressionTree([
            'op' => 'max',
            'left' => 'a',
            'right' => 'b',
        ]);
        $this->assertSame(7.0, $tree->evaluate(7, 3));
    }

    /**
     * native: вызов функции через whitelist
     */
    public function testNativeAbs(): void
    {
        $tree = new ExpressionTree([
            'op' => 'native',
            'fn' => 'abs',
            'arg' => 'a',
        ]);
        $this->assertSame(5.0, $tree->evaluate(-5, 0));
    }

    /**
     * native: sqrt
     */
    public function testNativeSqrt(): void
    {
        $tree = new ExpressionTree([
            'op' => 'native',
            'fn' => 'sqrt',
            'arg' => 'a',
        ]);
        $this->assertSame(4.0, $tree->evaluate(16, 0));
    }

    /**
     * native: неизвестная функция → 0
     */
    public function testNativeUnknown(): void
    {
        $tree = new ExpressionTree([
            'op' => 'native',
            'fn' => 'imaginary_fn',
            'arg' => 'a',
        ]);
        $this->assertSame(0.0, $tree->evaluate(42, 0));
    }

    /**
     * parseFormula: x0 → a
     */
    public function testFromFormulaX0(): void
    {
        $tree = ExpressionTree::fromFormula('x0');
        $this->assertNotNull($tree);
        $this->assertSame(5.0, $tree->evaluate(5, 0));
    }

    /**
     * parseFormula: x1 → b
     */
    public function testFromFormulaX1(): void
    {
        $tree = ExpressionTree::fromFormula('x1');
        $this->assertNotNull($tree);
        $this->assertSame(7.0, $tree->evaluate(0, 7));
    }

    /**
     * parseFormula: K2 → константа
     */
    public function testFromFormulaConstant(): void
    {
        $tree = ExpressionTree::fromFormula('K2.5');
        $this->assertNotNull($tree);
        $this->assertSame(2.5, $tree->evaluate(0, 0));
    }

    /**
     * parseFormula: унарная (x0abs)
     */
    public function testFromFormulaUnary(): void
    {
        $tree = ExpressionTree::fromFormula('(x0abs)');
        $this->assertNotNull($tree);
        $this->assertSame(5.0, $tree->evaluate(-5, 0));
    }

    /**
     * parseFormula: сложная → null (не парсим)
     */
    public function testFromFormulaComplexReturnsNull(): void
    {
        $tree = ExpressionTree::fromFormula('((x0+x1)/(x0−x1))');
        $this->assertNull($tree);
    }

    /**
     * Конструктор из JSON-строки
     */
    public function testConstructFromJson(): void
    {
        $json = json_encode([
            'op' => '+',
            'left' => 'a',
            'right' => 'b',
        ]);
        $tree = new ExpressionTree($json);
        $this->assertSame(10.0, $tree->evaluate(4, 6));
    }

    /**
     * toJson: сериализация
     */
    public function testToJson(): void
    {
        $tree = new ExpressionTree([
            'op' => '+',
            'left' => 'a',
            'right' => 'b',
        ]);
        $json = $tree->toJson();
        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertSame('+', $decoded['op']);
    }
}
