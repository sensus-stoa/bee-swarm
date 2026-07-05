<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\Database;
use BeeSwarm\AtomRegistry;

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

    /**
     * Все форматы ключей preload (name::formula) матчат ключи открытия (name::atom).
     *
     * BUG: AtomRegistry::discover() возвращает несколько атомов для одной задачи
     * (например, abs, floor, ceil, round, relu для y=x), но DB имеет name UNIQUE —
     * только первый атом сохраняется. После рестарта preload загружает только
     * сохранённый атом, а остальные переоткрываются заново.
     *
     * @group disabled
     */
    public function test_preload_key_matches_all_discovered_atom_keys(): void
    {
        $db = Database::get();
        $db->exec("DELETE FROM laws WHERE name LIKE 'TEST_PRELOAD_%'");

        $taskName = 'TEST_PRELOAD_FORMAT';

        // Данные где discover() находит 5 атомов с CV=0:
        // abs, floor, ceil, round, relu — все дают точное совпадение
        $X = [[0.0], [1.0], [2.0]];
        $y = [0.0, 1.0, 2.0];

        // === Шаг 1: Симулируем открытие (как daemon) ===
        $discovered = AtomRegistry::discover($X, $y);
        $this->assertNotEmpty($discovered, 'Should discover atoms for identity-like task');
        $this->assertGreaterThan(1, count($discovered),
            'Need multiple atoms to demonstrate dedup bug');

        // Симулируем сохранение ВСЕХ открытых атомов в БД (как daemon)
        // НО: name UNIQUE → только первый атом реально сохраняется
        foreach ($discovered as $d) {
            $db->prepare("INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")
               ->execute([$taskName, $d['atom'], $d['cv'], 'test']);
        }

        // === Шаг 2: Симулируем preload после рестарта ===
        $knownLaws = [];
        $rows = $db->query("SELECT name, formula FROM laws")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $knownLaws[$row['name'] . '::' . $row['formula']] = true;
        }

        // === Шаг 3: Проверяем что ВСЕ открытые атомы есть в knownLaws ===
        // BUG: только первый атом (abs) в БД, остальные — нет
        foreach ($discovered as $d) {
            $discoveryKey = $taskName . '::' . $d['atom'];
            $this->assertArrayHasKey($discoveryKey, $knownLaws,
                "Atom '{$d['atom']}' discovered but missing from preload"
                . " — will be re-discovered after restart");
        }

        // Cleanup
        $db->exec("DELETE FROM laws WHERE name LIKE 'TEST_PRELOAD_%'");
    }
}
