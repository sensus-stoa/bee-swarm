<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Hive\DiscoveryEngine;

/**
 * Wine Dataset (UCI, 1991) — загружен из wine.data.
 * 178 записей. 13 химических признаков.
 * Предсказание alcohol (колонка 1) по остальным признакам.
 */
class WineDatasetTest extends TestCase
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
        $file = __DIR__ . '/../wine.data';
        if (! file_exists($file)) {
            $this->markTestSkipped('wine.data not found. Download from UCI.');
        }
        // Формат: class, alcohol, malic_acid, ash, ...
        // Предсказываем alcohol (col 1) по cols 2-13
        foreach (file($file) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = explode(',', $line);
            if (count($parts) < 14) continue;
            $alcohol = (float) $parts[1];
            $features = [];
            for ($i = 2; $i <= 13; $i++) {
                $features[] = (float) $parts[$i];
            }
            $this->X[] = $features;
            $this->y[] = $alcohol;
        }
    }

    public function testWineYieldsLaws(): void
    {
        $this->assertGreaterThan(100, count($this->X), 'Need 100+ data rows');
        $this->assertCount(count($this->X), $this->y);

        $engine = new DiscoveryEngine();
        $results = $engine->discover(
            $this->X, $this->y,
            array_merge(Grammar::baseOpNames(), ['add', 'sub', 'mul', 'div', 'min', 'max', 'abs', 'neg', 'sq', 'sqrt']),
            0.4,
            ['malic_acid', 'ash', 'ash_alcalinity', 'magnesium', 'total_phenols',
             'flavanoids', 'nonflavanoid_phenols', 'proanthocyanins', 'color_intensity',
             'hue', 'od280_od315', 'proline'],
        );

        $this->assertNotEmpty($results[0], 'Wine (178 rows) must yield laws');
    }

    /**
     * V0.8.5 Phase 4: Wine results must include class field.
     */
    public function testDiscoveryReturnsLawClass(): void
    {
        $this->assertGreaterThan(100, count($this->X), 'Need 100+ data rows');

        $engine = new DiscoveryEngine();
        $results = $engine->discover(
            $this->X, $this->y,
            array_merge(Grammar::baseOpNames(), ['add', 'sub', 'mul', 'div', 'min', 'max', 'abs', 'neg', 'sq', 'sqrt']),
            0.4,
            ['malic_acid', 'ash', 'ash_alcalinity', 'magnesium', 'total_phenols',
             'flavanoids', 'nonflavanoid_phenols', 'proanthocyanins', 'color_intensity',
             'hue', 'od280_od315', 'proline'],
            0.2
        );

        $this->assertNotEmpty($results[0], 'Wine must yield laws');
        foreach ($results[0] as $r) {
            $this->assertArrayHasKey('class', $r, 'Each result must have class field');
            $this->assertContains($r['class'], ['EMPIRICAL', 'IDENTITY', 'NONE']);
        }
    }
}
