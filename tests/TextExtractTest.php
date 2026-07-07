<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Forager\Forager;

/**
 * E1.6.1: Text atom extraction creates forager tasks
 */
class TextExtractTest extends TestCase
{
    /**
     * match_label(GI) должен создавать таски с числами
     */
    public function testForagerCreatesTextAtomTasks(): void
    {
        AtomRegistry::addDiscoveredTextAtom('match_label', 'GI');

        $f = new Forager();
        $dir = sys_get_temp_dir() . '/e161_' . uniqid();
        mkdir($dir);

        // 15 файлов × 1 GI = 15 точек → tMin=10 пройден
        for ($i = 0; $i < 15; $i++) {
            file_put_contents("{$dir}/daily{$i}.md", 'GI: ' . (7 + $i * 0.1) . "\n");
        }

        $tasks = $f->scanWithAccumulator([
            $dir => 1,
        ]);

        $textTasks = array_filter($tasks, fn ($t) => str_starts_with($t['name'], 'foraged_txt_'));
        $this->assertNotEmpty(
            $textTasks,
            'Forager must create tasks from discovered text atoms like match_label(GI)'
        );

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }
}
