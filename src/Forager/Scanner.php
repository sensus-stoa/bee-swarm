<?php

declare(strict_types=1);

namespace BeeSwarm\Forager;

use BeeSwarm\Infra\Database;
use BeeSwarm\Knowledge\ConceptRegistry;

/**
 * Scanner — extracted scanDir() from Forager (D10 Phase 4).
 *
 * Scans a single directory for extractable data patterns.
 * Returns tasks + strategy scores. Depends on Database and ConceptRegistry infrastructure.
 */
class Scanner
{
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

        foreach ($iterator as $file) {
            try {
                $path = $file->getPathname();
            } catch (\Throwable) {
                continue;
            }
            try {
                $file->getSize();
            } catch (\Throwable) {
                continue;
            }
            if (str_contains($path, '.git/') || str_contains($path, 'venv/') || str_contains($path, 'node_modules/')) {
                continue;
            }
            if (str_contains($path, '/.cache/') || str_contains($path, '/.local/share/')) {
                continue;
            }
            if (str_contains($path, '/.mozilla/') || str_contains($path, '/.config/')) {
                continue;
            }
            if (str_contains($path, '/.symfony') || str_contains($path, '/.composer/')) {
                continue;
            }

            $paths[] = $path;

            $content = @file_get_contents($path, false, null, 0, 50_000);
            if (! $content) {
                continue;
            }

            foreach ($strategies as $sname => $strategy) {
                $rows = $strategy($content);
                $scores[$sname] = ($scores[$sname] ?? 0) + count($rows);

                $isSemantic = isset($rows[0]['semantic']) && $rows[0]['semantic'] === true;
                $minRows = $isSemantic ? 1 : 3;
                if (count($rows) < $minRows) {
                    continue;
                }

                if ($isSemantic) {
                    $data = [];
                    $subjects = [];
                    $objects = [];
                    $positivePairs = [];

                    foreach ($rows as $r) {
                        if (isset($r['s'], $r['p'], $r['o'])) {
                            $s = trim($r['s']);
                            $o = trim($r['o']);
                            $pred = $r['p'];
                            $sh = ConceptRegistry::register($s);
                            $oh = ConceptRegistry::register($o);
                            $data[] = [$sh, $oh, 1.0];
                            $subjects[] = $s;
                            $objects[] = $o;
                            $positivePairs[$s . '::' . $o] = true;

                            $dbCheck = Database::get()->prepare(
                                'SELECT confidence FROM knowledge_graph WHERE subject=? AND predicate=? AND object=?'
                            );
                            $dbCheck->execute([$s, $pred, $o]);
                            $existing = $dbCheck->fetchColumn();
                            if ($existing !== false) {
                                $newConf = min(1.0, (float) $existing + 0.25);
                                Database::get()->prepare(
                                    'UPDATE knowledge_graph SET confidence=? WHERE subject=? AND predicate=? AND object=?'
                                )->execute([$newConf, $s, $pred, $o]);
                            } else {
                                Database::get()->prepare(
                                    'INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES (?,?,?,0.3)'
                                )->execute([$s, $pred, $o]);
                            }
                        }
                    }

                    $uniqSubjects = array_unique($subjects);
                    $uniqObjects = array_unique($objects);
                    $negCount = 0;
                    foreach ($uniqSubjects as $s) {
                        foreach ($uniqObjects as $o) {
                            if (isset($positivePairs[$s . '::' . $o])) {
                                continue;
                            }
                            $sh = ConceptRegistry::register($s);
                            $oh = ConceptRegistry::register($o);
                            $data[] = [$sh, $oh, 0.0];
                            $negCount++;
                            if ($negCount >= 10) {
                                break 2;
                            }
                        }
                    }

                    if (count($data) >= 2) {
                        $this->addTask($tasks, $taskIndex, 'foraged_sem_' . md5($path), $data, 'foraged_semantic');
                    }
                } else {
                    if (isset($rows[0]) && count($rows[0]) >= 2) {
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
            }
        }

        return [
            'tasks' => $tasks,
            'scores' => $scores,
            'paths' => $paths,
        ];
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
}
