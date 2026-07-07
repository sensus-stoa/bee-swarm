<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;

/**
 * E1.2: Text-aware task format — forager tasks must carry raw content
 *
 * @group disabled
 */
class TextTaskTest extends TestCase
{
    /** Forager tasks должны нести сырой контент */
    public function testForagerTaskHasContent(): void
    {
        $f = new Forager();
        $dir = sys_get_temp_dir() . '/e12_' . uniqid();
        mkdir($dir);
        // 20 строк — достаточно для tMin=10 в аккумуляторе
        $lines = '';
        for ($i = 0; $i < 20; $i++) {
            $lines .= ($i) . ' ' . ($i+1) . ' ' . ($i+2) . "\n";
        }
        file_put_contents("$dir/data.txt", $lines);

        $tasks = $f->scanWithAccumulator([$dir => 1]);

        $this->assertNotEmpty($tasks, 'Must produce tasks with tMin met');
        foreach ($tasks as $t) {
            $this->assertArrayHasKey(
                'content',
                $t,
                "Task {$t['name']} missing 'content' field required for text atom search"
            );
        }

        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
}
