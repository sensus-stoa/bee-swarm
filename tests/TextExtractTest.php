<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;

/**
 * E1.6.1: Text atom extraction creates forager tasks
 *
 * @group disabled
 */
class TextExtractTest extends TestCase
{
    /** scanWithAccumulator должен создавать таски из открытых text atoms */
    public function testForagerCreatesTextAtomTasks(): void
    {
        $f = new Forager();
        $dir = sys_get_temp_dir() . '/e161_' . uniqid();
        mkdir($dir);

        // 15 строк с GI — достаточно для tMin=10
        $lines = '';
        for ($i = 0; $i < 15; $i++) {
            $lines .= "GI: " . (7 + $i * 0.1) . "\n";
        }
        file_put_contents("$dir/daily.md", $lines);

        $tasks = $f->scanWithAccumulator([$dir => 1]);

        // Должна быть задача, созданная через text atom preg_match(GI)
        $textTasks = array_filter($tasks, fn($t) => str_contains($t['name'], 'preg_match'));
        $this->assertNotEmpty(
            $textTasks,
            'RED: Forager must create tasks from discovered text atoms like preg_match(GI)'
        );

        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
}
