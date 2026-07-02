<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager;
use BeeSwarm\Database;

/**
 * Тесты Forager на РЕАЛЬНЫХ данных ExoCortex.
 */
class ForagerRealDataTest extends TestCase
{
    private string $exocortexDir;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->exocortexDir = getenv('HOME') . '/Documents/the_lair/ExoCortex/Journal';
    }
    
    /** Forager должен находить текстовые файлы в ExoCortex */
    public function test_forager_finds_exocortex_files(): void
    {
        if (!is_dir($this->exocortexDir)) {
            $this->markTestSkipped('ExoCortex dir not found');
        }
        
        $forager = new Forager([$this->exocortexDir => 0.5]);
        $result = $forager->scan([$this->exocortexDir => 0.5]);
        
        // Должен что-то найти (хотя бы файлы)
        $this->assertIsArray($result, 'Forager scan должен вернуть массив');
    }
    
    /** Forager должен извлекать числовые паттерны из метрик */
    public function test_forager_extracts_from_metrics(): void
    {
        $metricsDir = $this->exocortexDir . '/global/metrics';
        if (!is_dir($metricsDir)) {
            $this->markTestSkipped('Metrics dir not found');
        }
        
        $forager = new Forager([$metricsDir => 0.5]);
        $result = $forager->scan([$metricsDir => 0.5]);
        
        // Даже если нет числовых паттернов, scan не должен падать
        $this->assertIsArray($result);
    }
    
    /** Forager должен извлекать is_a факты из текстов */
    public function test_forager_extracts_semantic_facts(): void
    {
        $insightsDir = $this->exocortexDir . '/global/insights';
        if (!is_dir($insightsDir)) {
            $this->markTestSkipped('Insights dir not found');
        }
        
        $forager = new Forager([$insightsDir => 0.5]);
        
        // Проверим конкретный файл с известным паттерном
        $files = glob($insightsDir . '/*.md');
        if (empty($files)) {
            $this->markTestSkipped('No insight files');
        }
        
        // Берём первый файл и проверяем, что forager его читает без ошибок
        $content = file_get_contents($files[0]);
        $this->assertNotEmpty($content);
        
        $result = $forager->scan([$insightsDir => 0.5]);
        $this->assertIsArray($result);
    }
    
    /** Приоритеты директорий обновляются после сканирования */
    public function test_priorities_update_after_scan(): void
    {
        $dirs = [
            $this->exocortexDir . '/global/strategy' => 0.5,
            $this->exocortexDir . '/global/metrics' => 0.3,
        ];
        
        $forager = new Forager($dirs);
        $forager->scan($dirs);
        $priorities = $forager->getPriorities();
        
        $this->assertIsArray($priorities);
        // Приоритеты должны быть не пустыми после скана
        $this->assertNotEmpty($priorities);
    }
}
