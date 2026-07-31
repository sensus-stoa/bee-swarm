<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;

class GrammarTest extends TestCase
{
    private Grammar $g;

    protected function setUp(): void
    {
        parent::setUp();
        $this->g = new Grammar();
    }

    /**
     * Базовые операции: +, ×, −, /
     */
    public function testBaseOpsPresent(): void
    {
        $ops = $this->g->all();
        $this->assertContains('+', $ops);
        $this->assertContains('×', $ops);
        $this->assertContains('−', $ops);
        $this->assertContains('/', $ops);
    }

    /**
     * apply: сложение
     */
    public function testApplyAdd(): void
    {
        $this->assertSame(5.0, $this->g->apply(2, 3, '+'));
    }

    /**
     * apply: умножение
     */
    public function testApplyMul(): void
    {
        $this->assertSame(6.0, $this->g->apply(2, 3, '×'));
    }

    /**
     * apply: вычитание
     */
    public function testApplySub(): void
    {
        $this->assertSame(-1.0, $this->g->apply(2, 3, '−'));
    }

    /**
     * apply: деление
     */
    public function testApplyDiv(): void
    {
        $this->assertSame(2.0, $this->g->apply(6, 3, '/'));
    }

    /**
     * apply: деление на ноль → null
     */
    public function testApplyDivByZero(): void
    {
        $this->assertNull($this->g->apply(5, 0, '/'));
    }

    /**
     * apply: неизвестная операция → null
     */
    public function testApplyUnknown(): void
    {
        $this->assertNull($this->g->apply(1, 2, 'nonexistent'));
    }

    /**
     * apply: хардкодные унарные — abs
     */
    public function testApplyAbs(): void
    {
        $this->assertSame(5.0, $this->g->apply(-5, 0, 'abs'));
    }

    /**
     * apply: MIN
     */
    public function testApplyMin(): void
    {
        $this->assertSame(3.0, $this->g->apply(7, 3, 'MIN'));
    }

    /**
     * apply: MAX
     */
    public function testApplyMax(): void
    {
        $this->assertSame(7.0, $this->g->apply(7, 3, 'MAX'));
    }

    /**
     * apply: inverse
     */
    public function testApplyInverse(): void
    {
        $this->assertSame(0.5, $this->g->apply(2, 0, 'inverse'));
    }

    /**
     * apply: pow2
     */
    public function testApplyPow(): void
    {
        $this->assertSame(8.0, $this->g->apply(3, 0, 'pow2')); // 2^3 = 8
    }

    /**
     * apply: parity
     */
    public function testApplyParityEven(): void
    {
        $this->assertSame(1.0, $this->g->apply(2, 0, 'parity'));
    }

    public function testApplyParityOdd(): void
    {
        $this->assertSame(-1.0, $this->g->apply(3, 0, 'parity'));
    }

    /**
     * apply: log2
     */
    public function testApplyLog2(): void
    {
        $this->assertEqualsWithDelta(1.0, $this->g->apply(2, 0, 'log2'), 0.01);
    }

    /**
     * Унарные операции
     */
    public function testUnaryOps(): void
    {
        // Добавляем унарные в grammar чтобы они были видны
        $this->g->add('abs', 'test');
        $this->g->add('sq', 'test');
        $unary = $this->g->getUnaryOps();
        $this->assertContains('abs', $unary);
    }

    /**
     * add: добавление новой операции
     */
    public function testAddNewOp(): void
    {
        $uniq = 'test_op_' . uniqid();
        $result = $this->g->add($uniq, 'test');
        $this->assertTrue($result);
        $this->assertContains($uniq, $this->g->all());
    }

    /**
     * add: дубликат → false
     */
    public function testAddDuplicate(): void
    {
        $this->g->add('dup_op', 'test');
        $result = $this->g->add('dup_op', 'test');
        $this->assertFalse($result);
    }

    /**
     * add с definition → expression tree
     */
    public function testAddWithDefinition(): void
    {
        $def = json_encode([
            'op' => '+',
            'left' => 'a',
            'right' => 'b',
        ]);
        $this->g->add('plus_alias', 'test', $def);

        // apply должен работать с кастомной операцией
        $result = $this->g->apply(3, 4, 'plus_alias');
        $this->assertSame(7.0, $result);
    }

    /**
     * count
     */
    public function testCount(): void
    {
        $initial = $this->g->count();
        $uniq1 = 'ct_' . uniqid();
        $uniq2 = 'ct_' . uniqid();
        $this->g->add($uniq1, 'test');
        $this->g->add($uniq2, 'test');
        $this->assertSame($initial + 2, $this->g->count());
    }

    /**
     * restrictTo: фильтр грамматики
     */
    public function testRestrict(): void
    {
        $this->g->restrictTo(['+', '−']);
        $ops = $this->g->all();
        $this->assertContains('+', $ops);
        $this->assertContains('−', $ops);
        $this->assertNotContains('×', $ops);
        $this->assertNotContains('/', $ops);
    }

    /**
     * clearAll: полная очистка
     */
    public function testClearAll(): void
    {
        $this->g->clearAll();
        $this->assertEmpty($this->g->all());
        $this->assertSame(0, $this->g->count());
    }

    /**
     * reloadFromDb: восстановление после очистки.
     *
     * S1.10: :memory: БД стартует пустой — self-seed перед reload,
     * чтобы тест не зависел от порядка в файле (testAddNewOp).
     */
    public function testReload(): void
    {
        $this->g->add('seed_op_for_reload', 'test');
        $this->g->clearAll();
        $this->g->reloadFromDb();
        $this->assertContains('seed_op_for_reload', $this->g->all());
    }
}
