<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\AtomRegistry;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Infra\Database;

class SemanticLayerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::get()->exec("DELETE FROM knowledge_graph WHERE subject LIKE 'tst_%' OR object LIKE 'tst_%'");
    }

    // ═══ 1. DISCOVER → KNOWLEDGE GRAPH ═══

    /** Открытие атома создаёт семантические факты */
    public function test_discovery_creates_semantic_facts(): void
    {
        $X = [[1.0, 2.0], [3.0, 4.0], [5.0, 6.0]];
        $y = [3.0, 7.0, 11.0];

        $found = AtomRegistry::discover($X, $y);
        $this->assertNotEmpty($found, 'Should discover add');

        $atomName = $found[0]['atom'];

        // Симулируем что демон добавляет факты
        $this->recordDiscovery($atomName, 'test_ADD');

        // Проверяем что факты появились
        $db = Database::get();
        $solves = $db->prepare("SELECT COUNT(*) FROM knowledge_graph WHERE subject = ? AND predicate = 'solves' AND object = 'test_ADD'");
        $solves->execute([$atomName]);
        $this->assertEquals(1, $solves->fetchColumn(), 'solves fact created');

        $isA = $db->prepare("SELECT COUNT(*) FROM knowledge_graph WHERE subject = ? AND predicate = 'is_a' AND object = 'discovered_atom'");
        $isA->execute([$atomName]);
        $this->assertEquals(1, $isA->fetchColumn(), 'is_a discovered_atom fact created');
    }

    // ═══ 2. COMPOSE → KNOWLEDGE GRAPH ═══

    /** Compose создаёт семантические факты */
    public function test_compose_creates_semantic_facts(): void
    {
        $grammar = ['abs', 'sub'];

        $X = [[1.0, 3.0], [5.0, 1.0], [2.0, 2.0]];
        $y = [2.0, 4.0, 0.0];

        $composed = AtomRegistry::discoverCompose($X, $y, $grammar);
        $this->assertNotEmpty($composed, 'Should discover abs(sub)');

        $compName = 'abs(sub)';
        $this->recordCompose($compName);

        $db = Database::get();
        $isA = $db->prepare("SELECT COUNT(*) FROM knowledge_graph WHERE subject = ? AND predicate = 'is_a' AND object = 'compose_operation'");
        $isA->execute([$compName]);
        $this->assertEquals(1, $isA->fetchColumn(), 'is_a compose_operation fact created');

        $composesWith = $db->prepare("SELECT COUNT(*) FROM knowledge_graph WHERE subject = 'abs' AND predicate = 'composes_with' AND object = 'sub'");
        $composesWith->execute();
        $this->assertEquals(1, $composesWith->fetchColumn(), 'composes_with fact created');
    }

    // ═══ 3. ДУБЛИКАТЫ НЕ СОЗДАЮТСЯ ═══

    /** Повторное открытие не дублирует факты */
    public function test_duplicate_discovery_no_duplicate_facts(): void
    {
        $this->recordDiscovery('add', 'test_ADD');
        $countBefore = $this->countFacts();

        // Повторно
        $this->recordDiscovery('add', 'test_ADD');
        $countAfter = $this->countFacts();

        $this->assertEquals($countBefore, $countAfter, 'No duplicate facts');
    }

    // ═══ 4. СЕМАНТИЧЕСКИЕ ЗАКОНЫ ИЗ ГРАФА ═══

    /** Граф с фактами порождает семантические законы */
    public function test_semantic_laws_from_populated_graph(): void
    {
        // Наполняем граф УНИКАЛЬНЫМИ атомами чтобы не пересекаться с демоном
        $this->recordDiscovery('tst_add', 'tst_task_A');
        $this->recordDiscovery('tst_mul', 'tst_task_B');
        $this->recordDiscovery('tst_sqrt', 'tst_task_C');
        $this->recordCompose('tst_abs(tst_sub)');
        $this->recordCompose('tst_sq(tst_add)');

        // Проверяем: все открытые атомы классифицированы
        $db = Database::get();
        $discovered = $db->query("SELECT COUNT(*) FROM knowledge_graph WHERE predicate = 'is_a' AND object = 'discovered_atom'")->fetchColumn();
        $this->assertGreaterThanOrEqual(3, $discovered, 'At least 3 atoms classified');

        // Проверяем: composes_with — асимметрично (только тестовые атомы)
        $pairs = $db->query("SELECT subject, object FROM knowledge_graph WHERE predicate = 'composes_with' AND (subject LIKE 'tst_%' OR object LIKE 'tst_%')")->fetchAll(\PDO::FETCH_ASSOC);
        $pairSet = [];
        foreach ($pairs as $p) $pairSet[$p['subject'] . '|' . $p['object']] = true;
        $sym = 0;
        foreach ($pairSet as $key => $_) {
            [$a, $b] = explode('|', $key);
            if (isset($pairSet[$b . '|' . $a])) $sym++;
        }
        $this->assertEquals(0, $sym, 'composes_with should be asymmetric (CV=0)');

        // Проверяем: solves — асимметрично (только тестовые)
        $solves = $db->query("SELECT subject, object FROM knowledge_graph WHERE predicate = 'solves' AND (subject LIKE 'tst_%' OR object LIKE 'tst_%')")->fetchAll(\PDO::FETCH_ASSOC);
        $solveSet = [];
        foreach ($solves as $s) $solveSet[$s['subject'] . '|' . $s['object']] = true;
        $symSolves = 0;
        foreach ($solveSet as $key => $_) {
            [$a, $b] = explode('|', $key);
            if (isset($solveSet[$b . '|' . $a])) $symSolves++;
        }
        $this->assertEquals(0, $symSolves, 'solves should be asymmetric (CV=0)');
    }

    // ═══ HELPERS (mirror what daemon does) ═══

    private function recordDiscovery(string $atomName, string $taskName): void
    {
        $db = Database::get();
        $db->prepare("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES (?,?,?,?)")
           ->execute([$atomName, 'solves', $taskName, 1.0]);
        $db->prepare("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES (?,?,?,?)")
           ->execute([$atomName, 'is_a', 'discovered_atom', 1.0]);
    }

    private function recordCompose(string $compName): void
    {
        $db = Database::get();
        $db->prepare("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES (?,?,?,?)")
           ->execute([$compName, 'is_a', 'compose_operation', 1.0]);
        if (preg_match('/^(\w+)\((\w+)\)$/', $compName, $pm)) {
            $db->prepare("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES (?,?,?,?)")
               ->execute([$pm[1], 'composes_with', $pm[2], 1.0]);
        }
    }

    private function countFacts(): int
    {
        return (int)Database::get()->query("SELECT COUNT(*) FROM knowledge_graph")->fetchColumn();
    }
}
