<?php
// ~/.bee_swarm/test_signal_accum.php
// ТЕСТ: сигнал атомов по всем доменам (арифметика + Obsidian)
// Сигнал = Σ(1−CV). Высокий сигнал → promote в grammar.

date_default_timezone_set('Europe/Moscow');

// ═══ ЗАГРУЗКА OBSIDIAN ═══
$home = getenv('HOME');
$metricsFile = $home . '/Documents/the_lair/ExoCortex/Journal/global/metrics.jsonl';
$journalDir  = $home . '/Documents/the_lair/ExoCortex/Journal/2026';

$metricsByDate = [];
if (file_exists($metricsFile)) {
    foreach (file($metricsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if ($r && isset($r['date'])) $metricsByDate[$r['date']] = $r;
    }
}

$wordsByDate = [];
$conceptsByDate = [];
if (is_dir($journalDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($journalDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'md') continue;
        $filename = $file->getFilename();
        if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $filename, $m)) continue;
        $date = "{$m[3]}-{$m[2]}-{$m[1]}";
        if (!isset($metricsByDate[$date])) continue;
        
        $content = file_get_contents($file->getPathname());
        $wordsByDate[$date] = str_word_count($content);
        
        preg_match_all('/\[\[([^\]|#]+)(?:[|#][^\]]+)?\]\]/', $content, $matches);
        $conceptsByDate[$date] = array_count_values($matches[1] ?? []);
    }
}

$commonDates = array_values(array_intersect(
    array_keys($metricsByDate), 
    array_keys($wordsByDate)
));
sort($commonDates);

echo "Obsidian: " . count($metricsByDate) . " metrics days, " 
     . count($wordsByDate) . " notes days, "
     . count($commonDates) . " common\n";

// ═══ ВСЕ ЗАДАЧИ ═══
$tasks = [];

// АРИФМЕТИКА
$arithmetic = [
    ['name'=>'ADD',   'data'=>[[1,2,3],[3,4,7],[5,6,11],[10,20,30]], 'domain'=>'arithmetic'],
    ['name'=>'MUL',   'data'=>[[1,2,2],[2,3,6],[3,4,12],[5,6,30]], 'domain'=>'arithmetic'],
    ['name'=>'MIN',   'data'=>[[0,0,0],[2,3,2],[5,1,1],[4,4,4],[10,3,3]], 'domain'=>'arithmetic'],
    ['name'=>'SQRT',  'data'=>[[1,1],[4,2],[9,3],[16,4],[25,5]], 'domain'=>'arithmetic'],
    ['name'=>'DIV',   'data'=>[[6,2,3],[12,3,4],[20,4,5],[10,2,5],[30,6,5]], 'domain'=>'arithmetic'],
    ['name'=>'SQUARE', 'data'=>[[1,1],[2,4],[3,9],[4,16],[5,25]], 'domain'=>'arithmetic'],
    ['name'=>'ABS',   'data'=>[[-3,3],[-1,1],[0,0],[2,2],[5,5]], 'domain'=>'arithmetic'],
    ['name'=>'POW2',  'data'=>[[0,1],[1,2],[2,4],[3,8],[4,16]], 'domain'=>'arithmetic'],
];
$tasks = $arithmetic;

// КРОСС-ДОМЕН: words → metric
$metricsList = ['stress','energy','dq','gi','discipline','sleep','intact'];
foreach ($metricsList as $metric) {
    $data = [];
    foreach ($commonDates as $d) {
        $y = $metricsByDate[$d][$metric] ?? null;
        $w = $wordsByDate[$d] ?? 0;
        if ($y !== null && $w > 0) $data[] = [(float)$w, (float)$y];
    }
    if (count($data) >= 10) {
        $tasks[] = ['name' => "words_to_$metric", 'data' => $data, 'domain' => 'cross_words'];
    }
}

// КРОСС-ДОМЕН: day_number → metric (тренд)
foreach ($metricsList as $metric) {
    $data = [];
    for ($i = 0; $i < count($commonDates); $i++) {
        $y = $metricsByDate[$commonDates[$i]][$metric] ?? null;
        if ($y !== null) $data[] = [(float)$i, (float)$y];
    }
    if (count($data) >= 10) {
        $tasks[] = ['name' => "daynum_to_$metric", 'data' => $data, 'domain' => 'cross_time'];
    }
}

// КРОСС-ДОМЕН: concept mentions → metric
if ($conceptsByDate) {
    $allConcepts = [];
    foreach ($conceptsByDate as $d => $counts) {
        foreach ($counts as $concept => $count) {
            if (!isset($allConcepts[$concept])) $allConcepts[$concept] = 0;
            $allConcepts[$concept] += $count;
        }
    }
    arsort($allConcepts);
    $topConcepts = array_slice(array_keys($allConcepts), 0, 15);
    
    foreach (['stress','dq','energy','intact'] as $metric) {
        foreach ($topConcepts as $concept) {
            $data = [];
            foreach ($commonDates as $d) {
                $y = $metricsByDate[$d][$metric] ?? null;
                $mentions = $conceptsByDate[$d][$concept] ?? 0;
                if ($y !== null) $data[] = [(float)$mentions, (float)$y];
            }
            $uniqueX = count(array_unique(array_column($data, 0)));
            if (count($data) >= 10 && $uniqueX >= 2) {
                $safeConcept = substr($concept, 0, 20);
                $tasks[] = ['name' => "mention_{$safeConcept}_to_$metric", 
                           'data' => $data, 'domain' => 'cross_concept'];
            }
        }
    }
}

echo "Tasks: " . count($tasks) . " (" . count($arithmetic) . " arithmetic + " 
     . (count($tasks) - count($arithmetic)) . " cross-domain)\n\n";

// ═══ АТОМЫ СРЕДЫ ═══
$unaryAtoms = ['abs','sqrt','sin','cos','tan','exp','log','log10','floor','ceil','round','deg2rad'];
$binaryAtoms = ['min','max','hypot','pow','fmod'];

// ═══ СИГНАЛ ═══
function signalStrength(array $vec, array $y): float {
    $n = count($vec);
    $exact = true;
    for ($i = 0; $i < $n; $i++) {
        if (abs(($vec[$i] ?? 9e99) - $y[$i]) > 0.001) { $exact = false; break; }
    }
    if ($exact) return 1.0;
    
    $ratios = [];
    for ($i = 0; $i < $n; $i++) {
        $denom = $y[$i] + 1e-8;
        if (abs($denom) < 1e-10) return 0.0;
        $ratios[] = ($vec[$i] ?? 0) / $denom;
    }
    $mean = array_sum($ratios) / $n;
    if (abs($mean) < 1e-8) return 0.0;
    $var = 0;
    foreach ($ratios as $r) $var += ($r - $mean) ** 2;
    $cv = sqrt($var / $n) / abs($mean);
    return max(0, 1 - min(1, $cv));
}

// ═══ ОЦЕНКА КАЖДОГО АТОМА ═══
$atomScores = [];

foreach (array_merge($unaryAtoms, $binaryAtoms) as $atom) {
    $totalSignal = 0;
    $domainsHit = [];
    $bestCv = 9.99;
    $tasksSolved = 0;
    $taskResults = [];
    
    foreach ($tasks as $task) {
        $data = $task['data'];
        $nFeat = count($data[0]) - 1;
        $y = array_column($data, $nFeat);
        $vec = [];
        $valid = true;
        
        foreach ($data as $row) {
            if (in_array($atom, $unaryAtoms)) {
                $v = $atom($row[0]);
            } elseif ($nFeat >= 2) {
                $v = $atom($row[0], $row[1]);
            } else {
                $valid = false; break;
            }
            if ($v === null || is_nan($v) || is_infinite($v)) { $valid = false; break; }
            $vec[] = $v;
        }
        if (!$valid || count($vec) < 2) continue;
        
        $sig = signalStrength($vec, $y);
        $totalSignal += $sig;
        $domainsHit[$task['domain']] = true;
        
        if ($sig > 0.99) $tasksSolved++;
        
        $ratios = [];
        for ($i = 0; $i < count($vec); $i++) $ratios[] = $vec[$i] / ($y[$i] + 1e-8);
        $m = array_sum($ratios) / count($ratios);
        if (abs($m) > 1e-8) {
            $v = 0;
            foreach ($ratios as $r) $v += ($r - $m) ** 2;
            $cv = sqrt($v / count($ratios)) / abs($m);
            $bestCv = min($bestCv, $cv);
        }
        
        $taskResults[$task['domain']] = ($taskResults[$task['domain']] ?? 0) + $sig;
    }
    
    if ($totalSignal > 0) {
        $atomScores[$atom] = [
            'total_signal' => $totalSignal,
            'domains' => count($domainsHit),
            'best_cv' => $bestCv,
            'solved' => $tasksSolved,
            'by_domain' => $taskResults,
        ];
    }
}

// ═══ ВЫВОД ═══
echo "══════════════════════════════════════\n";
echo "  ATOM SIGNALS (all domains)\n";
echo "══════════════════════════════════════\n\n";

uasort($atomScores, fn($a,$b) => $b['total_signal'] <=> $a['total_signal']);

echo sprintf("%-10s %8s %6s %6s  %s\n", "ATOM", "SIGNAL", "DOMAINS", "SOLVED", "BY DOMAIN");
echo str_repeat("─", 80) . "\n";

$candidates = [];
foreach ($atomScores as $atom => $s) {
    $domainStr = '';
    foreach ($s['by_domain'] as $dom => $sig) {
        $domainStr .= "$dom:" . round($sig,1) . " ";
    }
    
    $verdict = $s['solved'] >= 2 ? '🔥 PROMOTE' : 
              ($s['total_signal'] > 5 ? '📈 STRONG' : 
              ($s['total_signal'] > 2 ? '🔍 weak' : '❌ noise'));
    
    echo sprintf("%-10s %8.2f %6d %6d  %s %s\n", 
        $atom, $s['total_signal'], $s['domains'], $s['solved'], $domainStr, $verdict);
    
    if ($s['solved'] >= 1 || $s['total_signal'] > 5) {
        $candidates[] = $atom;
    }
}

// ═══ COMPOSE ЛУЧШИХ ═══
echo "\n══════════════════════════════════════\n";
echo "  COMPOSE: " . implode(' ', $candidates) . "\n";
echo "══════════════════════════════════════\n\n";

$compositions = [];
$top = array_slice($candidates, 0, 6);

foreach ($top as $a1) {
    foreach ($top as $a2) {
        if ($a1 === $a2) continue;
        $compName = "$a2($a1)";
        $totalSignal = 0;
        $solved = 0;
        $domainsHit = [];
        
        foreach ($tasks as $task) {
            $data = $task['data'];
            $nFeat = count($data[0]) - 1;
            $y = array_column($data, $nFeat);
            $vec = [];
            $valid = true;
            
            foreach ($data as $row) {
                $v1 = in_array($a1, $unaryAtoms) ? $a1($row[0]) : 
                      ($nFeat >= 2 ? $a1($row[0], $row[1]) : null);
                if ($v1 === null || is_nan($v1) || is_infinite($v1)) { $valid = false; break; }
                $v2 = $a2($v1);
                if ($v2 === null || is_nan($v2) || is_infinite($v2)) { $valid = false; break; }
                $vec[] = $v2;
            }
            
            if (!$valid) continue;
            $sig = signalStrength($vec, $y);
            $totalSignal += $sig;
            if ($sig > 0.99) $solved++;
            $domainsHit[$task['domain']] = true;
        }
        
        if ($totalSignal > 0) {
            $compositions[$compName] = [
                'signal' => $totalSignal,
                'solved' => $solved,
                'domains' => count($domainsHit),
            ];
        }
    }
}

if ($compositions) {
    uasort($compositions, fn($a,$b) => $b['signal'] <=> $a['signal']);
    echo sprintf("%-25s %8s %6s %6s\n", "COMPOSITION", "SIGNAL", "SOLVED", "DOMAINS");
    echo str_repeat("─", 55) . "\n";
    foreach (array_slice($compositions, 0, 15) as $name => $s) {
        $icon = $s['solved'] >= 2 ? '🔥' : ($s['signal'] > 10 ? '📈' : '·');
        echo sprintf("%-25s %8.2f %6d %6d %s\n", 
            $name, $s['signal'], $s['solved'], $s['domains'], $icon);
    }
}

echo "\nDone.\n";
