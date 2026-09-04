<?php

declare(strict_types=1);

namespace BeeSwarm\Forager;

use BeeSwarm\Knowledge\ConceptRegistry;

/**
 * Scanner — extracted scanDir() from Forager (D10 Phase 4).
 *
 * Scans a single directory for extractable data patterns.
 * Returns tasks + strategy scores. Depends on Database and ConceptRegistry infrastructure.
 */
class Scanner
{
    private SemanticFactInserter $factInserter;

    public function __construct(?SemanticFactInserter $factInserter = null)
    {
        $this->factInserter = $factInserter ?? new SemanticFactInserter();
    }

    /**
     * Scan one directory with given strategies.
     *
     * @param array<string, callable> $strategies
     * @return array{tasks: array, scores: array<string, int>, paths: string[]}
     */
    public function scanDir(string $dir, array $strategies): array
    {
        $tasks = [];
        $taskIndex = [];
        $scores = [];
        $paths = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
        } catch (\Throwable) {
            return [
                'tasks' => [],
                'scores' => [],
                'paths' => [],
            ];
        }

        $this->scanFiles($iterator, $strategies, $tasks, $taskIndex, $scores, $paths);

        return [
            'tasks' => $tasks,
            'scores' => $scores,
            'paths' => $paths,
        ];
    }

    /**
     * @param \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $iterator
     * @param array<string, callable> $strategies
     * @param array<int, array> $tasks
     * @param array<string, int> $taskIndex
     * @param array<string, int> $scores
     * @param string[] $paths
     */
    private function scanFiles(\RecursiveIteratorIterator $iterator, array $strategies, array &$tasks, array &$taskIndex, array &$scores, array &$paths): void
    {
        foreach ($iterator as $file) {
            $path = $this->safePath($file);
            if ($path === null || $this->isSkippablePath($path)) {
                continue;
            }

            $paths[] = $path;
            $content = @file_get_contents($path, false, null, 0, 50_000);
            if (! $content) {
                continue;
            }

            $this->processFile($path, $content, $strategies, $tasks, $taskIndex, $scores);
        }
    }

    /**
     * @param array<int, array> $tasks
     * @param array<string, int> $taskIndex
     */
    private function addTask(array &$tasks, array &$taskIndex, string $name, array $data, string $domain): void
    {
        $data = array_slice($data, 0, 100);
        if (isset($taskIndex[$name])) {
            $i = $taskIndex[$name];
            $tasks[$i]['data'] = array_slice(array_merge($tasks[$i]['data'], $data), 0, 100);
        } else {
            $tasks[] = [
                'name' => $name,
                'data' => $data,
                'domain' => $domain,
            ];
            $taskIndex[$name] = count($tasks) - 1;
        }
    }

    /**
     * @param array<string, callable> $strategies
     * @param array<int, array> $tasks
     * @param array<string, int> $taskIndex
     * @param array<string, int> $scores
     */
    private function processFile(string $path, string $content, array $strategies, array &$tasks, array &$taskIndex, array &$scores): void
    {
        foreach ($strategies as $sname => $strategy) {
            $rows = $strategy($content);
            $scores[$sname] = ($scores[$sname] ?? 0) + count($rows);

            $isSemantic = isset($rows[0]['semantic']) && $rows[0]['semantic'] === true;
            $minRows = $isSemantic ? 1 : 3;
            if (count($rows) < $minRows) {
                continue;
            }

            if ($isSemantic) {
                $this->processSemanticRows($rows, $path, $tasks, $taskIndex);
                continue;
            }
            $this->processNumericRows($rows, $path, $tasks, $taskIndex);
        }
    }

    private function safePath(\SplFileInfo $file): ?string
    {
        try {
            $path = $file->getPathname();
            $file->getSize();
            return $path;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isSkippablePath(string $path): bool
    {
        if (str_contains($path, '.git/') || str_contains($path, 'venv/') || str_contains($path, 'node_modules/')) {
            return true;
        }
        if (str_contains($path, '/.cache/') || str_contains($path, '/.local/share/')) {
            return true;
        }
        if (str_contains($path, '/.mozilla/') || str_contains($path, '/.config/')) {
            return true;
        }
        if (str_contains($path, '/.symfony') || str_contains($path, '/.composer/')) {
            return true;
        }
        // Security: skip sensitive directories
        if (str_contains($path, '/.ssh/') || str_contains($path, '/.gnupg/') || str_contains($path, '/.aws/')) {
            return true;
        }
        // Performance: skip large binary/media directories
        if (str_contains($path, '/.zoom/') || str_contains($path, '/.dropbox/')) {
            return true;
        }
        if (str_contains($path, '/Downloads/') || str_contains($path, '/Music/')) {
            return true;
        }
        if (str_contains($path, '/Videos/') || str_contains($path, '/Pictures/')) {
            return true;
        }
        return false;
    }

    /**
     * @param array<int, array> $tasks
     * @param array<string, int> $taskIndex
     */
    private function processSemanticRows(array $rows, string $path, array &$tasks, array &$taskIndex): void
    {
        $data = [];
        $subjects = [];
        $objects = [];
        $positivePairs = [];

        foreach ($rows as $r) {
            if (! isset($r['s'], $r['p'], $r['o'])) {
                continue;
            }
            $s = trim($r['s']);
            $o = trim($r['o']);
            $pred = $r['p'];
            $sh = ConceptRegistry::register($s);
            $oh = ConceptRegistry::register($o);
            $data[] = [$sh, $oh, 1.0];
            $subjects[] = $s;
            $objects[] = $o;
            $positivePairs[$s . '::' . $o] = true;

            $this->factInserter->insert($s, $pred, $o);
        }

        $this->addNegativeExamples($subjects, $objects, $positivePairs, $data);

        if (count($data) >= 2) {
            $this->addTask($tasks, $taskIndex, 'foraged_sem_' . md5($path), $data, 'foraged_semantic');
        }
    }

    /**
     * @param string[] $subjects
     * @param string[] $objects
     * @param array<string, bool> $positivePairs
     * @param array<int, array> $data
     */
    private function addNegativeExamples(array $subjects, array $objects, array $positivePairs, array &$data): void
    {
        $negCount = 0;
        foreach (array_unique($subjects) as $s) {
            foreach (array_unique($objects) as $o) {
                if (isset($positivePairs[$s . '::' . $o])) {
                    continue;
                }
                $sh = ConceptRegistry::register($s);
                $oh = ConceptRegistry::register($o);
                $data[] = [$sh, $oh, 0.0];
                $negCount++;
                if ($negCount >= 10) {
                    return;
                }
            }
        }
    }

    /**
     * @param array<int, array> $tasks
     * @param array<string, int> $taskIndex
     */
    private function processNumericRows(array $rows, string $path, array &$tasks, array &$taskIndex): void
    {
        if (! isset($rows[0]) || count($rows[0]) < 2) {
            return;
        }
        $nCols = count($rows[0]);
        for ($c1 = 0; $c1 < min($nCols, 4); $c1++) {
            for ($c2 = $c1 + 1; $c2 < min($nCols, 4); $c2++) {
                $data = [];
                foreach ($rows as $r) {
                    if (isset($r[$c1], $r[$c2])) {
                        $data[] = [(float) $r[$c1], (float) $r[$c2]];
                    }
                }
                if (count($data) >= 3) {
                    $this->addTask($tasks, $taskIndex, 'foraged_' . md5($path) . "_c{$c1}c{$c2}", $data, 'foraged');
                }
            }
        }
    }
}
