<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;

/**
 * Story D9: Streaming Forager — SQLite accumulator
 *
 * @group disabled
 */
class StreamingForagerTest extends TestCase
{
    /** 5 файлов × один паттерн → ≥ tMin точек → задача выпускается */
    public function testAccumulatesMultipleFilesIntoOneTask(): void
    {
        $f = new Forager();
        $dir = sys_get_temp_dir() . '/d9_stream_' . uniqid();
        mkdir($dir);

        // 5 файлов, каждый с одинаковым паттерном (a,b,c)
        for ($i = 0; $i < 5; $i++) {
            file_put_contents("$dir/rep$i.txt", ($i) . " " . ($i+1) . " " . ($i+2) . "\n" .
                                                   ($i+5) . " " . ($i+6) . " " . ($i+7) . "\n" .
                                                   ($i+10) . " " . ($i+11) . " " . ($i+12) . "\n");
        }

        $tasks = $f->scanWithAccumulator([$dir => 1]);

        // Должен быть 1 задача с ≥10 точками (5 файлов × 3 строки = 15)
        $this->assertCount(1, $tasks, 'Same pattern across files → 1 merged task');
        $this->assertGreaterThanOrEqual(10, count($tasks[0]['data']),
            'Merged task must have ≥ tMin data points');

        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
}
