<?php
// Быстрый тест: какие кросс-доменные CV самые низкие?

$home = getenv('HOME');
$metricsFile = $home . '/Documents/the_lair/ExoCortex/Journal/global/metrics.jsonl';
$journalDir  = $home . '/Documents/the_lair/ExoCortex/Journal/2026';

$metricsByDate = [];
foreach (file($metricsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $r = json_decode($line, true);
    if ($r && isset($r['date'])) $metricsByDate[$r['date']] = $r;
}

$wordsByDate = [];
$conceptsByDate = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($journalDir, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'md') continue;
    if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $file->getFilename(), $m)) continue;
    $date = "{$m[3]}-{$m[2]}-{$m[1]}";
    if (!isset($metricsByDate[$date])) continue;
    $content = file_get_contents($file->getPathname());
    $wordsByDate[$date] = str_word_count($content);
    preg_match_all('/\[\[([^\]|#]+)(?:[|#][^\]]+)?\]\]/', $content, $matches);
    $conceptsByDate[$date] = array_count_values($matches[1] ?? []);
}

$commonDates = array_values(array_intersect(array_keys($metricsByDate), array_keys($wordsByDate)));
sort($commonDates);

// ═══ ФУНКЦИИ: перебираем все отношения на всех кросс-доменных задачах ═══
$unaryFns = ['abs','sqrt','sin','cos','tan','exp','log','log10','floor','ceil','round','deg2rad'];
$binaryFns = ['min','max','hypot','pow','fmod'];
$allFns = array_merge($unaryFns, $binaryFns);

function calcCV(array $vec, array $y): float {
    $n = count($vec);
    for ($i = 0; $i < $n; $i++) {
        if (is_nan($vec[$i]) || is_infinite($vec[$i])) return 9.99;
        if (abs($vec[$i] - $y[$i]) > 0.0001) break;
        if ($i === $n-1) return 0.0;
    }
    $ratios = [];
    for ($i = 0; $i < $n; $i++) $ratios[] = $vec[$i] / ($y[$i] + 1e-8);
    $mean = array_sum($ratios) / $n;
    if (abs($mean) < 1e-8) return 9.99;
    $var = 0;
    foreach ($ratios as $r) $var += ($r - $mean) ** 2;
    return sqrt($var / $n) / abs($mean);
}

echo "══════════════════════════════════════════════════\n";
echo "  CROSS-DOMAIN: лучшие CV по категориям\n";
echo "  " . count($commonDates) . " общих дней\n";
echo "══════════════════════════════════════════════════\n\n";

// ═══ 1. WORDS → METRIC ═══
echo "─── words → metric ───\n";
foreach (['stress','energy','dq','gi','discipline','sleep','intact'] as $metric) {
    $data = [];
    foreach ($commonDates as $d) {
        $y = $metricsByDate[$d][$metric] ?? null;
        $w = $wordsByDate[$d] ?? 0;
        if ($y !== null && $w > 0) $data[] = [(float)$w, (float)$y];
    }
    $X = array_column($data, 0);
    $y = array_column($data, 1);
    
    $best = ['cv'=>9.99, 'fn'=>'none'];
    foreach ($allFns as $fn) {
        $vec = [];
        foreach ($X as $x) {
            $v = in_array($fn, $unaryFns) ? $fn($x) : $fn($x, 0);
            $vec[] = $v;
        }
        $cv = calcCV($vec, $y);
        if ($cv < $best['cv']) $best = ['cv'=>$cv, 'fn'=>$fn];
    }
    printf("  words→%-12s CV=%.4f  best=%s\n", $metric, $best['cv'], $best['fn']);
}

// ═══ 2. MENTIONS → METRIC ═══
$allConcepts = [];
foreach ($conceptsByDate as $counts) {
    foreach ($counts as $c => $n) {
        $allConcepts[$c] = ($allConcepts[$c] ?? 0) + $n;
    }
}
arsort($allConcepts);
$topConcepts = array_slice(array_keys($allConcepts), 0, 20);

echo "\n─── mentions → metric ───\n";
$bestCrossDomain = ['cv'=>9.99, 'name'=>'', 'fn'=>''];

foreach (['stress','dq','energy','intact'] as $metric) {
    foreach ($topConcepts as $concept) {
        $data = [];
        foreach ($commonDates as $d) {
            $y = $metricsByDate[$d][$metric] ?? null;
            $m = $conceptsByDate[$d][$concept] ?? 0;
            if ($y !== null) $data[] = [(float)$m, (float)$y];
        }
        if (count($data) < 10) continue;
        if (count(array_unique(array_column($data, 0))) < 2) continue;
        
        $X = array_column($data, 0);
        $y = array_column($data, 1);
        
        $best = ['cv'=>9.99, 'fn'=>'none'];
        foreach ($allFns as $fn) {
            $vec = [];
            foreach ($X as $x) {
                $v = in_array($fn, $unaryFns) ? $fn($x) : $fn($x, 0);
                $vec[] = $v;
            }
            $cv = calcCV($vec, $y);
            if ($cv < $best['cv']) $best = ['cv'=>$cv, 'fn'=>$fn];
        }
        
        if ($best['cv'] < 0.15) {
            printf("  %-15s → %-8s CV=%.4f  fn=%s\n", 
                substr($concept,0,15), $metric, $best['cv'], $best['fn']);
        }
        if ($best['cv'] < $bestCrossDomain['cv']) {
            $bestCrossDomain = ['cv'=>$best['cv'], 'name'=>"$concept→$metric", 'fn'=>$best['fn']];
        }
    }
}

// ═══ 3. DAY# → METRIC (тренд) ═══
echo "\n─── day# → metric (тренд) ───\n";
foreach (['stress','energy','dq','discipline','gi','sleep'] as $metric) {
    $data = [];
    for ($i = 0; $i < count($commonDates); $i++) {
        $y = $metricsByDate[$commonDates[$i]][$metric] ?? null;
        if ($y !== null) $data[] = [(float)$i, (float)$y];
    }
    $X = array_column($data, 0);
    $y = array_column($data, 1);
    
    $best = ['cv'=>9.99, 'fn'=>'none'];
    foreach ($allFns as $fn) {
        $vec = [];
        foreach ($X as $x) {
            $v = in_array($fn, $unaryFns) ? $fn($x) : $fn($x, 0);
            $vec[] = $v;
        }
        $cv = calcCV($vec, $y);
        if ($cv < $best['cv']) $best = ['cv'=>$cv, 'fn'=>$fn];
    }
    printf("  day#→%-12s CV=%.4f  fn=%s\n", $metric, $best['cv'], $best['fn']);
}

// ═══ 4. METRIC → METRIC (pairwise) ═══
echo "\n─── metric → metric (pairwise) ───\n";
$metricsList = ['stress','energy','dq','gi','discipline','sleep','intact'];
foreach ($metricsList as $m1) {
    foreach ($metricsList as $m2) {
        if ($m1 === $m2) continue;
        $data = [];
        foreach ($commonDates as $d) {
            $x = $metricsByDate[$d][$m1] ?? null;
            $y = $metricsByDate[$d][$m2] ?? null;
            if ($x !== null && $y !== null) $data[] = [(float)$x, (float)$y];
        }
        if (count($data) < 10) continue;
        
        $X = array_column($data, 0);
        $y = array_column($data, 1);
        
        $best = ['cv'=>9.99, 'fn'=>'none'];
        foreach ($allFns as $fn) {
            $vec = [];
            foreach ($X as $x) {
                $v = in_array($fn, $unaryFns) ? $fn($x) : $fn($x, 0);
                $vec[] = $v;
            }
            $cv = calcCV($vec, $y);
            if ($cv < $best['cv']) $best = ['cv'=>$cv, 'fn'=>$fn];
        }
        
        if ($best['cv'] < 0.3) {
            printf("  %-10s→%-10s CV=%.4f  fn=%s\n", $m1, $m2, $best['cv'], $best['fn']);
        }
    }
}

// ═══ ИТОГ ═══
echo "\n══════════════════════════════════════════════════\n";
echo "  ЛУЧШИЙ КРОСС-ДОМЕННЫЙ РЕЗУЛЬТАТ\n";
echo "  {$bestCrossDomain['name']}: CV={$bestCrossDomain['cv']} fn={$bestCrossDomain['fn']}\n";
echo "══════════════════════════════════════════════════\n";

if ($bestCrossDomain['cv'] > 0.01) {
    echo "\nВывод: CV→0 не достигнут ни на одной кросс-доменной задаче.\n";
    echo "Причина: человеческие метрики + текст = шум, не инварианты.\n";
    echo "Нужен другой критерий: накопление слабого сигнала, не бинарный CV=0.\n";
}
