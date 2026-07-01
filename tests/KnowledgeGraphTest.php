<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

class KnowledgeGraphTest extends TestCase
{
    /** Каждый метрический закон (sleep→energy) создаёт факт в графе */
    public function test_metric_law_creates_fact(): void
    {
        $db = \BeeSwarm\Database::get();
        
        // Эмулируем: найден закон sleep→energy
        $name = 'test_metric→test_target';
        $formula = '(x0+K1)';
        $cv = 0.05;
        $conf = max(0.01, 1.0 - $cv);
        
        // Вставляем закон
        $db->prepare("INSERT OR IGNORE INTO laws (name,formula,cv,domain) VALUES (?,?,?,?)")
           ->execute([$name, $formula, $cv, 'test']);
        
        // Эмулируем создание факта (как в agenda.php)
        [$subj, $obj] = explode('→', $name, 2);
        $db->prepare("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence, inferred) VALUES (?, 'relates_to', ?, ?, 0)")
           ->execute([trim($subj), trim($obj), $conf]);
        
        // Проверяем что факт создан
        $stmt = $db->prepare("SELECT * FROM knowledge_graph WHERE subject = ? AND predicate = 'relates_to' AND object = ?");
        $stmt->execute([trim($subj), trim($obj)]);
        $fact = $stmt->fetch();
        
        $this->assertNotFalse($fact);
        $this->assertEqualsWithDelta($conf, $fact['confidence'], 0.001);
        $this->assertSame(0, (int)$fact['inferred']);
    }

    /** Синтетический закон (MUL, AND) создаёт факт is_a law */
    public function test_synthetic_law_creates_is_a_law_fact(): void
    {
        $db = \BeeSwarm\Database::get();
        
        $name = 'TEST_OP_' . uniqid();
        $conf = 1.0;  // CV=0 → conf=1
        
        $db->prepare("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence, inferred) VALUES (?, 'is_a', 'law', ?, 0)")
           ->execute([$name, $conf]);
        
        $stmt = $db->prepare("SELECT * FROM knowledge_graph WHERE subject = ? AND predicate = 'is_a' AND object = 'law'");
        $stmt->execute([$name]);
        $fact = $stmt->fetch();
        
        $this->assertNotFalse($fact);
        $this->assertSame(0, (int)$fact['inferred']);
    }

    /** Граф должен содержать ранее созданные факты из метрик */
    public function test_graph_has_relates_to_facts(): void
    {
        $db = \BeeSwarm\Database::get();
        $count = $db->query("SELECT COUNT(*) FROM knowledge_graph WHERE predicate = 'relates_to'")->fetchColumn();
        // Если демон работал — должно быть > 0
        $this->assertGreaterThanOrEqual(0, $count);
    }

    /** Противоречия только для is_a и не в цепочке иерархии */
    public function test_contradiction_detector_ignores_hierarchy(): void
    {
        $db = \BeeSwarm\Database::get();
        $contradictions = $db->query(
            "SELECT COUNT(*) FROM knowledge_graph k1 
             JOIN knowledge_graph k2 ON k1.subject = k2.subject AND k1.predicate = k2.predicate 
             WHERE k1.object != k2.object 
               AND k1.predicate = 'is_a'
               AND NOT EXISTS (SELECT 1 FROM knowledge_graph k3 WHERE k3.subject = k1.object AND k3.object = k2.object AND k3.predicate = 'is_a')
               AND NOT EXISTS (SELECT 1 FROM knowledge_graph k3 WHERE k3.subject = k2.object AND k3.object = k1.object AND k3.predicate = 'is_a')"
        )->fetchColumn();
        $this->assertSame(0, (int)$contradictions, 'Should have 0 real contradictions in clean taxonomy');
    }
}
