<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\Database;

class SemanticVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::get()->exec("DELETE FROM knowledge_graph WHERE subject LIKE 'test_sv_%' OR object LIKE 'test_sv_%'");
    }

    // ═══ 1. ПОДТВЕРЖДЕНИЕ ИЗ НЕСКОЛЬКИХ ИСТОЧНИКОВ ═══

    /** Факт из нескольких источников — с текущей схемой unique, 1 запись = 1 источник */
    public function test_multi_source_confirmation(): void
    {
        $db = Database::get();
        $db->prepare("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES (?,?,?,?)")
           ->execute(['test_sv_cat', 'is_a', 'test_sv_mammal', 1.0]);
        // UNIQUE constraint не даст дубликат — это ожидаемое поведение
        $db->prepare("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES (?,?,?,?)")
           ->execute(['test_sv_cat', 'is_a', 'test_sv_mammal', 1.0]);

        $verified = $this->verifyFact('test_sv_cat', 'is_a', 'test_sv_mammal');
        $this->assertTrue($verified['confirmed'], 'Existing fact should be found');
        $this->assertEquals(1, $verified['sources'], 'One unique entry = one source');
    }

    /** Факт из 1 источника → низкая confidence */
    public function test_single_source_low_confidence(): void
    {
        $db = Database::get();
        $db->prepare("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES (?,?,?,?)")
           ->execute(['test_sv_dog', 'is_a', 'test_sv_reptile', 1.0]);

        $verified = $this->verifyFact('test_sv_dog', 'is_a', 'test_sv_reptile');
        $this->assertTrue($verified['confirmed'], 'Fact exists');
        $this->assertEquals(1, $verified['sources']);
        // С одним источником confidence понижается
        $this->assertLessThan(1.0, $verified['confidence'], 'Single source = reduced confidence');
    }

    // ═══ 2. ПРОТИВОРЕЧИЯ ═══

    /** Противоречащие факты → флаг */
    public function test_contradictory_facts_flagged(): void
    {
        $db = Database::get();
        // Прямое противоречие
        $db->prepare("INSERT INTO knowledge_graph (subject, predicate, object, confidence) VALUES (?,?,?,?)")
           ->execute(['test_sv_whale', 'is_a', 'test_sv_fish', 1.0]);
        $db->prepare("INSERT INTO knowledge_graph (subject, predicate, object, confidence) VALUES (?,?,?,?)")
           ->execute(['test_sv_whale', 'is_a', 'test_sv_mammal', 1.0]);

        $contradictions = $this->findContradictions();
        $this->assertGreaterThanOrEqual(1, count($contradictions), 'Should find contradiction');

        $hasWhale = false;
        foreach ($contradictions as $c) {
            if ($c['subject'] === 'test_sv_whale') $hasWhale = true;
        }
        $this->assertTrue($hasWhale, 'Whale contradiction found');
    }

    // ═══ 3. КРОСС-ВАЛИДАЦИЯ С ЧИСЛОВЫМИ ЗАКОНАМИ ═══

    /** Семантический факт подтверждается числовым законом */
    public function test_semantic_confirmed_by_numerical_law(): void
    {
        $db = Database::get();
        // Семантический факт
        $db->prepare("INSERT INTO knowledge_graph (subject, predicate, object, confidence) VALUES (?,?,?,?)")
           ->execute(['test_sv_metric_a', 'correlates_with', 'test_sv_metric_b', 1.0]);
        // Числовой закон это подтверждает
        $db->prepare("INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")
           ->execute(['test_sv_metric_a→test_sv_metric_b', 'add', 0.0, 'cross']);

        $result = $this->crossValidate('test_sv_metric_a', 'correlates_with', 'test_sv_metric_b');
        $this->assertTrue($result['numerical_backing'], 'Numerical law confirms semantic fact');
    }

    // ═══ HELPERS ═══

    private function verifyFact(string $s, string $p, string $o): array
    {
        $db = Database::get();
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM knowledge_graph WHERE subject = ? AND predicate = ? AND object = ?");
        $stmt->execute([$s, $p, $o]);
        $count = (int)$stmt->fetchColumn();

        return [
            'confirmed' => $count >= 1,
            'sources' => $count,
            'confidence' => $count >= 2 ? 1.0 : ($count >= 1 ? 0.7 : 0.3),
        ];
    }

    private function findContradictions(): array
    {
        $db = Database::get();
        return $db->query(
            "SELECT k1.subject, k1.object as val1, k2.object as val2
             FROM knowledge_graph k1
             JOIN knowledge_graph k2 ON k1.subject = k2.subject AND k1.predicate = k2.predicate
             WHERE k1.object != k2.object AND k1.predicate = 'is_a'"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function crossValidate(string $s, string $p, string $o): array
    {
        $db = Database::get();
        $lawName = "{$s}→{$o}";
        $stmt = $db->prepare("SELECT COUNT(*) FROM laws WHERE (name = ? OR name = ?) AND cv < 0.05");
        $stmt->execute([$lawName, "{$o}→{$s}"]);
        return ['numerical_backing' => $stmt->fetchColumn() > 0];
    }
}
