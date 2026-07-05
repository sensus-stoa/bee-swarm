<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\AtomRegistry;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Database;

class DiscoveryLoopTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Чистим grammar_ops от предыдущих тестов
        Database::get()->exec("DELETE FROM grammar_ops WHERE source LIKE 'auto%' OR source = 'seed'");
    }

    /** Открытый атом попадает в grammar */
    public function test_discovered_atom_added_to_grammar(): void
    {
        $g = new Grammar();
        
        // SQRT задача
        $X = [[1.0], [4.0], [9.0], [16.0]];
        $y = [1.0, 2.0, 3.0, 4.0];
        
        $found = AtomRegistry::discover($X, $y);
        $this->assertNotEmpty($found, 'Should find sqrt');
        
        $hasSqrt = false;
        foreach ($found as $f) {
            if ($f['atom'] === 'sqrt') {
                $g->add($f['atom'], 'auto-discover');
                $hasSqrt = true;
            }
        }
        
        $this->assertTrue($hasSqrt, 'sqrt should be discovered');
        $this->assertContains('sqrt', $g->all(), 'sqrt should be in grammar ops');
    }

    /** Compose-атом попадает в grammar */
    public function test_compose_added_to_grammar(): void
    {
        $g = new Grammar();
        $g->add('abs', 'seed');
        $g->add('sub', 'seed');
        
        $X = [[1.0, 3.0], [5.0, 1.0], [2.0, 2.0]];
        $y = [2.0, 4.0, 0.0]; // |x−y|
        
        $found = AtomRegistry::discoverCompose($X, $y, $g->all());
        $this->assertNotEmpty($found, 'Should find abs(sub)');
        
        foreach ($found as $f) {
            $g->add($f['atom'], 'auto-compose');
        }
        
        $this->assertContains('abs(sub)', $g->all(), 'Compose atom in grammar');
    }

    /** Полный цикл: задача → discover → compose → grammar растёт */
    public function test_full_discovery_cycle(): void
    {
        $g = new Grammar();
        $atomsDiscovered = [];
        
        // ФАЗА 1: простые атомы
        $tasks = [
            ['X' => [[1.0, 2.0], [3.0, 4.0], [5.0, 6.0]], 'y' => [3.0, 7.0, 11.0]],
            ['X' => [[1.0], [4.0], [9.0], [16.0]], 'y' => [1.0, 2.0, 3.0, 4.0]],
            ['X' => [[0.0, 0.0], [2.0, 3.0], [5.0, 1.0]], 'y' => [0.0, 2.0, 1.0]],
        ];
        
        foreach ($tasks as $task) {
            foreach (AtomRegistry::discover($task['X'], $task['y']) as $f) {
                if (!in_array($f['atom'], $g->all())) {
                    $g->add($f['atom'], 'auto');
                    $atomsDiscovered[] = $f['atom'];
                }
            }
        }
        
        $this->assertGreaterThanOrEqual(2, count($atomsDiscovered), 'Phase 1: atoms discovered');
        
        // ФАЗА 2: compose
        $compTasks = [
            ['X' => [[1.0, 3.0], [5.0, 1.0]], 'y' => [2.0, 4.0]], // |x-y|
            ['X' => [[1.0, 2.0], [3.0, 1.0]], 'y' => [9.0, 16.0]], // (x+y)²
        ];
        
        $composesFound = [];
        foreach ($compTasks as $task) {
            // Сначала простые
            foreach (AtomRegistry::discover($task['X'], $task['y']) as $f) {
                if (!in_array($f['atom'], $g->all())) {
                    $g->add($f['atom'], 'auto');
                    $atomsDiscovered[] = $f['atom'];
                }
            }
            // Затем compose
            foreach (AtomRegistry::discoverCompose($task['X'], $task['y'], $g->all()) as $f) {
                if (!in_array($f['atom'], $g->all())) {
                    $g->add($f['atom'], 'auto-compose');
                    $composesFound[] = $f['atom'];
                }
            }
        }
        
        $this->assertNotEmpty($composesFound, 'Phase 2: compose atoms found. Grammar: ' . implode(', ', $g->all()));
    }

    /** Новый домен → выше сигнал */
    public function test_cross_domain_signal(): void
    {
        $tasks = [
            ['X' => [[1.0, 2.0], [3.0, 4.0]], 'y' => [3.0, 7.0], 'domain' => 'arith', 'novelty' => 1.0],
            ['X' => [[1.0, 2.0], [3.0, 4.0]], 'y' => [3.0, 7.0], 'domain' => 'cross', 'novelty' => 5.0],
        ];
        
        $signal = AtomRegistry::accumulateSignal($tasks, 'add');
        $this->assertEqualsWithDelta(6.0, $signal['total'], 0.1, '1×1 + 5×1 = 6');
        $this->assertEquals(2, $signal['domains']);
    }

    /** Известные законы не награждаются повторно */
    public function test_known_laws_not_rewarded(): void
    {
        $known = [];
        $energy = 10.0;
        
        $tasks = [
            ['X' => [[1.0, 2.0], [3.0, 4.0]], 'y' => [3.0, 7.0], 'domain' => 'arith', 'novelty' => 1.0],
        ];
        
        // Проход 1
        foreach ($tasks as $task) {
            foreach (AtomRegistry::discover($task['X'], $task['y']) as $f) {
                $key = $f['atom'];
                if (!isset($known[$key])) {
                    $known[$key] = true;
                    $energy += $task['novelty'] ?? 1.0;
                }
            }
        }
        $energy1 = $energy;
        $this->assertGreaterThan(10.0, $energy1, 'Energy increased on first pass');
        
        // Проход 2 — те же задачи
        foreach ($tasks as $task) {
            foreach (AtomRegistry::discover($task['X'], $task['y']) as $f) {
                $key = $f['atom'];
                if (!isset($known[$key])) {
                    $known[$key] = true;
                    $energy += $task['novelty'] ?? 1.0;
                }
            }
        }
        $this->assertEquals($energy1, $energy, 'Energy unchanged — known laws not re-rewarded');
    }
}
