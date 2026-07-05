<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\AtomRegistry;
use BeeSwarm\Database;

/**
 * Story 03b: Retrospective Data
 *
 * retrospectiveValidate() должен проверять ВСЕ законы,
 * включая foraged и generated.
 *
 * @group disabled
 */
class RetrospectiveDataTest extends TestCase
{
    /** retrospectiveValidate с полным набором задач */
    public function test_retrospective_with_foraged_tasks(): void
    {
        $db = Database::get();
        $db->exec("DELETE FROM laws WHERE name LIKE 'TEST_RETRO_%'");
        
        // Симулируем foraged-закон (как будто открыт без held-out)
        $db->prepare("INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")
           ->execute(['TEST_RETRO_FORAGED', 'add', 0, 'foraged']);
        
        // Таск с правильными данными (ADD-задача)
        $tasks = [
            ['name' => 'TEST_RETRO_FORAGED', 'data' => [[1,2,3],[3,4,7],[5,6,11],[7,8,15],[9,10,19],[11,12,23]], 'domain' => 'foraged'],
        ];
        
        $result = AtomRegistry::retrospectiveValidate($tasks);
        
        $this->assertContains('TEST_RETRO_FORAGED::add', $result['passed'],
            'add on ADD-data must pass retrospective');
        $this->assertEmpty($result['overfit'], 'No overfit for valid law');
        
        $db->exec("DELETE FROM laws WHERE name LIKE 'TEST_RETRO_%'");
    }
    
    /** Мусорный закон (abs на данных с выбросом) — overfit */
    public function test_garbage_law_detected_as_overfit(): void
    {
        $db = Database::get();
        $db->exec("DELETE FROM laws WHERE name LIKE 'TEST_RETRO_%'");
        
        // abs на identity-данных: train (0,1,2,3,4) → CV=0
        // но holdout содержит -1 → abs(-1)=1, y=-1 → CV_holdout >> 0.10
        $db->prepare("INSERT INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")
           ->execute(['TEST_RETRO_GARBAGE', 'abs', 0, 'arithmetic']);
        
        $data = [[0,0],[1,1],[2,2],[3,3],[4,4],[5,5],[-1,-1]];
        $tasks = [
            ['name' => 'TEST_RETRO_GARBAGE', 'data' => $data, 'domain' => 'arithmetic'],
        ];
        
        $result = AtomRegistry::retrospectiveValidate($tasks);
        
        // abs на [-1,-1]: abs(-1)=1, expected=-1 → CV≠0 → overfit
        $this->assertContains('TEST_RETRO_GARBAGE::abs', $result['overfit'],
            'abs on data with negatives must be overfit (train positive, holdout negative)');
        
        $exists = $db->query("SELECT COUNT(*) FROM laws WHERE name='TEST_RETRO_GARBAGE'")->fetchColumn();
        $this->assertSame(0, (int)$exists, 'Overfit law must be deleted from DB');
        
        $db->exec("DELETE FROM laws WHERE name LIKE 'TEST_RETRO_%'");
    }
}
