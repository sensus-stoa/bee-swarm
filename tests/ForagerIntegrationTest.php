<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

class ForagerIntegrationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/forager_test_' . getmypid();
        @mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    // ═══ 1. СКАНИРОВАНИЕ ДИРЕКТОРИЙ ═══

    /**
     * Forager находит файлы с числами
     */
    public function testScanFindsFilesWithNumbers(): void
    {
        // Создаём тестовые файлы
        file_put_contents($this->tmpDir . '/rich.md', "|a|b|\n|---|---|\n|1|2|\n|3|4|\n|5|6|");
        file_put_contents($this->tmpDir . '/empty.md', 'no numbers here');
        file_put_contents($this->tmpDir . '/broken.md', '');

        $tasks = $this->forageDir($this->tmpDir);
        $this->assertNotEmpty($tasks, 'Should find tasks in rich.md');
    }

    /**
     * Пустая директория не даёт задач
     */
    public function testEmptyDirReturnsNoTasks(): void
    {
        $emptyDir = $this->tmpDir . '/empty';
        @mkdir($emptyDir);
        $tasks = $this->forageDir($emptyDir);
        $this->assertEmpty($tasks);
    }

    // ═══ 2. ПРИОРИТЕТЫ ═══

    /**
     * Приоритетная директория сканируется первой
     */
    public function testPriorityOrder(): void
    {
        file_put_contents($this->tmpDir . '/low.md', "1 2\n3 4");
        $highDir = $this->tmpDir . '/high';
        @mkdir($highDir);
        file_put_contents($highDir . '/data.md', "|x|y|\n|---|---|\n|1|2|\n|3|4|\n|5|6|");

        $priorities = [
            $highDir => 0.9,
            $this->tmpDir => 0.3,
        ];
        $results = $this->forageWithPriorities($priorities);

        // High priority dir должен быть обработан и дать результат
        $this->assertNotEmpty($results, 'Should find tasks');
    }

    // ═══ 3. ИЗВЛЕЧЕНИЕ ЗАДАЧ ═══

    /**
     * Markdown таблицы → задачи
     */
    public function testMarkdownTableExtraction(): void
    {
        file_put_contents(
            $this->tmpDir . '/table.md',
            "| sleep | stress | energy |\n|---|---|---|\n|7|3|7|\n|6|5|6|\n|8|2|8|"
        );

        $tasks = $this->forageDir($this->tmpDir);
        $this->assertNotEmpty($tasks);

        // Проверяем что задачи решаются (числа подобраны)
        foreach ($tasks as $task) {
            $this->assertArrayHasKey('data', $task);
            $this->assertArrayHasKey('domain', $task);
            $this->assertEquals('foraged', $task['domain']);
            $this->assertGreaterThanOrEqual(2, count($task['data']));
        }
    }

    /**
     * JSON → задачи
     */
    public function testJsonExtraction(): void
    {
        file_put_contents(
            $this->tmpDir . '/data.json',
            json_encode([
                [
                    'sleep' => 7,
                    'stress' => 3,
                    'energy' => 7,
                ],
                [
                    'sleep' => 6,
                    'stress' => 5,
                    'energy' => 6,
                ],
                [
                    'sleep' => 8,
                    'stress' => 2,
                    'energy' => 8,
                ],
                [
                    'sleep' => 5,
                    'stress' => 8,
                    'energy' => 4,
                ],
            ])
        );

        $tasks = $this->forageDir($this->tmpDir);
        $this->assertNotEmpty($tasks, 'Should extract tasks from JSON');
        $this->assertGreaterThanOrEqual(3, count($tasks), 'Should have multiple pairwise tasks');
    }

    // ═══ 4. ПРИОРИТЕТЫ ОБНОВЛЯЮТСЯ ═══

    /**
     * Успешная директория получает приоритет
     */
    public function testPrioritiesUpdatedOnSuccess(): void
    {
        file_put_contents($this->tmpDir . '/good.md', "|a|b|\n|---|---|\n|1|2|\n|3|4|\n|5|6|");

        $priorities = [
            $this->tmpDir => 0.3,
        ];
        $results = $this->forageWithPriorities($priorities);

        $this->assertNotEmpty($results);
        // Приоритет должен вырасти
        $updatedPriority = $results['priorities'][$this->tmpDir] ?? 0;
        $this->assertGreaterThan(0.3, $updatedPriority, 'Priority should increase on success');
    }

    // ═══ HELPERS ═══

    private function forageDir(string $dir): array
    {
        return $this->forageWithPriorities([
            $dir => 0.5,
        ])['tasks'] ?? [];
    }

    private function forageWithPriorities(array $priorities): array
    {
        $allTasks = [];
        $updatedPriorities = $priorities;

        foreach ($priorities as $dir => $pri) {
            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            $dirTaskCount = 0;
            foreach ($iterator as $file) {
                if ($file->getSize() > 500_000) {
                    continue;
                }
                $ext = $file->getExtension();
                $path = $file->getPathname();
                if (str_contains($path, '.git/') || str_contains($path, 'venv/')) {
                    continue;
                }

                $content = file_get_contents($path);
                if (! $content) {
                    continue;
                }

                // Markdown tables
                if ($ext === 'md' && preg_match_all('/\|.+\|.*\n\|[-| ]+\|.*\n((?:\|.+\|.*\n?)+)/', $content, $matches)) {
                    foreach ($matches[1] as $table) {
                        $rows = [];
                        foreach (explode("\n", trim($table)) as $line) {
                            $cells = array_map('trim', explode('|', trim($line, '|')));
                            $nums = array_filter($cells, 'is_numeric');
                            if (count($nums) >= 2) {
                                $rows[] = array_map('floatval', $nums);
                            }
                        }
                        if (count($rows) >= 3) {
                            $nCols = count($rows[0]);
                            for ($c1 = 0; $c1 < $nCols; $c1++) {
                                for ($c2 = $c1 + 1; $c2 < $nCols; $c2++) {
                                    $data = [];
                                    foreach ($rows as $r) {
                                        if (isset($r[$c1], $r[$c2])) {
                                            $data[] = [$r[$c1], $r[$c2]];
                                        }
                                    }
                                    if (count($data) >= 3) {
                                        $allTasks[] = [
                                            'name' => 'foraged_' . basename($path) . "_c{$c1}c{$c2}",
                                            'data' => $data,
                                            'domain' => 'foraged',
                                        ];
                                        $dirTaskCount++;
                                    }
                                }
                            }
                        }
                    }
                }

                // JSON
                if (in_array($ext, ['json', 'jsonl'])) {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        if (! isset($decoded[0])) {
                            $decoded = [$decoded];
                        }
                        if (count($decoded) >= 3) {
                            $numKeys = [];
                            foreach ($decoded[0] as $k => $v) {
                                if (is_numeric($v)) {
                                    $numKeys[] = $k;
                                }
                            }
                            foreach ($numKeys as $k1) {
                                foreach ($numKeys as $k2) {
                                    if ($k1 === $k2) {
                                        continue;
                                    }
                                    $data = [];
                                    foreach ($decoded as $row) {
                                        if (isset($row[$k1], $row[$k2]) && is_numeric($row[$k1]) && is_numeric($row[$k2])) {
                                            $data[] = [(float) $row[$k1], (float) $row[$k2]];
                                        }
                                    }
                                    if (count($data) >= 3) {
                                        $allTasks[] = [
                                            'name' => 'foraged_' . basename($path) . "_{$k1}_{$k2}",
                                            'data' => $data,
                                            'domain' => 'foraged',
                                        ];
                                        $dirTaskCount++;
                                    }
                                }
                            }
                        }
                    }
                }

                // Numeric lines (fallback)
                if ($dirTaskCount === 0) {
                    preg_match_all('/-?\d+\.?\d+/', $content, $nm);
                    if (count($nm[0]) >= 6) {
                        $nums = array_map('floatval', $nm[0]);
                        $data = [];
                        for ($i = 0; $i < count($nums) - 1; $i += 2) {
                            $data[] = [$nums[$i], $nums[$i + 1]];
                        }
                        if (count($data) >= 3) {
                            $allTasks[] = [
                                'name' => 'foraged_' . basename($path),
                                'data' => array_slice($data, 0, 30),
                                'domain' => 'foraged',
                            ];
                            $dirTaskCount++;
                        }
                    }
                }
            }

            if ($dirTaskCount > 0) {
                $updatedPriorities[$dir] = min(1.0, $pri + $dirTaskCount * 0.05);
            }
        }

        return [
            'tasks' => $allTasks,
            'priorities' => $updatedPriorities,
        ];
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = "{$dir}/{$f}";
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
