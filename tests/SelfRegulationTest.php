<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\Database;

class SelfRegulationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Seed test data
        $db = Database::get();
        $db->exec("DELETE FROM laws WHERE domain LIKE 'test_reg%'");
        $db->exec("DELETE FROM grammar_ops WHERE source = 'test_reg'");
    }

    // ═══ 1. D/C RATIO ═══

    /**
     * D/C вычисляется из DB
     */
    public function testComputeDcRatio(): void
    {
        // Добавляем тестовые данные
        $db = Database::get();
        for ($i = 0; $i < 5; $i++) {
            $db->prepare('INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
                ->execute(["test_reg_d{$i}", "atom{$i}", 0.0, 'test_reg_gen']);
        }
        for ($i = 0; $i < 3; $i++) {
            $db->prepare('INSERT OR IGNORE INTO grammar_ops (name, source) VALUES (?,?)')
                ->execute(["test_reg_op{$i}", 'test_reg']);
        }

        $metrics = $this->computeDCRatio();
        $this->assertArrayHasKey('D', $metrics);
        $this->assertArrayHasKey('C', $metrics);
        $this->assertArrayHasKey('ratio', $metrics);
        $this->assertGreaterThan(0, $metrics['D']);
    }

    /**
     * D=0 не даёт деления на ноль
     */
    public function testDcRatioZeroCompression(): void
    {
        $db = Database::get();
        $db->exec("DELETE FROM laws WHERE domain LIKE 'test_reg%'");
        $db->exec("DELETE FROM grammar_ops WHERE source = 'test_reg'");

        $metrics = $this->computeDCRatio();
        $this->assertGreaterThanOrEqual(0, $metrics['ratio']);
        $this->assertEquals(1, $metrics['C'], 'C clamped to 1 minimum');
    }

    // ═══ 2. FIDELITY ═══

    /**
     * Fidelity = доля надёжных законов
     */
    public function testComputeFidelity(): void
    {
        $db = Database::get();
        // Надёжные
        $db->prepare('INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['test_reg_a1', 'add', 0.0, 'test_reg_arith']);
        $db->prepare('INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['test_reg_a2', 'sqrt', 0.0, 'test_reg_arith']);
        // Ненадёжные
        $db->prepare('INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['test_reg_g1', 'abs(sub)', 0.0, 'test_reg_gen']);
        $db->prepare('INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['test_reg_g2', 'sq(add)', 0.0, 'test_reg_gen']);
        $db->prepare('INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['test_reg_g3', 'mul(min)', 0.0, 'test_reg_gen']);

        $fidelity = $this->computeFidelity();
        $this->assertEqualsWithDelta(0.4, $fidelity, 0.01, '2/5 reliable = 0.4');
    }

    /**
     * Пустая БД — fidelity = 1 (всё что есть — надёжно)
     */
    public function testFidelityEmptyDb(): void
    {
        $db = Database::get();
        $db->exec("DELETE FROM laws WHERE domain LIKE 'test_reg%'");

        $fidelity = $this->computeFidelity();
        $this->assertEquals(1.0, $fidelity, 'Empty DB = max fidelity');
    }

    // ═══ 3. СВЯЗЬ D/C ↔ FIDELITY ═══

    /**
     * Высокий D/C → низкая fidelity
     */
    public function testHighDcLowFidelity(): void
    {
        $db = Database::get();
        // Много generated (D), мало arithmetic (C)
        for ($i = 0; $i < 10; $i++) {
            $db->prepare('INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
                ->execute(["test_reg_g{$i}", "gen{$i}", 0.0, 'test_reg_gen']);
        }
        $db->prepare('INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['test_reg_a1', 'add', 0.0, 'test_reg_arith']);

        $dc = $this->computeDCRatio();
        $fid = $this->computeFidelity();

        $this->assertGreaterThan(1.0, $dc['ratio'], 'High D/C');
        $this->assertLessThan(0.3, $fid, 'Low fidelity');
    }

    // ═══ HELPERS ═══

    private function computeDCRatio(): array
    {
        $db = Database::get();
        $generated = (int) $db->query("SELECT COUNT(*) FROM laws WHERE domain LIKE 'test_reg%'")
            ->fetchColumn();
        $atoms = (int) $db->query("SELECT COUNT(*) FROM grammar_ops WHERE source = 'test_reg'")
            ->fetchColumn();
        $D = $generated + $atoms;

        $composeLaws = (int) $db->query("SELECT COUNT(*) FROM laws WHERE formula LIKE '%(%' AND domain LIKE 'test_reg%' AND domain NOT LIKE 'test_reg_gen%'")
            ->fetchColumn();
        $C = max(1, $composeLaws);

        return [
            'D' => $D,
            'C' => $C,
            'ratio' => $D / $C,
        ];
    }

    private function computeFidelity(): float
    {
        $db = Database::get();
        $total = (int) $db->query("SELECT COUNT(*) FROM laws WHERE domain LIKE 'test_reg%'")
            ->fetchColumn();
        if ($total === 0) {
            return 1.0;
        }

        $reliable = (int) $db->query("SELECT COUNT(*) FROM laws WHERE domain IN ('test_reg_arith')")
            ->fetchColumn();
        return $reliable / $total;
    }
}
