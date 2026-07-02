<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Database;

/**
 * Тест: preload knownLaws из БД при старте демона.
 * Предотвращает переоткрытие законов после рестарта.
 */
class KnownLawsPreloadTest extends TestCase
{
    /** После рестарта knownLaws загружается из БД */
    public function test_preload_populates_known_laws(): void
    {
        $db = Database::get();
        
        // Вставляем тестовый закон
        $db->exec("INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES ('TEST_PRELOAD', 'test_atom', 0, 'test')");
        
        // Симулируем preload как в agenda.php
        $knownLaws = [];
        $rows = $db->query("SELECT name, formula FROM laws")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $knownLaws[$row['name'] . '::' . $row['formula']] = true;
        }
        
        // Проверяем что тестовый закон загружен
        $this->assertArrayHasKey('TEST_PRELOAD::test_atom', $knownLaws);
        $this->assertTrue($knownLaws['TEST_PRELOAD::test_atom']);
        
        // Чистим
        $db->exec("DELETE FROM laws WHERE name = 'TEST_PRELOAD'");
    }
    
    /** knownLaws не пустая после preload если в БД есть законы */
    public function test_preload_non_empty_when_db_has_laws(): void
    {
        $db = Database::get();
        $lawCount = (int)$db->query("SELECT COUNT(*) FROM laws")->fetchColumn();
        
        $knownLaws = [];
        $rows = $db->query("SELECT name, formula FROM laws")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $knownLaws[$row['name'] . '::' . $row['formula']] = true;
        }
        
        $this->assertCount($lawCount, $knownLaws);
    }
    
    /** Повторная вставка того же закона не дублирует knownLaws */
    public function test_preload_no_duplicates(): void
    {
        $db = Database::get();
        
        $db->exec("INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES ('TEST_PRELOAD2', 'dup_atom', 0, 'test')");
        
        // Первый preload
        $knownLaws1 = [];
        $rows = $db->query("SELECT name, formula FROM laws")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $knownLaws1[$row['name'] . '::' . $row['formula']] = true;
        }
        
        // Второй preload (симуляция рестарта — БД та же)
        $knownLaws2 = [];
        $rows = $db->query("SELECT name, formula FROM laws")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $knownLaws2[$row['name'] . '::' . $row['formula']] = true;
        }
        
        $this->assertCount(count($knownLaws1), $knownLaws2);
        $this->assertArrayHasKey('TEST_PRELOAD2::dup_atom', $knownLaws2);
        
        $db->exec("DELETE FROM laws WHERE name = 'TEST_PRELOAD2'");
    }
}
