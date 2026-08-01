<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\SemanticFactInserter;
use BeeSwarm\Forager\StreamingAccumulator;
use BeeSwarm\Infra\Database;

/**
 * Story D10 Phase 2: StreamingAccumulator — scanWithAccumulator extracted from Forager
 */
class StreamingAccumulatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Database::get()->exec("DELETE FROM knowledge_graph WHERE subject IN ('Лондон','Париж','Берлин')");
    }

    /**
     * 3 файла × один паттерн → ≥ tMin точек → задача выпускается
     */
    public function testAccumulatesMultipleFiles(): void
    {
        $strategies = [
            'explode_lines' => function (string $c): array {
                $lines = explode("\n", trim($c));
                $rows = [];
                foreach ($lines as $l) {
                    $parts = preg_split('/[\s,;]+/', trim($l));
                    $nums = array_filter($parts, 'is_numeric');
                    if (count($nums) >= 2) {
                        $rows[] = array_map('floatval', $nums);
                    }
                }
                return $rows;
            },
        ];
        $factInserter = new SemanticFactInserter();
        $acc = new StreamingAccumulator($strategies, $factInserter);
        $dir = sys_get_temp_dir() . '/d10_accum_' . uniqid();
        mkdir($dir);

        for ($i = 0; $i < 3; $i++) {
            file_put_contents("{$dir}/rep{$i}.txt", ($i) . ' ' . ($i + 1) . ' ' . ($i + 2) . "\n" .
                ($i + 5) . ' ' . ($i + 6) . ' ' . ($i + 7) . "\n" .
                ($i + 10) . ' ' . ($i + 11) . ' ' . ($i + 12) . "\n" .
                ($i + 15) . ' ' . ($i + 16) . ' ' . ($i + 17) . "\n" .
                ($i + 20) . ' ' . ($i + 21) . ' ' . ($i + 22) . "\n" .
                ($i + 25) . ' ' . ($i + 26) . ' ' . ($i + 27) . "\n");
        }

        $tasks = $acc->scan([
            $dir => 1,
        ]);

        $this->assertGreaterThan(0, count($tasks), 'Must produce tasks from accumulated data');
        foreach ($tasks as $t) {
            $this->assertGreaterThanOrEqual(
                10,
                count($t['data']),
                "Task {$t['name']} must have ≥ tMin data points from accumulation"
            );
        }

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }

    /**
     * Semantic facts → inserted into knowledge_graph via SemanticFactInserter
     */
    public function testInsertsSemanticFacts(): void
    {
        $strategies = [
            'preg_match_is_a' => function (string $c): array {
                $facts = [];
                if (preg_match_all('/([А-Яа-яA-Za-z_]+)\s*—\s*это\s+([А-Яа-яA-Za-z_]+)/u', $c, $mm)) {
                    for ($i = 0; $i < count($mm[0]); $i++) {
                        $facts[] = [
                            'semantic' => true,
                            's' => $mm[1][$i],
                            'p' => 'is_a',
                            'o' => $mm[2][$i],
                        ];
                    }
                }
                return $facts;
            },
        ];

        $factInserter = new SemanticFactInserter();
        $acc = new StreamingAccumulator($strategies, $factInserter);
        $dir = sys_get_temp_dir() . '/d10_sem_' . uniqid();
        mkdir($dir);

        file_put_contents("{$dir}/sem.txt", "Лондон — это город\nПариж — это город\nБерлин — это город\n");

        $acc->scan([
            $dir => 1,
        ]);

        // Verify facts inserted into KG
        $stmt = Database::get()->prepare(
            'SELECT COUNT(*) FROM knowledge_graph WHERE predicate=? AND object=?'
        );
        $stmt->execute(['is_a', 'город']);
        $count = (int) $stmt->fetchColumn();

        $this->assertSame(3, $count, 'All 3 semantic facts must be in knowledge_graph');

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }

    /**
     * S1.11 Phase 1: source_path в task-структуре
     * Каждая задача должна содержать source_path — путь к файлу-источнику данных.
     */
    public function testTasksIncludeSourcePath(): void
    {
        $strategies = [
            'csv' => function (string $c): array {
                $lines = explode("\n", trim($c));
                $rows = [];
                foreach ($lines as $l) {
                    $parts = str_getcsv($l);
                    $nums = array_filter($parts, 'is_numeric');
                    if (count($nums) >= 2) {
                        $rows[] = array_map('floatval', $nums);
                    }
                }
                return $rows;
            },
        ];
        $factInserter = new SemanticFactInserter();
        $acc = new StreamingAccumulator($strategies, $factInserter);
        $dir = sys_get_temp_dir() . '/s11_src_' . uniqid();
        mkdir($dir);

        // 3 файла с одинаковым паттерном → накопятся в одну задачу
        for ($i = 0; $i < 3; $i++) {
            file_put_contents("{$dir}/src{$i}.csv", ($i) . ',' . ($i+1) . "\n" .
                ($i+5) . ',' . ($i+6) . "\n" .
                ($i+10) . ',' . ($i+11) . "\n" .
                ($i+15) . ',' . ($i+16) . "\n");
        }

        $tasks = $acc->scan([$dir => 1]);

        $this->assertGreaterThan(0, count($tasks), 'Must produce tasks');
        foreach ($tasks as $t) {
            $this->assertArrayHasKey('source_path', $t, 'Task must have source_path field');
            $this->assertNotEmpty($t['source_path'], 'source_path must not be empty');
            $this->assertStringContainsString('src', $t['source_path'], 'source_path must reference a source file');
        }

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }

    /**
     * S1.11 Phase 3: col_labels из markdown-таблиц
     * Заголовки колонок из | header1 | header2 | → попадают в task['col_labels']
     */
    public function testTasksIncludeColumnLabels(): void
    {
        $strategies = [
            'csv' => function (string $c): array {
                $lines = explode("\n", trim($c));
                $rows = [];
                foreach ($lines as $l) {
                    $parts = str_getcsv($l);
                    $nums = array_filter($parts, 'is_numeric');
                    if (count($nums) >= 2) {
                        $rows[] = array_map('floatval', $nums);
                    }
                }
                return $rows;
            },
        ];
        $factInserter = new SemanticFactInserter();
        $acc = new StreamingAccumulator($strategies, $factInserter);
        $dir = sys_get_temp_dir() . '/s11_cols_' . uniqid();
        mkdir($dir);

        // CSV с заголовком
        file_put_contents("{$dir}/data.csv", "price,qty\n10,5\n20,3\n30,2\n40,1\n50,4\n");
        // Ещё строк для tMin=10
        file_put_contents("{$dir}/more.csv", "price,qty\n60,6\n70,7\n80,8\n90,9\n100,10\n");

        $tasks = $acc->scan([$dir => 1]);

        $this->assertGreaterThan(0, count($tasks), 'Must produce tasks');
        $numTasks = 0;
        foreach ($tasks as $t) {
            if ($t['domain'] === 'foraged') {
                $numTasks++;
                $this->assertArrayHasKey('col_labels', $t, 'Task must have col_labels field');
                $this->assertIsArray($t['col_labels'], 'col_labels must be array');
                $this->assertContains('price', $t['col_labels'], 'Labels must include price');
                $this->assertContains('qty', $t['col_labels'], 'Labels must include qty');
            }
        }
        $this->assertGreaterThan(0, $numTasks, 'Must have at least one foraged task');

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }
}
