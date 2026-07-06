<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;
use BeeSwarm\Infra\Database;

class SemanticFactFilterTest extends TestCase
{
    private string $tmpDir;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/bee_filt_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }
    
    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*'));
        rmdir($this->tmpDir);
        parent::tearDown();
    }
    
    /** Короткие слова не должны создавать is_a факты */
    public function test_short_words_filtered(): void
    {
        $db = Database::get();
        $before = (int)$db->query("SELECT COUNT(*) FROM knowledge_graph WHERE predicate='is_a'")->fetchColumn();
        
        file_put_contents($this->tmpDir . '/short.md', "он является им.\nты является мы.\n");
        
        $forager = new Forager([$this->tmpDir => 0.5]);
        $forager->scan([$this->tmpDir => 0.5]);
        
        $after = (int)$db->query("SELECT COUNT(*) FROM knowledge_graph WHERE predicate='is_a'")->fetchColumn();
        
        $this->assertEquals($before, $after, 'Короткие слова не должны создавать факты');
    }
    
    /** Стоп-слова не должны создавать факты */
    public function test_stopwords_filtered(): void
    {
        $db = Database::get();
        $before = (int)$db->query("SELECT COUNT(*) FROM knowledge_graph WHERE predicate='is_a'")->fetchColumn();
        
        file_put_contents($this->tmpDir . '/stop.md', "не является этим.\nто является тем.\n");
        
        $forager = new Forager([$this->tmpDir => 0.5]);
        $forager->scan([$this->tmpDir => 0.5]);
        
        $after = (int)$db->query("SELECT COUNT(*) FROM knowledge_graph WHERE predicate='is_a'")->fetchColumn();
        
        $this->assertEquals($before, $after, 'Стоп-слова не должны создавать факты');
    }
    
    /** Нормальные слова (>2 букв, не стоп-слова) — OK */
    public function test_valid_words_accepted(): void
    {
        $db = Database::get();
        $db->exec("DELETE FROM knowledge_graph WHERE subject='Сократ' AND predicate='is_a'");
        
        file_put_contents($this->tmpDir . '/valid.md', "Сократ является философом.\n");
        
        $forager = new Forager([$this->tmpDir => 0.5]);
        $forager->scan([$this->tmpDir => 0.5]);
        
        $stmt = $db->prepare("SELECT confidence FROM knowledge_graph WHERE subject=? AND predicate='is_a' AND object LIKE ?");
        $stmt->execute(['Сократ', 'философ%']);
        $conf = $stmt->fetchColumn();
        
        $this->assertNotFalse($conf, 'Сократ is_a философ... — должен быть в KG');
        $this->assertGreaterThan(0, (float)$conf);
        
        $db->exec("DELETE FROM knowledge_graph WHERE subject='Сократ' AND predicate='is_a'");
    }
}
