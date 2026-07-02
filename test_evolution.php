<?php
// ~/.bee_swarm/test_evolution.php
// v3: NaN fix + больше случайных попыток на поколение
// Лог: /tmp/evolution.log

date_default_timezone_set('Europe/Moscow');
$logFile = '/tmp/evolution.log';
file_put_contents($logFile, '');

function logMsg(string $msg): void {
    global $logFile;
    $line = '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

// ═══ АЛФАВИТ СРЕДЫ ═══
$unaryMath = ['abs','sqrt','sin','cos','tan','asin','acos','atan',
              'sinh','cosh','tanh','exp','log','log10',
              'floor','ceil','round','deg2rad','rad2deg'];
$binaryMath = ['min','max','hypot','pow','fmod'];

logMsg("═══ EVOLUTION v3 (NaN fix + 5 attempts/gen) ═══");
logMsg("Unary: " . count($unaryMath) . " | Binary: " . count($binaryMath));

// ═══ ЗАДАЧИ ═══
$tasks = [
    ['name'=>'ADD',   'data'=>[[1,2,3],[3,4,7],[5,6,11],[10,20,30]]],
    ['name'=>'MUL',   'data'=>[[1,2,2],[2,3,6],[3,4,12],[5,6,30]]],
    ['name'=>'SQRT',  'data'=>[[1.0,1.0],[4.0,2.0],[9.0,3.0],[16.0,4.0],[25.0,5.0]]],
    ['name'=>'MIN',   'data'=>[[0,0,0],[2,3,2],[5,1,1],[4,4,4],[10,3,3]]],
    ['name'=>'DIV',   'data'=>[[6,2,3],[12,3,4],[20,4,5],[10,2,5],[30,6,5]]],
    ['name'=>'SQUARE', 'data'=>[[1,1],[2,4],[3,9],[4,16],[5,25]]],
    ['name'=>'ABS',   'data'=>[[-3,3],[-1,1],[0,0],[2,2],[5,5]]],
];

function testFunction($fn, array $data, int $nFeat, string $mode, $fn2 = null): ?array {
    $y = array_column($data, $nFeat);
    $vec = [];
    
    foreach ($data as $row) {
        if ($mode === 'unary') {
            $v = $fn($row[0]);
        } elseif ($mode === 'binary' && $nFeat >= 2) {
            $v = $fn($row[0], $row[1]);
        } elseif ($mode === 'const') {
            $v = (float)$fn;
        } elseif ($mode === 'compare' && $nFeat >= 2) {
            $v = $fn($row[0], $row[1]) ? 1.0 : 0.0;
        } elseif ($mode === 'compose' && $fn2) {
            $v1 = $fn($row[0]);
            $v = $fn2($v1);
        } else {
            return null;
        }
        
        // NaN/Inf CHECK: любое invalid значение → reject всего вектора
        if ($v === null || is_nan($v) || is_infinite($v) || abs($v) > 1e90) {
            return ['cv' => 9.99, 'fn' => $fn, 'mode' => $mode, 'reject_reason' => 'invalid_value'];
        }
        $vec[] = $v;
    }
    
    $n = count($y);
    
    // Exact match first
    $exact = true;
    for ($i = 0; $i < $n; $i++) {
        if (abs(($vec[$i] ?? 9e99) - $y[$i]) > 0.0001) { $exact = false; break; }
    }
    if ($exact) return ['cv' => 0.0, 'fn' => $fn, 'mode' => $mode];
    
    $ratios = [];
    for ($i = 0; $i < $n; $i++) {
        $denom = $y[$i] + 1e-8;
        if (abs($denom) < 1e-10) return ['cv' => 9.99, 'fn' => $fn, 'mode' => $mode, 'reject_reason' => 'zero_y'];
        $ratios[] = $vec[$i] / $denom;
    }
    
    $mean = array_sum($ratios) / $n;
    if (abs($mean) < 1e-8) return ['cv' => 9.99, 'fn' => $fn, 'mode' => $mode, 'reject_reason' => 'zero_mean'];
    
    $variance = 0;
    foreach ($ratios as $r) $variance += ($r - $mean) ** 2;
    $cv = sqrt($variance / $n) / abs($mean);
    
    return ['cv' => $cv, 'fn' => $fn, 'mode' => $mode, 'vec_sample' => array_slice($vec, 0, 4)];
}

// ═══ ЗАПУСК ═══
$maxGens = 100;
$total = count($tasks);
$solved = 0;
$attemptsPerGen = 5; // 5 случайных функций на задачу за поколение

// Для каждой задачи храним лучший результат
$bestForTask = [];
foreach ($tasks as $ti => $task) {
    $bestForTask[$ti] = ['cv' => 9.99, 'fn' => null, 'mode' => null, 'gen' => 0];
}

logMsg("Tasks: $total | Max gens: $maxGens | Attempts/gen: $attemptsPerGen");
logMsg("---");

$foundAtoms = []; // все обнаруженные атомы

for ($gen = 1; $gen <= $maxGens; $gen++) {
    if ($solved >= $total) break;
    
    $anyImprovement = false;
    
    foreach ($tasks as $ti => &$task) {
        if (isset($task['solved'])) continue;
        
        $data = $task['data'];
        $nFeat = count($data[0]) - 1;
        
        for ($att = 0; $att < $attemptsPerGen; $att++) {
            $modeChoice = mt_rand(1, 5);
            
            if ($modeChoice === 1) {
                // Унарная
                $fn = $unaryMath[array_rand($unaryMath)];
                $result = testFunction($fn, $data, $nFeat, 'unary');
            } elseif ($modeChoice === 2 && $nFeat >= 2) {
                // Бинарная
                $fn = $binaryMath[array_rand($binaryMath)];
                $result = testFunction($fn, $data, $nFeat, 'binary');
            } elseif ($modeChoice === 3) {
                // Константа
                $k = round(mt_rand(0, 200) / 10, 1);
                $result = testFunction((string)$k, $data, $nFeat, 'const');
            } elseif ($modeChoice === 4 && $nFeat >= 2) {
                // Сравнение
                $cmps = [
                    ['<', fn($a,$b) => $a < $b, 'lt'],
                    ['>', fn($a,$b) => $a > $b, 'gt'],
                    ['==', fn($a,$b) => abs($a-$b) < 0.001, 'eq'],
                ];
                $cmp = $cmps[array_rand($cmps)];
                $result = testFunction($cmp[1], $data, $nFeat, 'compare');
                if ($result) $result['fn'] = "{$cmp[2]}(x0,x1)";
            } elseif ($modeChoice === 5 && $nFeat >= 1) {
                // Композиция
                $fn1 = $unaryMath[array_rand($unaryMath)];
                $fn2 = $unaryMath[array_rand($unaryMath)];
                $result = testFunction($fn1, $data, $nFeat, 'compose', $fn2);
                if ($result) $result['fn'] = "$fn2($fn1)";
            } else {
                continue;
            }
            
            if (!$result) continue;
            
            $cv = $result['cv'];
            $fnName = $result['fn'];
            
            if ($cv < $bestForTask[$ti]['cv']) {
                $bestForTask[$ti] = ['cv' => $cv, 'fn' => $fnName, 'mode' => $result['mode'], 'gen' => $gen];
                $anyImprovement = true;
            }
            
            if ($cv < 0.001 && !isset($foundAtoms[$fnName])) {
                $foundAtoms[$fnName] = ['task' => $task['name'], 'gen' => $gen, 'mode' => $result['mode']];
            }
            
            if ($cv < 0.001) {
                $task['solved'] = true;
                $task['discovered_at'] = $gen;
                $task['discovered_fn'] = $fnName;
                $task['discovered_mode'] = $result['mode'];
                $solved++;
                logMsg("✅ GEN $gen: {$task['name']} → $fnName (mode={$result['mode']}, CV=" . sprintf('%.2e',$cv) . ")");
                break; // следующая задача
            }
        }
    }
    
    if ($gen % 20 === 0 || ($anyImprovement && $gen % 5 === 0)) {
        $bestStr = '';
        foreach ($tasks as $ti => $task) {
            if (!isset($task['solved'])) {
                $b = $bestForTask[$ti];
                $bestStr .= " {$task['name']}:" . round($b['cv'],3) . "/{$b['fn']}";
            }
        }
        if ($bestStr) logMsg("GEN $gen |$bestStr");
    }
}

// ═══ ФИНАЛ ═══
logMsg("═══ FINAL ═══");
logMsg("Solved: $solved/$total");
logMsg("");

foreach ($tasks as $ti => $task) {
    $b = $bestForTask[$ti];
    if (isset($task['solved'])) {
        logMsg("  ✅ {$task['name']}: gen {$task['discovered_at']} → {$task['discovered_fn']} ({$task['discovered_mode']})");
    } else {
        logMsg("  ❌ {$task['name']}: best CV=" . round($b['cv'],4) . " fn={$b['fn']} ({$b['mode']}, gen {$b['gen']})");
    }
}

logMsg("");
logMsg("Discovered atoms: " . count($foundAtoms));
foreach ($foundAtoms as $atom => $info) {
    logMsg("  $atom ← {$info['task']} (gen {$info['gen']}, {$info['mode']})");
}

$missing = ['add','mul','div','sqrt','min','max','abs','sq','pow','exp','log','floor','ceil','gt','lt','eq','hypot'];
$foundNames = array_keys($foundAtoms);
$stillMissing = array_diff($missing, $foundNames);
if ($stillMissing) {
    logMsg("Still missing from grammar: " . implode(', ', $stillMissing));
}

// Сравнение с существующей grammar
require_once __DIR__ . '/vendor/autoload.php';
$grammar = new BeeSwarm\Grammar();
$existingOps = $grammar->all();
$newAtoms = array_diff($foundNames, $existingOps);
if ($newAtoms) {
    logMsg("NEW atoms (not in grammar): " . implode(', ', $newAtoms));
}

logMsg("Done. Log: $logFile");
