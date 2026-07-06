<?php
declare(strict_types=1);

namespace BeeSwarm\Forager;

use BeeSwarm\Infra\Database;
use BeeSwarm\Knowledge\ConceptRegistry;

class Forager
{
    private array $priorities = [];
    private array $strategies = [];
    private array $strategyScores = [];
    private int $newTaskCount = 0;
    private array $newDomains = [];
    private string $lastReportedFingerprint = '';
    private string $currentFingerprint = '';

    public function __construct(?array $priorities = null)
    {
        $this->priorities = $priorities ?? [];
        $this->strategies = $this->loadStrategies();
    }

    private function loadStrategies(): array
    {
        return [
            'preg_match_nums' => function(string $c): array {
                preg_match_all('/-?\d+\.?\d*/', $c, $m);
                if (count($m[0]) < 6) return [];
                $nums = array_map('floatval', $m[0]);
                $pairs = []; for ($i=0; $i<count($nums)-1; $i+=2) $pairs[] = [$nums[$i], $nums[$i+1]];
                return $pairs;
            },
            'preg_match_table' => function(string $c): array {
                if (!preg_match_all('/\|.+\|.*\n\|[-| ]+\|.*\n((?:\|.+\|.*\n?)+)/', $c, $m)) return [];
                $rows = [];
                foreach ($m[1] as $table) {
                    foreach (explode("\n", trim($table)) as $line) {
                        $cells = array_map('trim', explode('|', trim($line, '|')));
                        $nums = array_filter($cells, 'is_numeric');
                        if (count($nums) >= 2) $rows[] = array_map('floatval', $nums);
                    }
                }
                return $rows;
            },
            'json_decode' => function(string $c): array {
                $r = json_decode($c, true); if (!is_array($r)) return [];
                if (!isset($r[0])) $r = [$r]; if (count($r) < 3) return [];
                return array_map(fn($row) => array_values(array_filter($row, 'is_numeric')), $r);
            },
            'str_getcsv' => function(string $c): array {
                $lines = explode("\n", trim($c)); if (count($lines) < 3) return [];
                $rows = []; foreach ($lines as $l) { $r = str_getcsv($l); if (count($r) >= 2) $rows[] = $r; }
                $numRows = array_filter($rows, fn($r) => count(array_filter($r, 'is_numeric')) >= 2);
                return array_values($numRows);
            },
            'explode_lines' => function(string $c): array {
                $lines = explode("\n", trim($c)); $rows = [];
                foreach ($lines as $l) { $parts = preg_split('/[\s,;]+/', trim($l));
                    $nums = array_filter($parts, 'is_numeric'); if (count($nums) >= 2) $rows[] = array_map('floatval', $nums); }
                return $rows;
            },
            'preg_match_is_a' => function(string $c): array {
                $facts = [];
                $stopWords = ['и','в','на','с','не','то','же','как','так','он','она','оно','они','мы','вы','ты','это','этот','эта','эти','там','тут','ещё','уже','для','что','нет','или','да','но','а','за','из','от','до','при','под','над','об','во','со','ко','по','же','бы','ли','ль','б'];
                
                $addFact = function(string $s, string $o) use (&$facts, $stopWords) {
                    $s = trim($s); $o = trim($o);
                    // Фильтр: ≥3 символа, не стоп-слово, не начинается с цифры
                    if (mb_strlen($s) < 3 || mb_strlen($o) < 3) return;
                    if (in_array(mb_strtolower($s), $stopWords)) return;
                    if (in_array(mb_strtolower($o), $stopWords)) return;
                    if (preg_match('/^\d/u', $s) || preg_match('/^\d/u', $o)) return;
                    $facts[] = ['semantic' => true, 's' => $s, 'p' => 'is_a', 'o' => $o];
                };
                
                if (preg_match_all('/([А-Яа-яA-Za-z_]+)\s*[—–-]\s*это\s+([А-Яа-яA-Za-z_]+)/u', $c, $mm)) {
                    for ($i = 0; $i < count($mm[0]); $i++) $addFact($mm[1][$i], $mm[2][$i]);
                }
                if (preg_match_all('/([А-Яа-яA-Za-z_]+)\s+является\s+([А-Яа-яA-Za-z_]+)/u', $c, $mm)) {
                    for ($i = 0; $i < count($mm[0]); $i++) $addFact($mm[1][$i], $mm[2][$i]);
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
                if ($outer === $inner) continue;
                $compName = "$outer($inner)";
                if (isset($this->strategies[$compName])) continue;
                $innerFn = $this->strategies[$inner];
                $outerFn = $this->strategies[$outer];
                $composed[$compName] = function(string $c) use ($innerFn, $outerFn): array {
                    $innerResult = $innerFn($c);
                    if (!$innerResult) return [];
                    $asString = json_encode($innerResult);
                    return $outerFn($asString);
                };
            }
        }
        return $composed;
    }

    public function scan(array $dirs): array
    {
        $sorted = $dirs;
        arsort($sorted);
        $allTasks = [];
        $maxTotal = 30;
        $allStrategies = array_merge($this->strategies, $this->getComposedStrategies());

        foreach ($sorted as $dir => $pri) {
            if (count($allTasks) >= $maxTotal) break;
            if (!is_dir($dir)) continue;
            $dirTasks = $this->scanDir($dir, $maxTotal - count($allTasks), $allStrategies);
            $allTasks = array_merge($allTasks, $dirTasks);
            if (count($dirTasks) > 0) {
                $this->priorities[$dir] = min(1.0, $pri + count($dirTasks) * 0.05);
            }
        }

        // Compute fingerprint from scanned file paths
        $paths = [];
        foreach ($sorted as $dir => $pri) {
            if (!is_dir($dir)) continue;
            try {
                $iter = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($iter as $f) {
                    try { $paths[] = $f->getPathname(); }
                    catch (\Throwable) {}
                }
            } catch (\Throwable) {}
        }
        sort($paths);
        $this->currentFingerprint = md5(implode(',', $paths));
        
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

    /** ≥1 новый домен ИЛИ ≥5 новых задач с последнего consume */
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

    /** Запомнить текущий скан как доложенный */
    public function markContentConsumed(): void
    {
        $this->lastReportedFingerprint = $this->currentFingerprint;
    }

    private function scanDir(string $dir, int $max, array $strategies): array
    {
        $tasks = [];
        $count = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
        } catch (\Throwable $e) {
            return [];
        }

        foreach ($iterator as $file) {
            if ($count >= $max) break;
            try { $path = $file->getPathname(); } catch (\Throwable $e) { continue; }
            try { $size = $file->getSize(); } catch (\Throwable $e) { continue; }
            if ($size > 500_000) continue;
            if (str_contains($path, '.git/') || str_contains($path, 'venv/') || str_contains($path, 'node_modules/')) continue;
            if (str_contains($path, '/.cache/') || str_contains($path, '/.local/share/')) continue;
            if (str_contains($path, '/.mozilla/') || str_contains($path, '/.config/')) continue;
            if (str_contains($path, '/.symfony') || str_contains($path, '/.composer/')) continue;

            $content = @file_get_contents($path);
            if (!$content) continue;

            foreach ($strategies as $sname => $strategy) {
                $rows = $strategy($content);
                $this->strategyScores[$sname] = ($this->strategyScores[$sname] ?? 0) + count($rows);

                $isSemantic = isset($rows[0]['semantic']) && $rows[0]['semantic'] === true;
                $minRows = $isSemantic ? 1 : 3;
                if (count($rows) < $minRows) continue;
                
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
                            
                            // ═══ KG INSERT: замыкаем петлю ═══
                            $dbCheck = Database::get()->prepare(
                                "SELECT confidence FROM knowledge_graph WHERE subject=? AND predicate=? AND object=?"
                            );
                            $dbCheck->execute([$s, $pred, $o]);
                            $existing = $dbCheck->fetchColumn();
                            if ($existing !== false) {
                                // Повторное обнаружение → повышаем confidence
                                $newConf = min(1.0, (float)$existing + 0.25);
                                Database::get()->prepare(
                                    "UPDATE knowledge_graph SET confidence=? WHERE subject=? AND predicate=? AND object=?"
                                )->execute([$newConf, $s, $pred, $o]);
                            } else {
                                // Первое обнаружение → низкий confidence
                                Database::get()->prepare(
                                    "INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence) VALUES (?,?,?,0.3)"
                                )->execute([$s, $pred, $o]);
                            }
                        }
                    }
                    
                    // Negative examples: cross-product субъектов и объектов где нет связи
                    $uniqSubjects = array_unique($subjects);
                    $uniqObjects = array_unique($objects);
                    $negCount = 0;
                    foreach ($uniqSubjects as $s) {
                        foreach ($uniqObjects as $o) {
                            if (isset($positivePairs[$s . '::' . $o])) continue;
                            $sh = ConceptRegistry::register($s);
                            $oh = ConceptRegistry::register($o);
                            $data[] = [$sh, $oh, 0.0];
                            $negCount++;
                            if ($negCount >= 10) break 2; // лимит отрицательных примеров
                        }
                    }
                    
                    if (count($data) >= 2) {  // минимум 2 точки: positive + negative
                        $tasks[] = ['name' => 'foraged_sem_' . basename($path), 'data' => $data, 'domain' => 'foraged_semantic'];
                        $count++;
                    }
                } else {
                    if (isset($rows[0]) && count($rows[0]) >= 2) {
                        $nCols = count($rows[0]);
                        for ($c1 = 0; $c1 < min($nCols, 4); $c1++) {
                            for ($c2 = $c1 + 1; $c2 < min($nCols, 4); $c2++) {
                                $data = [];
                                foreach ($rows as $r) {
                                    if (isset($r[$c1], $r[$c2])) $data[] = [(float)$r[$c1], (float)$r[$c2]];
                                }
                                if (count($data) >= 3) {
                                    $tasks[] = ['name' => 'foraged_' . basename($path) . "_c{$c1}c{$c2}", 'data' => $data, 'domain' => 'foraged'];
                                    $count++;
                                }
                            }
                        }
                    }
                }
            }
        }
        return $tasks;
    }
}
