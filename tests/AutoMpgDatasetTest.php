<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Hive\DiscoveryEngine;

/**
 * Auto MPG Dataset (UCI, 1983) — загружен из auto+mpg.zip.
 * 398 записей. Предсказание mpg по horsepower, weight, displacement.
 *
 * Источник: https://archive.ics.uci.edu/dataset/9/auto+mpg
 */
class AutoMpgDatasetTest extends TestCase
{
    private array $X = [];
    private array $y = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadData();
    }

    private function loadData(): void
    {
        $file = __DIR__ . '/../auto-mpg.data';
        if (! file_exists($file)) {
            $this->markTestSkipped('auto-mpg.data not found. Download from UCI.');
        }
        foreach (file($file) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            // Формат: mpg cyl disp hp weight acc year origin name
            $parts = preg_split('/\s+/', $line, 9);
            if (count($parts) < 8) continue;
            $mpg    = (float) $parts[0];
            $disp   = (float) $parts[2];
            $hp     = (float) $parts[3];
            $weight = (float) $parts[4];
            if ($hp === 0.0) continue; // пропускаем missing HP (несколько записей)
            $this->X[] = [$hp, $weight, $disp];
            $this->y[] = $mpg;
        }
    }

    /**
     * Weight → MPG: обратная корреляция на реальных 398 записях.
     */
    public function testWeightAndHpPredictMpg(): void
    {
        $this->assertGreaterThan(100, count($this->X), 'Need 100+ data rows');
        $this->assertCount(count($this->X), $this->y);

        $engine = new DiscoveryEngine();
        $results = $engine->discover(
            $this->X, $this->y,
            array_merge(Grammar::baseOpNames(), ['add', 'sub', 'mul', 'div', 'min', 'max', 'abs', 'neg', 'sq', 'sqrt']),
            0.3,
            ['horsepower', 'weight', 'displacement'],
        );

        $this->assertNotEmpty($results, 'Auto MPG (398 rows) must yield laws');
        // Хотя бы один закон с CV ≤ 0.25
        $good = array_filter($results, fn ($r) => ($r['cv'] ?? 1.0) <= 0.25);
        $this->assertNotEmpty($good, 'At least one law with CV≤0.25');
    }
}
