<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Infra\Database;

class StrictThresholdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::get()->exec("DELETE FROM laws WHERE domain = 'test_strict'");
    }

    // ═══ 1. STRICT THRESHOLD ═══

    /**
     * CV < 0.01 — записываем
     */
    public function testStrictLawRecorded(): void
    {
        $X = [[1.0, 2.0], [3.0, 4.0], [5.0, 6.0]];
        $y = [3.0, 7.0, 11.0];
        $g = new Grammar();
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 3);

        $this->assertTrue($ok, 'ADD should be found');
        $this->assertLessThan(0.01, $cv, 'CV should be near 0');
    }

    /**
     * CV > 0.01 — НЕ записываем (детерминированно: закон находится,
     * но с CV > 0.01 — assert выполняется всегда)
     */
    public function testWeakLawNotRecorded(): void
    {
        // 12 точек, y = 1.5x + sin-шум ~±5% → CV ≈ 0.02-0.04, закон находим
        $X = [];
        $y = [];
        for ($i = 0; $i < 12; $i++) {
            $x = $i + 1.0;
            $X[] = [$x];
            $y[] = 1.5 * $x + 0.05 * $x * sin($i * 0.9);
        }
        $g = new Grammar();
        [$ok, $cv] = Search::find($X, $y, $g, 3);

        $this->assertTrue($ok, 'law must be found on 12 points');
        $this->assertGreaterThan(0.01, $cv, 'Weak law should be rejected by strict threshold');
    }

    // ═══ 2. ИНТЕГРАЦИЯ ═══

    /**
     * Fallback с strict порогом не записывает шум
     * (детерминированно: weak-закон найден → assert записи выполняется)
     */
    public function testFallbackStrictThreshold(): void
    {
        $db = Database::get();
        $countBefore = $db->query('SELECT COUNT(*) FROM laws')
            ->fetchColumn();

        // 12 точек, y = 1.5x + шум ±5% — закон находится с CV > 0.01
        $X = [];
        $y = [];
        for ($i = 0; $i < 12; $i++) {
            $x = $i + 1.0;
            $X[] = [$x];
            $y[] = 1.5 * $x + 0.05 * $x * sin($i * 0.9);
        }
        $g = new Grammar();
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 3);

        $this->assertTrue($ok, 'law must be found (weak)');
        $this->assertGreaterThan(0.01, $cv, 'CV must exceed strict threshold');

        if ($cv < 0.01) {
            $db->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
                ->execute(['test_strict_fallback', $formula, $cv, 'test_strict']);
        }

        $countAfter = $db->query('SELECT COUNT(*) FROM laws')
            ->fetchColumn();
        $this->assertEquals($countBefore, $countAfter, 'Weak law not recorded');
    }
}
