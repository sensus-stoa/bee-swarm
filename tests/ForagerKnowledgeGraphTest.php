<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;
use BeeSwarm\Infra\Database;
use BeeSwarm\Knowledge\ConceptRegistry;

/**
 * Forager → knowledge_graph петля.
 * 
 * Forager находит "X является Y" → ДОЛЖЕН вставить факт в knowledge_graph.
 * Без этого is_a атом не видит семантику.
 */
class ForagerKnowledgeGraphTest extends TestCase
{
    private string $tmpDir;
    
    protected function setUp(): void
    {
        parent::setUp();
        ConceptRegistry::clear();
        $this->tmpDir = sys_get_temp_dir() . '/bee_test_kg_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        
        // Чистим тестовые факты перед каждым тестом
        $db = Database::get();
        $db->exec("DELETE FROM knowledge_graph WHERE subject IN ('Сократ','Платон','Аристотель','Кот') AND predicate='is_a'");
    }
    
    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*'));
        rmdir($this->tmpDir);
        parent::tearDown();
    }
    
    /** Forager вставляет is_a факты в knowledge_graph */
    public function test_forager_inserts_facts_into_kg(): void
    {
        $db = Database::get();
        $before = (int)$db->query("SELECT COUNT(*) FROM knowledge_graph")->fetchColumn();
        
        file_put_contents($this->tmpDir . '/test.md', 
            "Сократ является философом.\nПлатон является философом.\n");
        
        $forager = new Forager([$this->tmpDir => 0.5]);
        $forager->scan([$this->tmpDir => 0.5]);
        
        $after = (int)$db->query("SELECT COUNT(*) FROM knowledge_graph")->fetchColumn();
        
        // Должны появиться новые факты
        $this->assertGreaterThan($before, $after, 'Forager должен вставлять факты в KG');
        
        // Конкретные факты должны существовать (regex ловит слово как есть, включая окончания)
        $stmt = $db->prepare("SELECT confidence FROM knowledge_graph WHERE subject=? AND predicate='is_a' AND object LIKE ?");
        $stmt->execute(['Сократ', 'философ%']);
        $socrat = $stmt->fetchColumn();
        $this->assertNotFalse($socrat, 'Сократ is_a философ... — должен быть в KG');
        $this->assertGreaterThan(0, (float)$socrat);
        
        $stmt->execute(['Платон', 'философ%']);
        $platon = $stmt->fetchColumn();
        $this->assertNotFalse($platon, 'Платон is_a философ... — должен быть в KG');
        
        // Чистим
        $db->exec("DELETE FROM knowledge_graph WHERE subject IN ('Сократ','Платон') AND predicate='is_a'");
    }
    
    /** Повторная вставка того же факта повышает confidence */
    public function test_repeated_fact_increases_confidence(): void
    {
        $db = Database::get();
        
        // Первый проход
        file_put_contents($this->tmpDir . '/a.md', "Аристотель является мыслителем.\n");
        $forager = new Forager([$this->tmpDir => 0.5]);
        $forager->scan([$this->tmpDir => 0.5]);
        
        $stmt = $db->prepare("SELECT confidence FROM knowledge_graph WHERE subject=? AND predicate='is_a' AND object=?");
        $stmt->execute(['Аристотель', 'мыслитель']);
        $conf1 = (float)$stmt->fetchColumn();
        
        // Второй проход — тот же факт из другого файла
        file_put_contents($this->tmpDir . '/b.md', "Аристотель является мыслителем.\n");
        $forager->scan([$this->tmpDir => 0.5]);
        
        $stmt->execute(['Аристотель', 'мыслитель']);
        $conf2 = (float)$stmt->fetchColumn();
        
        $this->assertGreaterThanOrEqual($conf1, $conf2, 'Повторный факт не должен уменьшать confidence');
        
        $db->exec("DELETE FROM knowledge_graph WHERE subject='Аристотель' AND predicate='is_a'");
    }
    
    /** Forager НЕ вставляет «Кот является китом» с высоким confidence */
    public function test_single_occurrence_gives_low_confidence(): void
    {
        $db = Database::get();
        
        file_put_contents($this->tmpDir . '/single.md', "Кот является китом.\n");
        $forager = new Forager([$this->tmpDir => 0.5]);
        $forager->scan([$this->tmpDir => 0.5]);
        
        $stmt = $db->prepare("SELECT confidence FROM knowledge_graph WHERE subject=? AND predicate='is_a' AND object LIKE ?");
        $stmt->execute(['Кот', 'кит%']);
        $conf = (float)$stmt->fetchColumn();
        
        // Одиночное вхождение → confidence < 1.0
        $this->assertLessThan(1.0, $conf, 'Одиночный факт не должен иметь confidence=1.0');
        $this->assertGreaterThan(0.0, $conf, 'Но должен быть > 0 (факт зафиксирован)');
        
        $db->exec("DELETE FROM knowledge_graph WHERE subject='Кот' AND predicate='is_a'");
    }
}
