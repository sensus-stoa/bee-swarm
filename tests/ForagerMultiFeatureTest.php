<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;
use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;

/**
 * MULTI-FEATURE-TASKS (05.08, ЭКСП-006): all-pairs даёт nFeat=1 —
 * закон Литтла L=λ×W неоткрываем через forager. Тройки колонок
 * (X=[c0,c1], y=c2) дают nFeat=2 → двухфичевые законы открываемы.
 */
class ForagerMultiFeatureTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/mf_' . uniqid();
        mkdir($this->tmpDir);

        // Закон Литтла: L = λ × W (12 строк, tMin=10)
        $little = "lambda,W_hours,L_tasks\n";
        for ($i = 1; $i <= 12; $i++) {
            $lambda = $i + 0.5;
            $w = $i * 0.7;
            $little .= "{$lambda},{$w}," . round($lambda * $w, 3) . "\n";
        }
        file_put_contents("{$this->tmpDir}/little.csv", $little);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            foreach (glob("{$this->tmpDir}/*") as $f) {
                unlink($f);
            }
            rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    public function testTripleColumnTaskCreated(): void
    {
        $forager = new Forager();
        $tasks = $forager->scanWithAccumulator([$this->tmpDir => 1]);

        $hasTriple = false;
        foreach ($tasks as $task) {
            // Тройка: 3 колонки данных → nFeat=2
            if (count($task['data'][0] ?? []) === 3) {
                $hasTriple = true;
                $this->assertCount(3, $task['col_labels'] ?? [], 'triple task must have 3 labels');
                break;
            }
        }
        $this->assertTrue($hasTriple, 'Forager must create triple-column tasks (X=[c0,c1], y=c2)');
    }

    public function testLittleLawDiscoverableFromTripleTask(): void
    {
        $forager = new Forager();
        $tasks = $forager->scanWithAccumulator([$this->tmpDir => 1]);

        $triple = null;
        foreach ($tasks as $task) {
            if (count($task['data'][0] ?? []) === 3) {
                $triple = $task;
                break;
            }
        }
        $this->assertNotNull($triple, 'triple task must exist');

        // Search::find: X = [λ, W], y = L → должен найти (x0×x1) CV=0
        $X = array_map(fn (array $r): array => array_slice($r, 0, 2), $triple['data']);
        $y = array_column($triple['data'], 2);
        $g = Grammar::fromOps(Grammar::baseOpNames());

        [$found, $cv, $formula] = Search::find($X, $y, $g, 2);
        $this->assertTrue($found, 'Little law L=λ×W must be found from triple task');
        $this->assertLessThan(0.001, $cv, 'CV must be near zero');
    }
}
