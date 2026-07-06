<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;

class AtomRegistryTest extends TestCase
{
    // ═══ 1. АЛФАВИТ СРЕДЫ ═══

    /** Все атомы доступны */
    public function test_all_atoms_available(): void
    {
        $atoms = AtomRegistry::all();
        $this->assertGreaterThan(30, count($atoms), 'Should have 30+ environment atoms');
    }

    /** Унарные атомы есть */
    public function test_unary_atoms_present(): void
    {
        $atoms = AtomRegistry::all();
        $unary = ['abs', 'sqrt', 'sin', 'cos', 'exp', 'log', 'floor', 'ceil', 'neg', 'sq'];
        foreach ($unary as $name) {
            $this->assertContains($name, $atoms, "Missing unary atom: $name");
        }
    }

    /** Бинарные атомы есть */
    public function test_binary_atoms_present(): void
    {
        $atoms = AtomRegistry::all();
        $binary = ['add', 'sub', 'mul', 'div', 'min', 'max', 'gt', 'lt', 'eq', 'and', 'or'];
        foreach ($binary as $name) {
            $this->assertContains($name, $atoms, "Missing binary atom: $name");
        }
    }

    /** Классификация: унарный vs бинарный */
    public function test_is_unary(): void
    {
        $this->assertTrue(AtomRegistry::isUnary('abs'));
        $this->assertTrue(AtomRegistry::isUnary('sqrt'));
        $this->assertFalse(AtomRegistry::isUnary('add'));
        $this->assertFalse(AtomRegistry::isUnary('min'));
    }

    public function test_is_binary(): void
    {
        $this->assertTrue(AtomRegistry::isBinary('add'));
        $this->assertTrue(AtomRegistry::isBinary('min'));
        $this->assertFalse(AtomRegistry::isBinary('sqrt'));
        $this->assertFalse(AtomRegistry::isBinary('abs'));
    }

    // ═══ 2. ПРИМЕНЕНИЕ АТОМОВ ═══

    /** apply: унарный */
    public function test_apply_unary(): void
    {
        $this->assertEqualsWithDelta(5.0, AtomRegistry::apply('abs', -5.0), 0.001);
        $this->assertEqualsWithDelta(2.0, AtomRegistry::apply('sqrt', 4.0), 0.001);
        $this->assertEqualsWithDelta(9.0, AtomRegistry::apply('sq', 3.0), 0.001);
        $this->assertEqualsWithDelta(0.0, AtomRegistry::apply('sin', 0.0), 0.001);
        $this->assertEqualsWithDelta(1.0, AtomRegistry::apply('exp', 0.0), 0.001);
    }

    /** apply: бинарный */
    public function test_apply_binary(): void
    {
        $this->assertEqualsWithDelta(5.0, AtomRegistry::apply('add', 2.0, 3.0), 0.001);
        $this->assertEqualsWithDelta(6.0, AtomRegistry::apply('mul', 2.0, 3.0), 0.001);
        $this->assertEqualsWithDelta(2.0, AtomRegistry::apply('min', 5.0, 2.0), 0.001);
        $this->assertEqualsWithDelta(5.0, AtomRegistry::apply('max', 5.0, 2.0), 0.001);
        $this->assertEqualsWithDelta(1.0, AtomRegistry::apply('gt', 3.0, 1.0), 0.001);
        $this->assertEqualsWithDelta(0.0, AtomRegistry::apply('gt', 1.0, 3.0), 0.001);
    }

    /** apply: деление на ноль */
    public function test_apply_div_by_zero(): void
    {
        $this->assertNull(AtomRegistry::apply('div', 5.0, 0.0));
    }

    /** apply: sqrt отрицательного */
    public function test_apply_sqrt_negative(): void
    {
        $this->assertNull(AtomRegistry::apply('sqrt', -4.0));
    }

    /** apply: log отрицательного */
    public function test_apply_log_negative(): void
    {
        $this->assertNull(AtomRegistry::apply('log', 0.0));
    }

    // ═══ 3. DISCOVER: поиск атома для задачи ═══

    /** discover: находит add для ADD */
    public function test_discover_add(): void
    {
        $X = [[1.0], [3.0], [5.0], [10.0]];
        $y = [3.0, 7.0, 11.0, 30.0];
        // ADD = x0 + x1, но у нас 2 признака
        $X2 = [[1.0, 2.0], [3.0, 4.0], [5.0, 6.0], [10.0, 20.0]];
        
        $result = AtomRegistry::discover($X2, $y);
        $this->assertNotNull($result, 'Should find at least one atom');
        $this->assertContains('add', array_column($result, 'atom'), 'Should discover add for ADD task');
    }

    /** discover: находит sqrt для SQRT */
    public function test_discover_sqrt(): void
    {
        $X = [[1.0], [4.0], [9.0], [16.0]];
        $y = [1.0, 2.0, 3.0, 4.0];
        
        $result = AtomRegistry::discover($X, $y);
        $found = array_filter($result, fn($r) => $r['atom'] === 'sqrt' && $r['cv'] < 0.001);
        $this->assertNotEmpty($found, 'Should discover sqrt for SQRT task');
    }

    /** discover: находит min для MIN */
    public function test_discover_min(): void
    {
        $X = [[0.0, 0.0], [2.0, 3.0], [5.0, 1.0], [4.0, 4.0]];
        $y = [0.0, 2.0, 1.0, 4.0];
        
        $result = AtomRegistry::discover($X, $y);
        $found = array_filter($result, fn($r) => $r['atom'] === 'min' && $r['cv'] < 0.001);
        $this->assertNotEmpty($found, 'Should discover min for MIN task');
    }

    // ═══ 4. COMPOSE: композиция атомов ═══

    /** compose: abs(sub(x,y)) для |x−y| */
    public function test_compose_abs_sub(): void
    {
        $X = [[1.0, 3.0], [5.0, 1.0], [2.0, 2.0], [0.0, 5.0]];
        $y = [2.0, 4.0, 0.0, 5.0]; // |x−y|
        
        $grammar = ['abs', 'sub'];
        $result = AtomRegistry::discoverCompose($X, $y, $grammar);
        $found = array_filter($result, fn($r) => $r['cv'] < 0.001);
        $this->assertNotEmpty($found, 'Should discover abs(sub) for |x-y|');
    }

    /** compose: mul(min(x,y), z) для min×z */
    public function test_compose_mul_min(): void
    {
        $X = [[2.0, 5.0, 3.0], [3.0, 1.0, 2.0], [4.0, 4.0, 1.0]];
        $y = [6.0, 2.0, 4.0]; // min(x,y)×z
        
        $grammar = ['mul', 'min'];
        $result = AtomRegistry::discoverCompose($X, $y, $grammar);
        $found = array_filter($result, fn($r) => $r['cv'] < 0.001);
        $this->assertNotEmpty($found, 'Should discover mul(min) for min×z');
    }

    /** compose: sq(add(x,y)) для (x+y)² */
    public function test_compose_sq_add(): void
    {
        $X = [[1.0, 2.0], [3.0, 1.0], [0.0, 0.0], [2.0, 3.0]];
        $y = [9.0, 16.0, 0.0, 25.0]; // (x+y)²
        
        $grammar = ['sq', 'add'];
        $result = AtomRegistry::discoverCompose($X, $y, $grammar);
        $found = array_filter($result, fn($r) => $r['cv'] < 0.001);
        $this->assertNotEmpty($found, 'Should discover sq(add) for (x+y)²');
    }

    // ═══ 5. НАКОПЛЕНИЕ СИГНАЛА (кросс-домен) ═══

    /** signal: атом с CV=0 на нескольких задачах имеет высокий сигнал */
    public function test_signal_accumulation(): void
    {
        $tasks = [
            ['X' => [[1.0, 2.0], [3.0, 4.0], [5.0, 6.0]], 'y' => [3.0, 7.0, 11.0], 'domain' => 'arith'],
            ['X' => [[1.0, 2.0], [3.0, 4.0], [5.0, 6.0]], 'y' => [3.0, 7.0, 11.0], 'domain' => 'arith'],
            ['X' => [[-3.0], [-1.0], [0.0], [2.0]], 'y' => [3.0, 1.0, 0.0, 2.0], 'domain' => 'arith'],
        ];
        
        $signals = AtomRegistry::accumulateSignal($tasks, 'add');
        $this->assertGreaterThanOrEqual(2.0, $signals['total'], 'add should have high signal');
        $this->assertEquals(1, $signals['domains'], 'add works in 1 domain');
    }

    /** signal: кросс-доменный атом получает бонус */
    public function test_cross_domain_bonus(): void
    {
        $tasks = [
            ['X' => [[-3.0], [-1.0], [0.0], [2.0]], 'y' => [3.0, 1.0, 0.0, 2.0], 'domain' => 'arith', 'novelty' => 1.0],
            ['X' => [[-5.0], [3.0], [-2.0]], 'y' => [5.0, 3.0, 2.0], 'domain' => 'cross', 'novelty' => 5.0],
        ];
        
        $signal = AtomRegistry::accumulateSignal($tasks, 'abs');
        $this->assertGreaterThan(0, $signal['total'], 'abs should have positive signal');
        $this->assertEquals(2, $signal['domains'], 'abs works in 2 domains');
    }
}
