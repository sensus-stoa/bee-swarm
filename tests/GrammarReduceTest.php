<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;

/**
 * S1.9-GENERATIVE: Grammar::reduce(op, vector) — arity bridge.
 *
 * ОДНА аксиома: любой ассоциативный бинарный оператор применим к вектору.
 * НЕ sum, НЕ mean — reduce.
 */
class GrammarReduceTest extends TestCase
{
    private Grammar $g;

    protected function setUp(): void
    {
        parent::setUp();
        $this->g = new Grammar();
    }

    /**
     * reduce('+', v) = сумма вектора
     */
    public function testReduceAddReturnsSum(): void
    {
        $this->assertSame(6.0, $this->g->reduce('+', [1.0, 2.0, 3.0]));
    }

    /**
     * reduce('×', v) = произведение вектора
     */
    public function testReduceMulReturnsProduct(): void
    {
        $this->assertSame(24.0, $this->g->reduce('×', [2.0, 3.0, 4.0]));
    }

    /**
     * reduce('max', v) = максимум вектора
     */
    public function testReduceMaxReturnsMaximum(): void
    {
        $this->assertSame(7.0, $this->g->reduce('max', [3.0, 7.0, 5.0]));
    }

    /**
     * reduce('min', v) = минимум вектора
     */
    public function testReduceMinReturnsMinimum(): void
    {
        $this->assertSame(3.0, $this->g->reduce('min', [3.0, 7.0, 5.0]));
    }

    /**
     * reduce с одним элементом = сам элемент (моноидная единица)
     */
    public function testReduceSingleElement(): void
    {
        $this->assertSame(7.0, $this->g->reduce('+', [7.0]));
    }

    /**
     * Пустой вектор → null (нет данных, не 0)
     */
    public function testReduceEmptyVectorReturnsNull(): void
    {
        $this->assertNull($this->g->reduce('+', []));
    }

    /**
     * Неассоциативный оператор (−) → null
     */
    public function testReduceSubReturnsNull(): void
    {
        $this->assertNull($this->g->reduce('−', [1.0, 2.0, 3.0]));
    }

    /**
     * Неассоциативный оператор (/) → null
     */
    public function testReduceDivReturnsNull(): void
    {
        $this->assertNull($this->g->reduce('/', [1.0, 2.0]));
    }

    /**
     * Неизвестный оператор → null
     */
    public function testReduceUnknownOpReturnsNull(): void
    {
        $this->assertNull($this->g->reduce('nonexistent', [1.0, 2.0]));
    }

    /**
     * Семантический предикат не применим к вектору → null
     */
    public function testReduceSemanticOpReturnsNull(): void
    {
        $this->assertNull($this->g->reduce('is_a', [1.0, 2.0]));
    }

    /**
     * Float precision: 0.1 + 0.2 ≈ 0.3 (IEEE 754)
     */
    public function testReduceFloatPrecision(): void
    {
        $this->assertEqualsWithDelta(0.3, $this->g->reduce('+', [0.1, 0.2]), 0.0001);
    }
}
