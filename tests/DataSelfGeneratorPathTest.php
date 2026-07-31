<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\DataSelfGenerator;

/**
 * Фикс: DataSelfGenerator должен принимать путь к metrics при создании.
 */
class DataSelfGeneratorPathTest extends TestCase
{
    /** Путь передаётся и используется */
    public function testPathIsInjectable(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'metrics_');
        $rows = [];
        for ($i = 0; $i < 15; $i++) {
            $rows[] = json_encode(['date' => "2026-01-{$i}", 'sleep' => 7.0 + $i*0.1, 'energy' => 7.0 - $i*0.05]);
        }
        file_put_contents($tmp, implode("\n", $rows) . "\n");

        $gen = new DataSelfGenerator($tmp);
        $tasks = $gen->fromMetrics();

        $this->assertNotEmpty($tasks, 'Must generate tasks from injected path');
        unlink($tmp);
    }

    /** Конструктор должен хранить путь */
    public function testConstructorStoresPath(): void
    {
        $gen = new DataSelfGenerator('/custom/path/metrics.jsonl');
        $prop = new \ReflectionProperty(DataSelfGenerator::class, 'metricsPath');
        $this->assertEquals('/custom/path/metrics.jsonl', $prop->getValue($gen));
    }

    /** Без пути — fallback на дефолтный */
    public function testDefaultPathFallback(): void
    {
        $gen = new DataSelfGenerator();
        $prop = new \ReflectionProperty(DataSelfGenerator::class, 'metricsPath');
        $path = $prop->getValue($gen);
        $this->assertStringContainsString('metrics.jsonl', $path);
    }
}
