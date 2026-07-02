<?php
// ~/.bee_swarm/test_self_modify.php v2
// Рой сканирует любые файлы, эволюционирует стратегии и приоритеты

$home = getenv('HOME');
$configFile = __DIR__ . '/data/forage_config.json';

require_once __DIR__ . '/vendor/autoload.php';
use BeeSwarm\AtomRegistry;

// ═══ КОНФИГ ═══
$config = file_exists($configFile) 
    ? json_decode(file_get_contents($configFile), true) 
    : [
        'priorities' => [],     // "ext:dir" → priority (0-1)
        'strategy_weights' => [ // strategy → success_rate
            'markdown_tables' => 1.0,
            'json_keys' => 1.0,
            'csv_columns' => 1.0,
            'numeric_lines' => 0.5,
        ],
        'scan_dirs' => [        // директории для сканирования
            $home . '/Documents/the_lair' => 0.5,
        ],
        'history' => [],
        'generation' => 0,
    ];

$config['generation']++;

echo "══════════════════════════════════════\n";
echo "  SELF-MODIFYING FORAGER gen {$config['generation']}\n";
echo "══════════════════════════════════════\n\n";

// ═══ СТРАТЕГИИ ═══
$strategies = [
    'markdown_tables' => function(string $content): array {
        $tasks = [];
        if (preg_match_all('/\|.+\|.*\n\|[-| ]+\|.*\n((?:\|.+\|.*\n?)+)/', $content, $m)) {
            foreach ($m[1] as $table) {
                $rows = [];
                foreach (explode("\n", trim($table)) as $line) {
                    $cells = array_map('trim', explode('|', trim($line, '|')));
                    $nums = array_filter($cells, 'is_numeric');
                    if (count($nums) >= 2) $rows[] = array_map('floatval', $nums);
                }
                if (count($rows) >= 3) {
                    for ($c1 = 0; $c1 < count($rows[0]); $c1++)
                        for ($c2 = $c1+1; $c2 < count($rows[0]); $c2++) {
                            $data = [];
                            foreach ($rows as $r) if (isset($r[$c1],$r[$c2])) $data[] = [$r[$c1], $r[$c2]];
                            if (count($data) >= 3) $tasks[] = ['name'=>'table_c'.$c1.'c'.$c2, 'data'=>$data];
                        }
                }
            }
        }
        return $tasks;
    },
    'json_keys' => function(string $content): array {
        $tasks = [];
        $r = json_decode($content, true);
        if (!$r || !is_array($r)) return $tasks;
        if (!isset($r[0])) $r = [$r];
        if (count($r) < 3) return $tasks;
        $numKeys = [];
        foreach ($r[0] as $k => $v) if (is_numeric($v)) $numKeys[] = $k;
        foreach ($numKeys as $k1) foreach ($numKeys as $k2) {
            if ($k1 === $k2) continue;
            $data = [];
            foreach ($r as $row) if (isset($row[$k1],$row[$k2]) && is_numeric($row[$k1]) && is_numeric($row[$k2]))
                $data[] = [(float)$row[$k1], (float)$row[$k2]];
            if (count($data) >= 3) $tasks[] = ['name'=>"json_{$k1}_{$k2}", 'data'=>$data];
        }
        return $tasks;
    },
    'csv_columns' => function(string $content): array {
        $tasks = [];
        $lines = explode("\n", trim($content));
        if (count($lines) < 3) return $tasks;
        $headers = str_getcsv(array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
            $vals = str_getcsv($line);
            if (count($vals) === count($headers)) $rows[] = $vals;
        }
        if (count($rows) < 3) return $tasks;
        $numCols = [];
        foreach ($headers as $i => $h) {
            $allNum = true;
            foreach ($rows as $r) if (!is_numeric($r[$i]??null)) { $allNum = false; break; }
            if ($allNum) $numCols[] = $i;
        }
        foreach ($numCols as $c1) foreach ($numCols as $c2) {
            if ($c1 === $c2) continue;
            $data = [];
            foreach ($rows as $r) $data[] = [(float)$r[$c1], (float)$r[$c2]];
            if (count($data) >= 3) $tasks[] = ['name'=>"csv_{$headers[$c1]}_{$headers[$c2]}", 'data'=>$data];
        }
        return $tasks;
    },
    'numeric_lines' => function(string $content): array {
        $tasks = [];
        $lines = explode("\n", $content);
        $allNums = [];
        foreach ($lines as $line) {
            preg_match_all('/-?\d+\.?\d*/', $line, $m);
            if (count($m[0]) >= 2) $allNums[] = array_map('floatval', $m[0]);
        }
        if (count($allNums) >= 3) {
            $data = [];
            foreach ($allNums as $nums) if (count($nums) >= 2) $data[] = [$nums[0], $nums[1]];
            if (count($data) >= 3) $tasks[] = ['name'=>'numlines_c0c1', 'data'=>$data];
        }
        return $tasks;
    },
];

// ═══ АВТО-РАСШИРЕНИЕ ДИРЕКТОРИЙ ═══
// Если топ-директория дала законы в прошлом поколении, добавляем родительскую
if ($config['generation'] > 1 && $config['history']) {
    $last = end($config['history']);
    if (isset($last['top_source'])) {
        $topDir = dirname(str_replace(':', '/', $last['top_source']));
        $parent = dirname($home . '/Documents/the_lair/' . $topDir);
        if (!isset($config['scan_dirs'][$parent])) {
            $config['scan_dirs'][$parent] = 0.3;
            echo "🌿 Auto-expanded: added $parent (parent of top source)\n";
        }
    }
}

// ═══ ПРИОРИТЕТНОЕ СКАНИРОВАНИЕ ═══
echo "Scan dirs (" . count($config['scan_dirs']) . "):\n";
foreach ($config['scan_dirs'] as $dir => $pri) {
    echo "  $dir (priority=$pri)\n";
}
echo "\n";

$results = [];       // [combo => [strategy => laws]]
$strategyStats = []; // [strategy => [tried => N, success => N]]

$totalFiles = 0;
$maxFiles = 30;

// Сортируем директории по приоритету
$scanOrder = $config['scan_dirs'];
arsort($scanOrder);

foreach ($scanOrder as $dir => $dirPriority) {
    if ($totalFiles >= $maxFiles) break;
    if (!is_dir($dir)) continue;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($iterator as $file) {
        if ($totalFiles >= $maxFiles) break;
        
        $path = $file->getPathname();
        if (str_contains($path, '.git/') || str_contains($path, 'venv/') || str_contains($path, 'node_modules/')) continue;
        if ($file->getSize() > 500_000) continue;
        
        $content = file_get_contents($path);
        if (!$content) continue;
        
        $relPath = str_replace($dir . '/', '', $path);
        $ext = $file->getExtension();
        $combo = "$ext:" . dirname($relPath);
        $basePriority = $config['priorities'][$combo] ?? $dirPriority;
        
        $fileTotalLaws = 0;
        
        // Применяем стратегии в порядке их веса
        $stratOrder = $config['strategy_weights'];
        arsort($stratOrder);
        
        foreach ($stratOrder as $sname => $weight) {
            if (!isset($strategies[$sname])) continue;
            
            $tasks = $strategies[$sname]($content);
            $lawsFound = 0;
            
            foreach ($tasks as $task) {
                if (count($task['data']) < 3) continue;
                $X = array_map(fn($r) => array_slice($r, 0, -1), array_values($task['data']));
                $y = array_column($task['data'], count($task['data'][0]) - 1);
                $lawsFound += count(AtomRegistry::discover($X, $y));
            }
            
            $strategyStats[$sname]['tried'] = ($strategyStats[$sname]['tried'] ?? 0) + 1;
            $strategyStats[$sname]['success'] = ($strategyStats[$sname]['success'] ?? 0) + ($lawsFound > 0 ? 1 : 0);
            
            if ($lawsFound > 0) {
                $results[$combo][$sname] = ($results[$combo][$sname] ?? 0) + $lawsFound;
                $fileTotalLaws += $lawsFound;
            }
        }
        
        // Обновляем приоритет комбинации ext:dir
        if ($fileTotalLaws > 0) {
            $oldPriority = $config['priorities'][$combo] ?? $basePriority;
            $config['priorities'][$combo] = min(1.0, $oldPriority + 0.05 * $fileTotalLaws);
        }
        
        $totalFiles++;
    }
}

// ═══ ОБНОВЛЕНИЕ ВЕСОВ СТРАТЕГИЙ ═══
foreach ($strategyStats as $sname => $stats) {
    if ($stats['tried'] > 0) {
        $rate = $stats['success'] / $stats['tried'];
        // Плавное обновление: 70% старый вес + 30% новый опыт
        $oldWeight = $config['strategy_weights'][$sname] ?? 1.0;
        $config['strategy_weights'][$sname] = round($oldWeight * 0.7 + $rate * 0.3, 2);
    }
}

// ═══ ВЫВОД ═══
echo "─── RESULTS ───\n\n";
echo "Files scanned: $totalFiles\n";

if ($results) {
    $ranked = [];
    foreach ($results as $combo => $strats) $ranked[$combo] = array_sum($strats);
    arsort($ranked);
    
    echo "\nTop sources:\n";
    foreach (array_slice($ranked, 0, 8) as $combo => $laws) {
        $pri = $config['priorities'][$combo] ?? '?';
        $stratList = implode(',', array_keys($results[$combo]));
        printf("  %-45s laws=%3d  pri=%.2f  via=%s\n", $combo, $laws, $pri, $stratList);
    }
}

echo "\nStrategy weights (evolved):\n";
arsort($config['strategy_weights']);
foreach ($config['strategy_weights'] as $sname => $w) {
    $stats = $strategyStats[$sname] ?? ['tried'=>0,'success'=>0];
    printf("  %-20s weight=%.2f  hit=%d/%d\n", $sname, $w, $stats['success'], $stats['tried']);
}

// ═══ АВТО-ДОБАВЛЕНИЕ УСПЕШНЫХ ДИРЕКТОРИЙ ═══
if ($ranked) {
    $topCombo = array_key_first($ranked);
    [$topExt, $topSubdir] = explode(':', $topCombo . ':');
    $topFullDir = $home . '/Documents/the_lair/' . $topSubdir;
    if (is_dir($topFullDir) && !isset($config['scan_dirs'][$topFullDir])) {
        $config['scan_dirs'][$topFullDir] = 0.8;
        echo "\n🌿 NEW: added $topFullDir as priority scan dir\n";
    }
}

// ═══ СОХРАНЕНИЕ ═══
$config['history'][] = [
    'gen' => $config['generation'],
    'time' => date('Y-m-d H:i:s'),
    'files_scanned' => $totalFiles,
    'sources_found' => count($results),
    'top_source' => $ranked ? array_key_first($ranked) : 'none',
    'top_strategy' => array_key_first($config['strategy_weights']),
];

@mkdir(dirname($configFile), 0755, true);
file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\nConfig: $configFile (gen {$config['generation']})\n";
echo "History: " . count($config['history']) . " generations\n";
echo "Scan dirs: " . count($config['scan_dirs']) . " total\n";
