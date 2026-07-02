<?php
// ТЕСТ: накопление сигнала в шумных доменах
// Сигнал чистый если: атом работает лучше baseline на N доменах

$home = getenv('HOME');
$metricsFile = $home . '/Documents/the_lair/ExoCortex/Journal/global/metrics.jsonl';
$journalDir  = $home . '/Documents/the_lair/ExoCortex/Journal/2026';

// ═══ ЗАГРУЗКА ═══
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

// ═══ ЗАДАЧИ ПО ДОМЕНАМ ═══
$domains = [];

// ДОМЕН 1: words→metric
$mlist = ['stress','energy','dq','gi','discipline','sleep','intact'];
$domains['words_to_metric'] = [];
foreach ($mlist as $metric) {
    $data = [];
    foreach ($commonDates as $d) {
        $y = $metricsByDate[$d][$metric] ?? null;
        $w = $wordsByDate[$d] ?? 0;
        if ($y !== null && $w > 0) $data[] = [(float)$w, (float)$y];
    }
    if (count($data) >= 10) $domains['words_to_metric'][] = ['name'=>$metric, 'data'=>$data];
}

// ДОМЕН 2: day#→metric
$domains['time_to_metric'] = [];
foreach ($mlist as $metric) {
    $data = [];
    for ($i = 0; $i < count($commonDates); $i++) {
        $y = $metricsByDate[$commonDates[$i]][$metric] ?? null;
        if ($y !== null) $data[] = [(float)$i, (float)$y];
    }
    if (count($data) >= 10) $domains['time_to_metric'][] = ['name'=>$metric, 'data'=>$data];
}

// ДОМЕН 3: metric→metric
$domains['metric_to_metric'] = [];
foreach ($mlist as $m1) {
    foreach ($mlist as $m2) {
        if ($m1 === $m2) continue;
        $data = [];
        foreach ($commonDates as $d) {
            $x = $metricsByDate[$d][$m1] ?? null;
            $y = $metricsByDate[$d][$m2] ?? null;
            if ($x !== null && $y !== null) $data[] = [(float)$x, (float)$y];
        }
        if (count($data) >= 10) $domains['metric_to_metric'][] = ['name'=> $m1 . '_to_' . $m2, 'data'=>$data];
    }
}

// ДОМЕН 4: mentions→metric
$allConcepts = [];
foreach ($conceptsByDate as $counts) foreach ($counts as $c => $n) $allConcepts[$c] = ($allConcepts[$c]??0) + $n;
arsort($allConcepts);
$topConcepts = array_slice(array_keys($allConcepts), 0, 10);
$domains['mentions_to_metric'] = [];
foreach (['stress','dq','energy','intact'] as $metric) {
    foreach ($topConcepts as $concept) {
        $data = [];
        foreach ($commonDates as $d) {
            $y = $metricsByDate[$d][$metric] ?? null;
            $m = $conceptsByDate[$d][$concept] ?? 0;
            if ($y !== null) $data[] = [(float)$m, (float)$y];
        }
        if (count($data) >= 10 && count(array_unique(array_column($data,0))) >= 3) {
            $domains['mentions_to_metric'][] = ['name'=> $concept . '_to_' . $metric, 'data'=>$data];
        }
    }
}

echo "══════════════════════════════════════\n";
echo "  SIGNAL ACCUMULATION (noisy domains)\n";
echo "══════════════════════════════════════\n\n";

foreach ($domains as $dname => $tasks) {
    echo "$dname: " . count($tasks) . " tasks\n";
}

// ═══ АТОМЫ + BASELINE ═══
$mathNames = ['abs','sqrt','log','log10','exp','sin','cos','tan','floor','ceil','round'];
$atoms = [];
// Строковые имена → Closure (by-value capture!)
foreach ($mathNames as $name) {
    $fnName = $name; // capture by value
    $atoms[$fnName] = fn($x) => $fnName($x);
}
// Кастомные
$atoms['sq']   = fn($x) => $x*$x;
$atoms['cube'] = fn($x) => $x*$x*$x;
$atoms['inv']  = fn($x) => $x!=0 ? 1/$x : null;
$atoms['neg']  = fn($x) => -$x;
// Baseline: константы
for ($k = 0; $k <= 20; $k += 2) {
    $kv = $k / 2;
    $atoms["K$kv"] = fn($x) => $kv;
}

// ═══ ОЦЕНКА: КАЖДЫЙ АТОМ НА КАЖДОМ ДОМЕНЕ ═══
// Для каждой задачи: CV атома vs CV лучшей константы (baseline)
// Сигнал = Σ(max(0, baseline_CV - atom_CV)) по всем задачам домена
// Отрицательный сигнал = атом ХУЖЕ константы

function taskCV($fn, array $data): float {
    $X = array_column($data, 0);
    $y = array_column($data, 1);
    $vec = [];
    foreach ($X as $x) {
        $v = $fn($x);
        if ($v === null || is_nan($v) || is_infinite($v)) return 9.99;
        $vec[] = $v;
    }
    $n = count($vec);
    for ($i = 0; $i < $n; $i++) {
        if (abs($vec[$i] - $y[$i]) > 0.001) break;
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

// Считаем baseline (лучшая константа) для каждой задачи
$baselineCV = []; // [domain][task_name] => best_constant_CV
foreach ($domains as $dname => $tasks) {
    foreach ($tasks as $task) {
        $bestConstCV = 9.99;
        for ($k = 0; $k <= 20; $k += 2) {
            $kv = $k / 2;
            $fn = fn($x) => $kv;
            $cv = taskCV($fn, $task['data']);
            if ($cv < $bestConstCV) $bestConstCV = $cv;
        }
        $baselineCV[$dname][$task['name']] = $bestConstCV;
    }
}

// Оцениваем каждый атом: сигнал = превышение над baseline
$atomSignals = []; // [atom => [domain => signal, ...]]

foreach ($atoms as $name => $fn) {
    if (!($fn instanceof Closure)) continue;
    
    $totalSignal = 0;
    $domainsPositive = 0;
    $domainSignals = [];
    
    foreach ($domains as $dname => $tasks) {
        $domainSignal = 0;
        $better = 0; $worse = 0;
        
        foreach ($tasks as $task) {
            $atomCV = taskCV($fn, $task['data']);
            $baseCV = $baselineCV[$dname][$task['name']];
            $delta = $baseCV - $atomCV; // положительный = атом лучше baseline
            $domainSignal += $delta;
            if ($delta > 0.01) $better++;
            elseif ($delta < -0.01) $worse++;
        }
        
        if ($domainSignal > 0) $domainsPositive++;
        $domainSignals[$dname] = $domainSignal;
        $totalSignal += $domainSignal;
    }
    
    if (abs($totalSignal) > 0.001) {
        $atomSignals[$name] = [
            'total' => $totalSignal,
            'domains_pos' => $domainsPositive,
            'domains_total' => count($domains),
            'by_domain' => $domainSignals,
        ];
    }
}

// ═══ ВЫВОД ═══
echo "\n══════════════════════════════════════════════════════\n";
echo "  SIGNAL = Σ(baseline_const - atom_CV) across domains\n";
echo "  Positive = atom beats constant. Negative = worse.\n";
echo "══════════════════════════════════════════════════════\n\n";

// Сортируем по total signal
uasort($atomSignals, fn($a,$b) => $b['total'] <=> $a['total']);

echo sprintf("%-8s %8s %6s  %s\n", "ATOM", "SIGNAL", "#DOM+", "BY DOMAIN (signal per domain)");
echo str_repeat("─", 85) . "\n";

$strongAtoms = [];
$weakAtoms = [];
$noiseAtoms = [];

foreach ($atomSignals as $name => $s) {
    $domainStr = '';
    foreach ($s['by_domain'] as $dom => $sig) {
        $domainStr .= substr($dom, 0, 6) . ':' . round($sig,2) . ' ';
    }
    
    if ($s['domains_pos'] >= 3 && $s['total'] > 1.0) {
        $verdict = '🔥 STRONG';
        $strongAtoms[] = $name;
    } elseif ($s['domains_pos'] >= 2 && $s['total'] > 0) {
        $verdict = '📈 MODERATE';
        $weakAtoms[] = $name;
    } elseif ($s['total'] > 0) {
        $verdict = '· weak';
    } else {
        $verdict = '❌ noise';
        $noiseAtoms[] = $name;
    }
    
    printf("%-8s %8.3f %6d  %s %s\n", 
        $name, $s['total'], $s['domains_pos'], $domainStr, $verdict);
}

// ═══ ИТОГ ═══
echo "\n══════════════════════════════════════\n";
echo "  PROMOTION CANDIDATES\n";
echo "══════════════════════════════════════\n";
echo "STRONG (≥3 domains, signal>1): " . implode(', ', $strongAtoms) . "\n";
echo "MODERATE (≥2 domains):        " . implode(', ', $weakAtoms) . "\n";

// Проверка: если atom побеждает на всех метриках одного домена — это паттерн
if ($strongAtoms) {
    echo "\nДетально по strong атомам:\n";
    foreach ($strongAtoms as $atom) {
        echo "  $atom:\n";
        foreach ($atomSignals[$atom]['by_domain'] as $dom => $sig) {
            echo "    $dom: " . round($sig,3) . "\n";
        }
    }
}

echo "\nDone.\n";
