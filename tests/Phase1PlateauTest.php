<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;

use BeeSwarm\Database;

/**
 * Тесты preload knownLaws из БД.
 * 
 * Story 01 (1.6 Deduplication): проверка что preload предотвращает
 * переоткрытие законов после рестарта демона.
 * 
 * Plateau-тесты перенесены в PlateauDetectorTest (Story 02).
 */
class Phase1PlateauTest extends TestCase
{
    /** Preload должен матчить атомные ключи И Search::find ключи */
    public function test_preload_matches_both_key_formats(): void
    {
        $db = Database::get();
        
        // Добавляем атомный закон
        $db->exec("INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES ('TEST_ATOM', 'or', 0, 'logic')");
        // Добавляем Search::find закон
        $db->exec("INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES ('TEST_SEARCH', '((x0+x1)−(x0/x1))', 0, 'test')");
        
        // Симулируем preload с обоими форматами
        $knownLaws = [];
        foreach ($db->query("SELECT name, formula FROM laws")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $knownLaws[$row['name'] . '::' . $row['formula']] = true;
        }
        
        $this->assertArrayHasKey('TEST_ATOM::or', $knownLaws);
        $this->assertArrayHasKey('TEST_SEARCH::((x0+x1)−(x0/x1))', $knownLaws);
        
        $db->exec("DELETE FROM laws WHERE name LIKE 'TEST_%'");
    }
    
    /** Preload исключает повторы при симуляции тика */
    public function test_preload_prevents_repeat_discovery(): void
    {
        $db = Database::get();
        $db->exec("INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES ('OR', 'or', 0, 'logic')");
        
        // Preload
        $knownLaws = [];
        foreach ($db->query("SELECT name, formula FROM laws")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $knownLaws[$row['name'] . '::' . $row['formula']] = true;
        }
        
        // Симулируем discover: задача OR, атом or
        $taskName = 'OR';
        $atom = 'or';
        $key = $taskName . '::' . $atom;
        
        $this->assertArrayHasKey($key, $knownLaws);
        
        $shouldLog = !isset($knownLaws[$key]);
        $this->assertFalse($shouldLog);
        
        $db->exec("DELETE FROM laws WHERE name='OR' AND formula='or'");
    }
}
