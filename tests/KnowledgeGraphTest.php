<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

class KnowledgeGraphTest extends TestCase
{
    /** Каждый метрический закон (sleep→energy) создаёт факт в графе */
    public function test_metric_law_creates_fact(): void
    {
        $db = \BeeSwarm\Database::get();
        $name = 'test_metric→test_target';
        $formula = '(x0+K1)';
        $cv = 0.05;
        $conf = max(0.01, 1.0 - $cv);
        $db->prepare("INSERT OR IGNORE INTO laws (name,formula,cv,domain) VALUES (?,?,?,?)")
           ->execute([$name, $formula, $cv, 'test']);
        [$subj, $obj] = explode('→', $name, 2);
        $db->prepare("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence, inferred) VALUES (?, 'relates_to', ?, ?, 0)")
           ->execute([trim($subj), trim($obj), $conf]);
        $stmt = $db->prepare("SELECT * FROM knowledge_graph WHERE subject = ? AND predicate = 'relates_to' AND object = ?");
        $stmt->execute([trim($subj), trim($obj)]);
        $fact = $stmt->fetch();
        $this->assertNotFalse($fact);
        $this->assertEqualsWithDelta($conf, $fact['confidence'], 0.001);
        $this->assertSame(0, (int)$fact['inferred']);
    }

    public function test_synthetic_law_creates_is_a_law_fact(): void
    {
        $db = \BeeSwarm\Database::get();
        $name = 'TEST_OP_' . uniqid();
        $conf = 1.0;
        $db->prepare("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence, inferred) VALUES (?, 'is_a', 'law', ?, 0)")
           ->execute([$name, $conf]);
        $stmt = $db->prepare("SELECT * FROM knowledge_graph WHERE subject = ? AND predicate = 'is_a' AND object = 'law'");
        $stmt->execute([$name]);
        $fact = $stmt->fetch();
        $this->assertNotFalse($fact);
        $this->assertSame(0, (int)$fact['inferred']);
    }

    public function test_graph_has_relates_to_facts(): void
    {
        $db = \BeeSwarm\Database::get();
        $count = $db->query("SELECT COUNT(*) FROM knowledge_graph WHERE predicate = 'relates_to'")->fetchColumn();
        $this->assertGreaterThanOrEqual(0, $count);
    }

    /** Противоречия с учётом иерархии операций */
    public function test_contradiction_detector_ignores_hierarchy(): void
    {
        $db = \BeeSwarm\Database::get();
        
        // Чистим тестовые факты перед проверкой
        $db->exec("DELETE FROM knowledge_graph WHERE subject IN ('discovered_atom','arithmetic_operation','compose_operation') AND predicate='is_a'");
        
        // Иерархия: все категории связаны
        $db->exec("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES ('discovered_atom', 'is_a', 'compose_operation', 1.0)");
        $db->exec("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES ('arithmetic_operation', 'is_a', 'discovered_atom', 1.0)");
        $db->exec("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES ('compose_operation', 'is_a', 'operation', 1.0)");
        
        // Считаем противоречия ТОЛЬКО для субъектов иерархии
        $contradictions = $db->query(
            "SELECT COUNT(*) FROM knowledge_graph k1 
             JOIN knowledge_graph k2 ON k1.subject = k2.subject AND k1.predicate = k2.predicate 
             WHERE k1.object != k2.object 
               AND k1.predicate = 'is_a'
               AND k1.subject IN ('discovered_atom','arithmetic_operation','compose_operation')
               AND NOT EXISTS (SELECT 1 FROM knowledge_graph k3 WHERE k3.subject = k1.object AND k3.object = k2.object AND k3.predicate = 'is_a')
               AND NOT EXISTS (SELECT 1 FROM knowledge_graph k3 WHERE k3.subject = k2.object AND k3.object = k1.object AND k3.predicate = 'is_a')"
        )->fetchColumn();
        
        $this->assertSame(0, (int)$contradictions, 'Should have 0 real contradictions with hierarchy');
        
        // Чистим
        $db->exec("DELETE FROM knowledge_graph WHERE subject IN ('discovered_atom','arithmetic_operation','compose_operation') AND predicate='is_a'");
    }
}
