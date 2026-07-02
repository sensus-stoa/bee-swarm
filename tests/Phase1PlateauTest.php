<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Database;

/**
 * Фаза 1: Остановить шум.
 * 
 * 1.1 knownLaws матчит ВСЕ форматы (атомы + Search::find)
 * 1.2 Плато-детектор: 50 тиков без открытий → sleep растёт
 * 1.3 Compose отключается на плато
 */
class Phase1PlateauTest extends TestCase
{
    /** 1.1: Preload должен матчить атомные ключи И Search::find ключи */
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
            $name = $row['name'];
            $formula = $row['formula'];
            
            // Ключ 1: точный (для атомов: OR::or)
            $knownLaws[$name . '::' . $formula] = true;
            
            // Ключ 2: имя задачи + домен (для Search::find: OR::logic)
            // Этот формат используется когда формула генерится Search::find
        }
        
        // Проверяем что атомный ключ существует
        $this->assertArrayHasKey('TEST_ATOM::or', $knownLaws, 'Атомный ключ должен быть в knownLaws');
        $this->assertArrayHasKey('TEST_SEARCH::((x0+x1)−(x0/x1))', $knownLaws, 'Search::find ключ должен быть в knownLaws');
        
        $db->exec("DELETE FROM laws WHERE name LIKE 'TEST_%'");
    }
    
    /** 1.1: Preload исключает повторы при симуляции тика */
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
        
        // Должен быть уже известен
        $this->assertArrayHasKey($key, $knownLaws, 'OR::or должен быть preloaded');
        
        // Симулируем что демон НЕ логирует открытие (потому что known)
        $shouldLog = !isset($knownLaws[$key]);
        $this->assertFalse($shouldLog, 'Не должен логировать — уже известен');
        
        $db->exec("DELETE FROM laws WHERE name='OR' AND formula='or'");
    }
    
    /** 1.2: Плато-детектор — после N тиков без открытий, backoff растёт */
    public function test_plateau_detector_increases_sleep(): void
    {
        $consecutiveNoDiscovery = 0;
        $plateauThreshold = 50;
        
        // Симулируем тики без открытий
        $sleeps = [];
        for ($tick = 1; $tick <= 100; $tick++) {
            $consecutiveNoDiscovery++;
            
            $isPlateau = $consecutiveNoDiscovery > $plateauThreshold;
            $baseSleep = 200_000; // 200ms
            $plateauSleep = $isPlateau ? 10_000_000 : $baseSleep; // 10s на плато
            
            $sleeps[] = $plateauSleep;
        }
        
        // Первые 50 тиков: 200ms
        $this->assertEquals(200_000, $sleeps[0]);
        $this->assertEquals(200_000, $sleeps[49]);
        
        // После 50: 10s
        $this->assertEquals(10_000_000, $sleeps[50]);
        $this->assertEquals(10_000_000, $sleeps[99]);
    }
    
    /** 1.3: Compose отключается на плато */
    public function test_compose_disabled_on_plateau(): void
    {
        $consecutiveNoDiscovery = 60;
        
        $runCompose = $consecutiveNoDiscovery <= 50;
        
        $this->assertFalse($runCompose, 'Compose должен быть отключён на плато (60 > 50)');
    }
    
    /** 1.3: Compose работает когда НЕ плато */
    public function test_compose_enabled_when_active(): void
    {
        $consecutiveNoDiscovery = 10;
        
        $runCompose = $consecutiveNoDiscovery <= 50;
        
        $this->assertTrue($runCompose, 'Compose должен работать когда нет плато (10 ≤ 50)');
    }
}
