<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Scanner;
use BeeSwarm\Infra\Database;

/**
 * Story D10 Phase 4: Scanner — scanDir() extracted from Forager
 */
class ScannerTest extends TestCase
{
    /**
     * @var string[] temp dirs to clean up
     */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                array_map('unlink', glob("{$dir}/*"));
                rmdir($dir);
            }
        }
        parent::tearDown();
    }

    /**
     * Directory with numeric files → tasks with column pairs
     */
    public function testScanDirProducesNumericTasks(): void
    {
        $scanner = new Scanner();
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

        $dir = $this->tempDir('d10_scanner_');
        file_put_contents("{$dir}/nums.txt", "1 2 3\n4 5 6\n7 8 9\n10 11 12\n");

        $result = $scanner->scanDir($dir, $strategies);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('tasks', $result);
        $this->assertArrayHasKey('scores', $result);
        $this->assertArrayHasKey('paths', $result);
        $this->assertGreaterThan(0, count($result['tasks']), 'Must find numeric data');
        $this->assertArrayHasKey('explode_lines', $result['scores'], 'Must track strategy scores');
    }

    /**
     * Empty directory → empty tasks
     */
    public function testEmptyDirReturnsNoTasks(): void
    {
        $scanner = new Scanner();
        $dir = $this->tempDir('d10_empty_');

        $result = $scanner->scanDir($dir, []);

        $this->assertSame([], $result['tasks']);
        $this->assertSame([], $result['scores']);
        $this->assertSame([], $result['paths']);
    }

    /**
     * Semantic facts → tasks produced + DB populated
     */
    public function testScanDirProcessesSemanticFacts(): void
    {
        $scanner = new Scanner();
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

        $dir = $this->tempDir('d10_semscan_');
        file_put_contents("{$dir}/sem.txt", "Кошка — это животное\nСобака — это животное\n");

        $result = $scanner->scanDir($dir, $strategies);

        // Must produce at least 1 semantic task
        $this->assertGreaterThan(0, count($result['tasks']), 'Must produce semantic tasks');
        $this->assertArrayHasKey('preg_match_is_a', $result['scores']);

        // Verify KG was populated
        $stmt = Database::get()->prepare(
            'SELECT COUNT(*) FROM knowledge_graph WHERE predicate=? AND object=?'
        );
        $stmt->execute(['is_a', 'животное']);
        $count = (int) $stmt->fetchColumn();
        $this->assertGreaterThan(0, $count, 'Knowledge graph must contain semantic facts');
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . uniqid();
        mkdir($dir);
        $this->tempDirs[] = $dir;
        return $dir;
    }
}
