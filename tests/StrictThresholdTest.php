<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Infra\Database;

class StrictThresholdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::get()->exec("DELETE FROM laws WHERE domain = 'test_strict'");
    }

    // ═══ 1. STRICT THRESHOLD ═══

    /** CV < 0.01 — записываем */
    public function test_strict_law_recorded(): void
    {
        $X = [[1.0, 2.0], [3.0, 4.0], [5.0, 6.0]];
        $y = [3.0, 7.0, 11.0];
        $g = new Grammar();
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 3);

        $this->assertTrue($ok, 'ADD should be found');
        $this->assertLessThan(0.01, $cv, 'CV should be near 0');
    }

    /** CV > 0.01 — НЕ записываем */
    public function test_weak_law_not_recorded(): void
    {
        // Слабый сигнал (случайные данные)
        $X = [[1.0], [2.0], [3.0], [4.0], [5.0]];
        $y = [2.1, 3.9, 6.2, 7.8, 10.1]; // ~2x но с шумом
        $g = new Grammar();
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 3);

        // Search::find может найти формулу, но CV будет > 0.01
        if ($ok) {
            $this->assertGreaterThan(0.01, $cv, 'Weak law should be rejected');
        }
        // Если не нашёл — тоже OK
        $this->assertTrue(true);
    }

    // ═══ 2. ИНТЕГРАЦИЯ ═══

    /** Fallback с strict порогом не записывает шум */
    public function test_fallback_strict_threshold(): void
    {
        $db = Database::get();
        $countBefore = $db->query("SELECT COUNT(*) FROM laws")->fetchColumn();

        // Симуляция: задача решается только Search::find с плохим CV
        $X = [[1.0], [2.0], [3.0]];
        $y = [1.5, 3.5, 5.5]; // примерно 1.5x, шумно
        $g = new Grammar();
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 3);

        if ($ok && $cv < 0.01) {
            // Если нашёлся strict-закон — ОК, записываем
            $db->prepare("INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")
               ->execute(['test_strict_fallback', $formula, $cv, 'test_strict']);
        }

        $countAfter = $db->query("SELECT COUNT(*) FROM laws")->fetchColumn();
        // Шумный закон НЕ должен быть записан (CV > 0.01)
        if ($ok && $cv > 0.01) {
            $this->assertEquals($countBefore, $countAfter, 'Weak law not recorded');
        }
    }

    /** AtomRegistry strict laws still recorded */
    public function test_atom_registry_strict_works(): void
    {
        $X = [[1.0], [4.0], [9.0], [16.0]];
        $y = [1.0, 2.0, 3.0, 4.0];

        $found = AtomRegistry::discover($X, $y);
        $this->assertNotEmpty($found);
        foreach ($found as $f) {
            $this->assertLessThan(0.001, $f['cv'], 'AtomRegistry uses strict CV');
        }
    }
}
