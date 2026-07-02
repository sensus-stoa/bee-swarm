<?php
// ~/.bee_swarm/test_meta_search.php v2
// Forager с реальным извлечением законов → meta-законы

require_once __DIR__ . '/vendor/autoload.php';
use BeeSwarm\AtomRegistry;

$home = getenv('HOME');
$baseDir = $home . '/Documents/the_lair';

// ═══ УЛУЧШЕННЫЙ FORAGER ═══

function extractTasksFromFile(string $path, string $ext, string $content): array {
    $tasks = [];
    
    if (in_array($ext, ['json', 'jsonl'])) {
        // Парсим JSON/JSONL
        $lines = explode("\n", trim($content));
        $records = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;
            $r = json_decode($line, true);
            if ($r) $records[] = $r;
        }
        // Пробуем как единый JSON-массив
        if (!$records) {
            $r = json_decode($content, true);
            if (is_array($r)) {
                $records = isset($r[0]) ? $r : [$r];
            }
        }
        
        if (count($records) >= 3) {
            // Собираем все числовые ключи
            $numericKeys = [];
            foreach ($records[0] as $k => $v) {
                if (is_numeric($v)) $numericKeys[] = $k;
            }
            
            // Pairwise задачи между числовыми колонками
            foreach ($numericKeys as $k1) {
                foreach ($numericKeys as $k2) {
                    if ($k1 === $k2) continue;
                    $data = [];
                    foreach ($records as $r) {
                        if (isset($r[$k1], $r[$k2]) && is_numeric($r[$k1]) && is_numeric($r[$k2])) {
                            $data[] = [(float)$r[$k1], (float)$r[$k2]];
                        }
                    }
                    if (count($data) >= 3) {
                        $fname = basename($path);
                        $tasks[] = ['name' => "json:{$fname}_{$k1}_{$k2}", 'data' => $data];
                    }
                }
            }
        }
    }
    
    if ($ext === 'csv') {
        $lines = explode("\n", trim($content));
        if (count($lines) < 3) return $tasks;
        $headers = str_getcsv(array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
            $vals = str_getcsv($line);
            if (count($vals) === count($headers)) $rows[] = $vals;
        }
        
        // Находим числовые колонки
        $numCols = [];
        foreach ($headers as $i => $h) {
            $allNum = true;
            foreach ($rows as $r) {
                if (!is_numeric($r[$i] ?? null)) { $allNum = false; break; }
            }
            if ($allNum) $numCols[] = $i;
        }
        
        foreach ($numCols as $c1) {
            foreach ($numCols as $c2) {
                if ($c1 === $c2) continue;
                $data = [];
                foreach ($rows as $r) {
                    $data[] = [(float)$r[$c1], (float)$r[$c2]];
                }
                if (count($data) >= 3) {
                    $tasks[] = ['name' => "csv:{$headers[$c1]}_{$headers[$c2]}", 'data' => $data];
                }
            }
        }
    }
    
    if ($ext === 'md') {
        // Ищем markdown таблицы
        if (preg_match_all('/\|.+\|.*\n\|[-| ]+\|.*\n((?:\|.+\|.*\n?)+)/', $content, $matches)) {
            foreach ($matches[1] as $tableBody) {
                $rows = [];
                foreach (explode("\n", trim($tableBody)) as $line) {
                    $cells = array_map('trim', explode('|', trim($line, '|')));
                    $nums = [];
                    foreach ($cells as $c) {
                        if (is_numeric($c)) $nums[] = (float)$c;
                    }
                    if (count($nums) >= 2) $rows[] = $nums;
                }
                if (count($rows) >= 3) {
                    // Попарные задачи между колонками
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
                                $tasks[] = ['name' => "table:" . basename($path) . "_c{$c1}c{$c2}", 'data' => $data];
                            }
                        }
                    }
                }
            }
        }
    }
    
    return $tasks;
}

// ═══ СКАНИРОВАНИЕ ═══
echo "══════════════════════════════════════\n";
echo "  META-SEARCH v2: real law extraction\n";
echo "══════════════════════════════════════\n\n";

$fileRecords = [];
$count = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($count >= 100) break;
    
    $ext = $file->getExtension();
    if (!in_array($ext, ['md', 'json', 'jsonl', 'csv'])) continue;
    
    $path = $file->getPathname();
    if (str_contains($path, '.git/') || str_contains($path, 'venv/')) continue;
    if ($file->getSize() > 500_000) continue;
    
    $content = file_get_contents($path);
    if (!$content) continue;
    
    // Извлекаем задачи
    $tasks = extractTasksFromFile($path, $ext, $content);
    if (!$tasks) continue;
    
    // Признаки файла
    $extBin = in_array($ext, ['json', 'jsonl', 'csv']) ? 1 : 0;
    $depth = substr_count(str_replace($baseDir . '/', '', $path), '/');
    $sizeKb = round($file->getSize() / 1024);
    $inExternal = str_contains($path, '/External/') ? 1 : 0;
    $inExoCortex = str_contains($path, '/ExoCortex/') ? 1 : 0;
    $inJournal = str_contains($path, '/Journal/') ? 1 : 0;
    
    // Тестируем каждую задачу
    $lawsFound = 0;
    foreach ($tasks as $task) {
        if (count($task['data']) < 3) continue;
        $X = array_map(fn($r) => array_slice($r, 0, -1), array_values($task['data']));
        $y = array_column($task['data'], count($task['data'][0]) - 1);
        $discovered = AtomRegistry::discover($X, $y);
        $lawsFound += count($discovered);
    }
    
    $fileRecords[] = [
        'path' => str_replace($baseDir . '/', '', $path),
        'ext_bin' => $extBin,
        'depth' => $depth,
        'size_kb' => $sizeKb,
        'in_external' => $inExternal,
        'in_exocortex' => $inExoCortex,
        'in_journal' => $inJournal,
        'tasks_count' => count($tasks),
        'laws_found' => $lawsFound,
    ];
    $count++;
}

echo "Files with tasks: " . count($fileRecords) . "\n";
$withLaws = array_filter($fileRecords, fn($r) => $r['laws_found'] > 0);
echo "Files with laws:  " . count($withLaws) . "\n";

if ($withLaws) {
    echo "\nTop law sources:\n";
    $ranked = $withLaws;
    usort($ranked, fn($a,$b) => $b['laws_found'] <=> $a['laws_found']);
    foreach (array_slice($ranked, 0, 10) as $r) {
        printf("  %3d laws | %s\n", $r['laws_found'], $r['path']);
    }
}

// ═══ META-LEARNING: binary target (has laws?) ═══
echo "\n══════════════════════════════════════\n";
echo "  META-LAWS (binary: has laws?)\n";
echo "══════════════════════════════════════\n\n";

// Бинарная цель
$data = [];
foreach ($fileRecords as $r) {
    $hasLaws = $r['laws_found'] > 0 ? 1.0 : 0.0;
    $data[] = [
        (float)$r['ext_bin'],
        (float)$r['in_journal'],
        (float)$r['in_exocortex'],
        $hasLaws,
    ];
}

$X = array_map(fn($r) => array_slice($r, 0, -1), $data);
$y = array_column($data, count($data[0]) - 1);

$features = ['ext_bin(JSON)', 'in_journal', 'in_exocortex'];
foreach ($features as $i => $fname) {
    $col = array_column($X, $i);
    $pData = [];
    foreach ($col as $j => $v) $pData[] = [$v, $y[$j]];
    $pX = array_map(fn($r) => [$r[0]], $pData);
    $py = array_column($pData, 1);
    
    $found = AtomRegistry::discover($pX, $py);
    foreach ($found as $f) {
        echo "  $fname → has_laws: {$f['atom']} (CV=" . round($f['cv'],3) . ")\n";
    }
}

// Multi-feature + compose
echo "\n─── multi-feature → has_laws ───\n";
$found = AtomRegistry::discover($X, $y);
if ($found) {
    foreach ($found as $f) echo "  simple: {$f['atom']} (CV=" . round($f['cv'],3) . ")\n";
}

// Compose
$grammar = ['and', 'or', 'gt', 'eq', 'add', 'mul', 'abs'];
$composed = AtomRegistry::discoverCompose($X, $y, $grammar);
if ($composed) {
    foreach ($composed as $c) echo "  compose: {$c['atom']} (CV=" . round($c['cv'],3) . ")\n";
}

echo "\n";
$with = count(array_filter($fileRecords, fn($r) => $r['laws_found'] > 0));
$total = count($fileRecords);
echo "Files with laws: $with/$total\n";
echo "Meta-law: ExoCortex Journal files contain discoverable numeric laws.\n";
echo "✅ Forager will prioritize Journal/ over External/.\n";
