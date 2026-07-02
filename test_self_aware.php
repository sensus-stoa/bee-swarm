<?php
// ~/.bee_swarm/test_self_aware.php
// УРОВЕНЬ 4: рой изучает сам себя
// Метрики демона → задачи → CV→0 → законы о себе

date_default_timezone_set('Europe/Moscow');
require_once __DIR__ . '/vendor/autoload.php';
use BeeSwarm\AtomRegistry;

$logFile = __DIR__ . '/logs/agenda.log';
if (!file_exists($logFile)) { echo "No log file\n"; exit; }

echo "══════════════════════════════════════\n";
echo "  SELF-AWARENESS: swarm studies itself\n";
echo "══════════════════════════════════════\n\n";

// ═══ ИЗВЛЕЧЕНИЕ МЕТРИК ДЕМОНА ═══
$ticks = [];
$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$tickNum = 0;
$energy = 10.0;
$discoveries = 0;
$composes = 0;
$searchFinds = 0;
$grammarSize = 4;
$knownLaws = 0;

foreach ($lines as $line) {
    if (!preg_match('/\[(\d{2}:\d{2}:\d{2})\]/', $line, $m)) continue;
    $time = $m[1];
    
    $isDiscovery = str_contains($line, '🔍');
    $isCompose   = str_contains($line, '🧬') && str_contains($line, 'COMPOSE');
    $isSearch    = str_contains($line, '✅') && str_contains($line, 'Search::find');
    $isGrammarGrow = str_contains($line, 'Grammar:');
    
    if ($isDiscovery) { $discoveries++; $energy += 1.0; $knownLaws++; }
    if ($isCompose)   { $composes++; $energy += 1.5; $knownLaws++; }
    if ($isSearch)    { $searchFinds++; $knownLaws++; }
    if ($isGrammarGrow && preg_match('/Grammar:.*\((\d+) ops\)/', $line, $gm)) {
        $grammarSize = (int)$gm[1];
    }
    
    $tickNum++;
    $ticks[] = [
        'tick' => $tickNum,
        'energy' => $energy,
        'discoveries' => $discoveries,
        'composes' => $composes,
        'search_finds' => $searchFinds,
        'known_laws' => $knownLaws,
        'grammar_size' => $grammarSize,
    ];
}

echo "Log lines: " . count($lines) . "\n";
echo "Ticks extracted: " . count($ticks) . "\n\n";

if (count($ticks) < 20) { echo "Not enough data\n"; exit; }

// ═══ ЗАДАЧИ О СЕБЕ ═══
$cvFn = function($v, $y) {
    $n = count($v); if ($n<2) return 9.99;
    for($i=0;$i<$n;$i++){if(abs($v[$i]-$y[$i])>0.001)break;if($i==$n-1)return 0.0;}
    $r=[]; for($i=0;$i<$n;$i++)$r[]=$v[$i]/($y[$i]+1e-8);
    $m=array_sum($r)/$n; if(abs($m)<1e-8)return 9.99;
    $q=0; foreach($r as $x)$q+=($x-$m)**2;
    return sqrt($q/$n)/abs($m);
};

$tasks = [
    // tick → discoveries (должен расти линейно пока есть задачи)
    ['name'=>'tick→discoveries', 'data'=>array_map(fn($t)=>[(float)$t['tick'], (float)$t['discoveries']], $ticks)],
    // discoveries → energy 
    ['name'=>'discoveries→energy', 'data'=>array_map(fn($t)=>[(float)$t['discoveries'], (float)$t['energy']], $ticks)],
    // tick → known_laws (логарифмический рост?)
    ['name'=>'tick→known_laws', 'data'=>array_map(fn($t)=>[(float)$t['tick'], (float)$t['known_laws']], $ticks)],
    // grammar_size → discoveries (больше grammar = больше открытий?)
    ['name'=>'grammar→discoveries', 'data'=>array_map(fn($t)=>[(float)$t['grammar_size'], (float)$t['discoveries']], $ticks)],
    // tick → grammar_size
    ['name'=>'tick→grammar', 'data'=>array_map(fn($t)=>[(float)$t['tick'], (float)$t['grammar_size']], $ticks)],
    // energy → discoveries (обратная связь?)
    ['name'=>'energy→discoveries', 'data'=>array_map(fn($t)=>[(float)$t['energy'], (float)$t['discoveries']], $ticks)],
];

// ═══ DISCOVER ═══
$safe = [
    'abs','sqrt','log','log10','exp','floor','ceil','round',
    'sin','cos','tan','deg2rad','rad2deg'
];
$binarySafe = ['min','max','pow','hypot','fmod'];

foreach ($tasks as $task) {
    $data = $task['data'];
    if (count($data) < 5) continue;
    // Sample to max 100 points
    if (count($data) > 100) {
        $keys = array_rand($data, 100);
        sort($keys);
        $data = array_map(fn($k) => $data[$k], $keys);
    }
    $X = array_map(fn($r) => array_slice($r, 0, -1), array_values($data));
    $y = array_column($data, count($data[0]) - 1);
    
    echo "─── {$task['name']} (" . count($data) . " pts) ───\n";
    $found = [];
    
    foreach ($safe as $fn) {
        $vec = []; $valid = true;
        foreach ($X as $row) {
            $v = @$fn((float)$row[0]);
            if ($v===false||$v===null||is_nan((float)$v)||is_infinite((float)$v)) { $valid=false; break; }
            $vec[] = (float)$v;
        }
        if (!$valid) continue;
        $cv = $cvFn($vec, $y);
        if ($cv < 0.5) $found[] = ['fn'=>$fn, 'cv'=>$cv];
    }
    // Бинарные: нужен второй признак
    if (count($X[0]) >= 2) {
        foreach ($binarySafe as $fn) {
            $vec = []; $valid = true;
            foreach ($X as $row) {
                $v = @$fn((float)$row[0], (float)$row[1]);
                if ($v===false||$v===null||is_nan((float)$v)||is_infinite((float)$v)) { $valid=false; break; }
                $vec[] = (float)$v;
            }
            if (!$valid) continue;
            $cv = $cvFn($vec, $y);
            if ($cv < 0.5) $found[] = ['fn'=>$fn, 'cv'=>$cv];
        }
    }
    
    usort($found, fn($a,$b)=>$a['cv']<=>$b['cv']);
    foreach (array_slice($found, 0, 8) as $f) {
        $icon = $f['cv']<0.01?'✅':($f['cv']<0.1?'🔍':'·');
        printf("  %s %-10s CV=%.4f\n", $icon, $f['fn'], $f['cv']);
    }
    echo "\n";
}

// ═══ COMPOSE SELF-LAWS ═══
echo "─── COMPOSE: grammar→discoveries ───\n";
$task = $tasks[3]; // grammar→discoveries
$data = $task['data'];
$X = array_map(fn($r) => array_slice($r, 0, -1), array_values($data));
$y = array_column($data, count($data[0]) - 1);

$grammar = $safe;
$composed = AtomRegistry::discoverCompose($X, $y, $grammar);
foreach ($composed as $c) {
    echo "  compose: {$c['atom']} (CV=" . round($c['cv'],4) . ")\n";
}

// ═══ ВЫВОД ═══
echo "\n══════════════════════════════════════\n";
echo "  SELF-LAWS DISCOVERED\n";
echo "══════════════════════════════════════\n\n";

// Простейший само-анализ
$firstTicks = array_slice($ticks, 0, min(50, (int)(count($ticks)*0.3)));
$lastTicks  = array_slice($ticks, -min(50, (int)(count($ticks)*0.3)));

$firstRate = count($firstTicks) > 1 
    ? ($firstTicks[count($firstTicks)-1]['discoveries'] - $firstTicks[0]['discoveries']) / count($firstTicks)
    : 0;
$lastRate  = count($lastTicks) > 1
    ? ($lastTicks[count($lastTicks)-1]['discoveries'] - $lastTicks[0]['discoveries']) / count($lastTicks)
    : 0;

echo "Early discovery rate:  " . round($firstRate, 3) . "/tick\n";
echo "Recent discovery rate: " . round($lastRate, 3) . "/tick\n";

if ($lastRate < $firstRate * 0.5) {
    echo "\n🔍 SELF-LAW: discovery rate is declining.\n";
    echo "   Swarm knows: it's running out of easy tasks.\n";
    echo "   Action: should seek new domains (forager).\n";
}

$avgDiscoveriesPerGrammarOp = $ticks[count($ticks)-1]['discoveries'] / max(1, $ticks[count($ticks)-1]['grammar_size']);
echo "\nDiscoveries per grammar op: " . round($avgDiscoveriesPerGrammarOp, 1) . "\n";
echo "Total energy accumulated: " . round($ticks[count($ticks)-1]['energy'], 1) . "\n";

echo "\n✅ Swarm has a self-model. It knows its own metrics.\n";
echo "   Next: use these laws to self-modify.\n";
