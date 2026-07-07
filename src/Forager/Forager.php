<?php

declare(strict_types=1);

namespace BeeSwarm\Forager;

class Forager
{
    private array $priorities = [];

    private array $strategies = [];

    private array $strategyScores = [];

    private int $newTaskCount = 0;

    private array $newDomains = [];

    private string $lastReportedFingerprint = '';

    private string $currentFingerprint = '';

    private SemanticFactInserter $factInserter;

    private Scanner $scanner;

    public function __construct(?array $priorities = null)
    {
        $this->priorities = $priorities ?? [];
        $this->strategies = $this->loadStrategies();
        $this->factInserter = new SemanticFactInserter();
        $this->scanner = new Scanner();
    }

    /**
     * @internal D10 bridge — public access to strategies for extraction
     */
    public function getStrategiesForExtraction(): array
    {
        return $this->strategies;
    }

    private function loadStrategies(): array
    {
        return [
            'preg_match_nums' => function (string $c): array {
                preg_match_all('/-?\d+\.?\d*/', $c, $m);
                if (count($m[0]) < 6) {
                    return [];
                }
                $nums = array_map('floatval', $m[0]);
                $pairs = [];
                for ($i = 0; $i < count($nums) - 1; $i += 2) {
                    $pairs[] = [$nums[$i], $nums[$i + 1]];
                }
                return $pairs;
            },
            'preg_match_table' => function (string $c): array {
                if (! preg_match_all('/\|.+\|.*\n\|[-| ]+\|.*\n((?:\|.+\|.*\n?)+)/', $c, $m)) {
                    return [];
                }
                $rows = [];
                foreach ($m[1] as $table) {
                    foreach (explode("\n", trim($table)) as $line) {
                        $cells = array_map('trim', explode('|', trim($line, '|')));
                        $nums = array_filter($cells, 'is_numeric');
                        if (count($nums) >= 2) {
                            $rows[] = array_map('floatval', $nums);
                        }
                    }
                }
                return $rows;
            },
            'json_decode' => function (string $c): array {
                $r = json_decode($c, true);
                if (! is_array($r)) {
                    return [];
                }
                if (! isset($r[0])) {
                    $r = [$r];
                } if (count($r) < 3) {
                    return [];
                }
                return array_map(fn ($row) => is_array($row) ? array_values(array_filter($row, 'is_numeric')) : [], $r);
            },
            'str_getcsv' => function (string $c): array {
                $lines = explode("\n", trim($c));
                if (count($lines) < 3) {
                    return [];
                }
                $rows = [];
                foreach ($lines as $l) {
                    $r = str_getcsv($l);
                    if (count($r) >= 2) {
                        $rows[] = $r;
                    }
                }
                $numRows = array_filter($rows, fn ($r) => count(array_filter($r, 'is_numeric')) >= 2);
                return array_values($numRows);
            },
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
            'preg_match_is_a' => function (string $c): array {
                $facts = [];
                $stopWords = ['и', 'в', 'на', 'с', 'не', 'то', 'же', 'как', 'так', 'он', 'она', 'оно', 'они', 'мы', 'вы', 'ты', 'это', 'этот', 'эта', 'эти', 'там', 'тут', 'ещё', 'уже', 'для', 'что', 'нет', 'или', 'да', 'но', 'а', 'за', 'из', 'от', 'до', 'при', 'под', 'над', 'об', 'во', 'со', 'ко', 'по', 'же', 'бы', 'ли', 'ль', 'б'];

                $addFact = function (string $s, string $o) use (&$facts, $stopWords): void {
                    $s = trim($s);
                    $o = trim($o);
                    // Фильтр: ≥3 символа, не стоп-слово, не начинается с цифры
                    if (mb_strlen($s) < 3 || mb_strlen($o) < 3) {
                        return;
                    }
                    if (in_array(mb_strtolower($s), $stopWords)) {
                        return;
                    }
                    if (in_array(mb_strtolower($o), $stopWords)) {
                        return;
                    }
                    if (preg_match('/^\d/u', $s) || preg_match('/^\d/u', $o)) {
                        return;
                    }
                    $facts[] = [
                        'semantic' => true,
                        's' => $s,
                        'p' => 'is_a',
                        'o' => $o,
                    ];
                };

                if (preg_match_all('/([А-Яа-яA-Za-z_]+)\s*[—–-]\s*это\s+([А-Яа-яA-Za-z_]+)/u', $c, $mm)) {
                    for ($i = 0; $i < count($mm[0]); $i++) {
                        $addFact($mm[1][$i], $mm[2][$i]);
                    }
                }
                if (preg_match_all('/([А-Яа-яA-Za-z_]+)\s+является\s+([А-Яа-яA-Za-z_]+)/u', $c, $mm)) {
                    for ($i = 0; $i < count($mm[0]); $i++) {
                        $addFact($mm[1][$i], $mm[2][$i]);
                    }
                }
                return $facts;
            },
        ];
    }

    private function getComposedStrategies(): array
    {
        $composed = [];
        $names = array_keys($this->strategies);
        foreach ($names as $outer) {
            foreach ($names as $inner) {
                if ($outer === $inner) {
                    continue;
                }
                $compName = "{$outer}({$inner})";
                if (isset($this->strategies[$compName])) {
                    continue;
                }
                $innerFn = $this->strategies[$inner];
                $outerFn = $this->strategies[$outer];
                $composed[$compName] = function (string $c) use ($innerFn, $outerFn): array {
                    $innerResult = $innerFn($c);
                    if (! $innerResult) {
                        return [];
                    }
                    $asString = json_encode($innerResult);
                    return $outerFn($asString);
                };
            }
        }
        return $composed;
    }

    /**
     * Streaming scan with SQLite accumulator — delegates to StreamingAccumulator (D10 Phase 2).
     */
    public function scanWithAccumulator(array $dirs): array
    {
        $allStrategies = array_merge($this->strategies, $this->getComposedStrategies());
        $acc = new StreamingAccumulator($allStrategies, $this->factInserter);
        $tasks = $acc->scan($dirs);

        // Fingerprint from paths collected during scan (single walk — no desync)
        $this->currentFingerprint = $this->fingerprintFromPaths($acc->getPaths());

        $this->newTaskCount = count($tasks);
        $this->newDomains = [];
        foreach ($tasks as $t) {
            $this->newDomains[$t['domain']] = true;
        }
        return $tasks;
    }

    /**
     * Compute md5 fingerprint from file paths + sizes.
     *
     * @param string[] $paths
     */
    private function fingerprintFromPaths(array $paths): string
    {
        sort($paths);
        $fpParts = [];
        foreach ($paths as $p) {
            try {
                $fpParts[] = $p . ':' . filesize($p);
            } catch (\Throwable) {
                $fpParts[] = $p;
            }
        }
        return md5(implode(',', $fpParts));
    }

    /**
     * Insert semantic fact into knowledge_graph — delegates to SemanticFactInserter (D10 Phase 3).
     */
    public function addSemanticFact(string $s, string $p, string $o): void
    {
        $this->factInserter->insert($s, $p, $o);
    }

    public function scan(array $dirs): array
    {
        $sorted = $dirs;
        arsort($sorted);
        $allTasks = [];
        $allStrategies = array_merge($this->strategies, $this->getComposedStrategies());
        $allPaths = [];

        $maxTasks = 200; // OOM guard for 1499 files
        foreach ($sorted as $dir => $pri) {
            if (count($allTasks) >= $maxTasks) {
                break;
            }
            if (! is_dir($dir)) {
                continue;
            }
            $result = $this->scanner->scanDir($dir, $allStrategies);
            $dirTasks = $result['tasks'];
            $allPaths = array_merge($allPaths, $result['paths']);
            foreach ($result['scores'] as $sname => $score) {
                $this->strategyScores[$sname] = ($this->strategyScores[$sname] ?? 0) + $score;
            }
            $allTasks = array_merge($allTasks, $dirTasks);
            if (count($dirTasks) > 0) {
                $this->priorities[$dir] = min(1.0, $pri + count($dirTasks) * 0.05);
            }
        }

        // Compute fingerprint from scanned file paths (single walk — no desync)
        $this->currentFingerprint = $this->fingerprintFromPaths($allPaths);

        // Always track current content
        $this->newTaskCount = count($allTasks);
        foreach ($allTasks as $t) {
            $domain = $t['domain'] ?? 'unknown';
            $this->newDomains[$domain] = true;
        }

        foreach ($this->strategyScores as $name => $score) {
            if ($score === 0 && count($this->strategies) > 3) {
                unset($this->strategies[$name]);
            }
        }
        return $allTasks;
    }

    public function getPriorities(): array
    {
        return $this->priorities;
    }

    /**
     * ≥1 новый домен ИЛИ ≥5 новых задач с последнего consume
     */
    public function hasNewContent(): bool
    {
        if ($this->currentFingerprint === $this->lastReportedFingerprint && $this->lastReportedFingerprint !== '') {
            return false;
        }
        return count($this->newDomains) >= 1 || $this->newTaskCount >= 5;
    }

    public function getNewDomainCount(): int
    {
        return count($this->newDomains);
    }

    public function getNewTaskCount(): int
    {
        return $this->newTaskCount;
    }

    /**
     * Запомнить текущий скан как доложенный
     */
    public function markContentConsumed(): void
    {
        $this->lastReportedFingerprint = $this->currentFingerprint;
    }
}
