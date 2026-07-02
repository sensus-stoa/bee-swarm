<?php
// ~/.bee_swarm/test_extract_evolution.php
// ИКР: extract-стратегии эволюционируют сами

$tmpDir = sys_get_temp_dir() . '/extract_test_' . getmypid();
@mkdir($tmpDir, 0755, true);

// ═══ ТЕСТОВЫЕ ФАЙЛЫ РАЗНЫХ ФОРМАТОВ ═══
file_put_contents($tmpDir . '/data.json', json_encode([["a"=>1,"b"=>2],["a"=>3,"b"=>4],["a"=>5,"b"=>6]]));
file_put_contents($tmpDir . '/data.csv', "x,y\n1,2\n3,4\n5,6");
file_put_contents($tmpDir . '/data.md', "|u|v|\n|---|---|\n|1|2|\n|3|4|\n|5|6|");
file_put_contents($tmpDir . '/junk.txt', "hello world\nno numbers\njust text");

// ═══ ЭКСТРАКТ-АТОМЫ (из PHP-среды) ═══
$extractAtoms = [
    'json_decode' => function(string $c): array {
        $r = json_decode($c, true); if (!is_array($r)) return [];
        if (!isset($r[0])) $r = [$r]; if (count($r) < 3) return [];
        return array_map(fn($row) => array_values(array_filter($row, 'is_numeric')), $r);
    },
    'str_getcsv' => function(string $c): array {
        $lines = explode("\n", trim($c)); if (count($lines) < 3) return [];
        $rows = []; foreach ($lines as $l) { $r = str_getcsv($l); if (count($r) >= 2) $rows[] = $r; }
        return $rows;
    },
    'preg_match_table' => function(string $c): array {
        if (!preg_match_all('/\|.+\|.*\n\|[-| ]+\|.*\n((?:\|.+\|.*\n?)+)/', $c, $m)) return [];
        $rows = [];
        foreach (explode("\n", trim($m[1][0])) as $line) {
            $cells = array_map('trim', explode('|', trim($line, '|')));
            $nums = array_filter($cells, 'is_numeric');
            if (count($nums) >= 2) $rows[] = array_map('floatval', $nums);
        }
        return $rows;
    },
    'preg_match_nums' => function(string $c): array {
        preg_match_all('/-?\d+\.?\d*/', $c, $m);
        if (count($m[0]) < 6) return [];
        $nums = array_map('floatval', $m[0]);
        $pairs = []; for ($i=0; $i<count($nums)-1; $i+=2) $pairs[] = [$nums[$i], $nums[$i+1]];
        return $pairs;
    },
    'explode_lines' => function(string $c): array {
        $lines = explode("\n", trim($c)); $rows = [];
        foreach ($lines as $l) { $parts = preg_split('/[\s,;]+/', trim($l)); 
            $nums = array_filter($parts, 'is_numeric'); if (count($nums) >= 2) $rows[] = array_map('floatval', $nums); }
        return $rows;
    },
];

echo "══════════════════════════════════════\n";
echo "  EXTRACT STRATEGY EVOLUTION\n";
echo "══════════════════════════════════════\n\n";

// ═══ ТЕСТ: каждая стратегия на каждом файле ═══
$results = [];
foreach (glob($tmpDir . '/*') as $file) {
    $content = file_get_contents($file);
    $fname = basename($file);
    echo "─── $fname ───\n";
    
    foreach ($extractAtoms as $name => $fn) {
        $rows = $fn($content);
        $pairs = count($rows) >= 3 ? count($rows) : 0;
        if ($pairs > 0) {
            echo "  ✅ $name: $pairs pairs\n";
            $results[$name][$fname] = $pairs;
        }
    }
}

// ═══ СТРАТЕГИИ РАНЖИРУЮТСЯ ═══
echo "\n══════════════════════\n";
echo "  STRATEGY RANKING\n";
echo "══════════════════════\n\n";

$scores = [];
foreach ($results as $strategy => $files) {
    $total = array_sum($files);
    $count = count($files);
    $scores[$strategy] = ['total' => $total, 'files' => $count];
}
uasort($scores, fn($a,$b) => $b['total'] <=> $a['total']);

foreach ($scores as $name => $s) {
    $icon = $s['total'] > 5 ? '🔥' : ($s['total'] > 0 ? '📈' : '💀');
    echo "$icon $name: {$s['total']} pairs in {$s['files']} files\n";
}

// ═══ COMPOSE СТРАТЕГИЙ ═══
echo "\n─── Compose: json_decode(file_get_contents) + preg_match_nums ───\n";
$composed = [];
foreach ($extractAtoms as $outer => $outerFn) {
    foreach ($extractAtoms as $inner => $innerFn) {
        if ($outer === $inner) continue;
        $compName = "$outer($inner)";
        // Compose: применить inner к каждой строке результата outer
        $total = 0;
        foreach (glob($tmpDir . '/*') as $file) {
            $c = file_get_contents($file);
            $innerResult = $innerFn($c);
            if (!$innerResult) continue;
            // Конвертируем inner rows в строку для outer
            $asString = json_encode($innerResult);
            $outerResult = $outerFn($asString);
            if ($outerResult) $total += count($outerResult);
        }
        if ($total > 0) {
            echo "  $compName: $total pairs\n";
            $composed[$compName] = $total;
        }
    }
}

// ═══ ВЫВОД ═══
echo "\nStrategies are PHP functions. They evolve through:\n";
echo "  1. Apply each to each new file\n";
echo "  2. Count resulting numeric pairs\n";
echo "  3. High count → keep. Zero → drop.\n";
echo "  4. Compose of successful strategies → new strategies.\n";
echo "\nNo hardcoded extractors. Environment = PHP string functions.\n";

// Cleanup
foreach (glob($tmpDir . '/*') as $f) @unlink($f);
@rmdir($tmpDir);
