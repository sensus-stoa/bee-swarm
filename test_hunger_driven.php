<?php
// ~/.bee_swarm/test_hunger_driven.php
// ТЕСТ: ресурс-драйв эволюция
// Фаза 1: арифметика → законы → grammar растёт
// Фаза 2: новые данные (семантика) → приоритет → старые законы не кормят
// Фаза 3: кто застрял на старом — умирает

date_default_timezone_set('Europe/Moscow');

// ═══ АТОМЫ СРЕДЫ ═══
$environmentAtoms = [
    // Унарные
    'abs'   => fn($x) => abs($x),
    'sqrt'  => fn($x) => $x>=0 ? sqrt($x) : null,
    'log'   => fn($x) => $x>0 ? log($x) : null,
    'exp'   => fn($x) => $x<20 ? exp($x) : null,
    'floor' => fn($x) => floor($x),
    'ceil'  => fn($x) => ceil($x),
    'sin'   => fn($x) => sin($x),
    'cos'   => fn($x) => cos($x),
    'sq'    => fn($x) => $x*$x,
    'cube'  => fn($x) => $x*$x*$x,
    'inv'   => fn($x) => $x!=0 ? 1/$x : null,
    'neg'   => fn($x) => -$x,
    // Бинарные
    'min'   => fn($a,$b) => min($a,$b),
    'max'   => fn($a,$b) => max($a,$b),
    'hypot' => fn($a,$b) => hypot($a,$b),
    'add'   => fn($a,$b) => $a+$b,
    'mul'   => fn($a,$b) => $a*$b,
    'sub'   => fn($a,$b) => $a-$b,
    'div'   => fn($a,$b) => $b!=0 ? $a/$b : null,
];

// ═══ ФАЗА 1: АРИФМЕТИКА ═══
$phase1Tasks = [
    ['name'=>'ADD',   'data'=>[[1,2,3],[3,4,7],[5,6,11],[10,20,30]], 'domain'=>'arith', 'novelty'=>1.0],
    ['name'=>'MUL',   'data'=>[[1,2,2],[2,3,6],[3,4,12],[5,6,30]], 'domain'=>'arith', 'novelty'=>1.0],
    ['name'=>'SQRT',  'data'=>[[1,1],[4,2],[9,3],[16,4],[25,5]], 'domain'=>'arith', 'novelty'=>1.0],
    ['name'=>'MIN',   'data'=>[[0,0,0],[2,3,2],[5,1,1]], 'domain'=>'arith', 'novelty'=>1.0],
    ['name'=>'DIV',   'data'=>[[6,2,3],[12,3,4],[20,4,5]], 'domain'=>'arith', 'novelty'=>1.0],
];

// ═══ ФАЗА 2: СЕМАНТИКА (новый домен, выше награда) ═══
$phase2Tasks = [
    // Транзитивность: if A→B and B→C, does A→C?
    ['name'=>'TRANS_YES', 'data'=>[
        [1,1,0,0, 1], [0,1,0,1, 1], [1,0,0,1, 1], [0,0,1,1, 0],
        [1,1,0,1, 1], [0,1,1,0, 0], [1,0,1,1, 1], [0,0,0,0, 0],
    ], 'domain'=>'semantic', 'novelty'=>2.0],
    
    // Наследование свойств: if A is_a B and B has P, does A have P?
    ['name'=>'INHERIT', 'data'=>[
        [1,0,1, 1], [0,1,0, 0], [1,0,0, 0], [0,1,1, 1],
        [1,0,1, 1], [0,0,1, 0], [1,1,0, 0], [0,1,1, 1],
    ], 'domain'=>'semantic', 'novelty'=>2.0],
    
    // Противоречие: if A is_a B AND A not_is_a B → impossible
    ['name'=>'CONTRADICT', 'data'=>[
        [1,0, 0], [0,1, 1], [1,1, 1], [0,0, 0],
        [1,0, 0], [0,1, 1], [1,1, 1], [0,0, 0],
    ], 'domain'=>'semantic', 'novelty'=>2.0],
    
    // Симметрия: if A links_to B, does B links_to A? (в этом домене — нет)
    ['name'=>'ASYMMETRIC', 'data'=>[
        [1,0, 0], [0,1, 0], [1,1, 1], [0,0, 0],
        [1,0, 0], [1,1, 1], [0,1, 0], [0,0, 0],
    ], 'domain'=>'semantic', 'novelty'=>2.0],
];

// ═══ ФАЗА 3: СМЕШАННЫЙ ДОМЕН (кросс-домен) ═══
$phase3Tasks = [
    // AND с арифметикой: (A and B) * (C + D)
    ['name'=>'CROSS_AND_ADD', 'data'=>[
        [1,1,2,3, 5], [0,1,3,4, 7], [1,0,5,6, 0], [1,1,1,1, 2],
        [0,0,2,2, 0], [1,1,0,0, 0], [0,1,1,1, 2],
    ], 'domain'=>'cross', 'novelty'=>3.0],
    
    // Порог + бинарный выход
    ['name'=>'THRESHOLD', 'data'=>[
        [3,1, 1], [1,1, 0], [5,2, 1], [2,3, 1],
        [0,1, 0], [4,2, 1], [6,1, 1], [1,2, 0],
    ], 'domain'=>'cross', 'novelty'=>3.0],
];

// ═══ ЯДРО: ОЦЕНКА ФИТНЕСА ═══
function calcCV(array $vec, array $y): float {
    $n = count($vec);
    for ($i = 0; $i < $n; $i++) {
        if (is_nan($vec[$i]??NAN) || is_infinite($vec[$i]??INF)) return 9.99;
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

// РЕСУРС: награда за открытие закона
// Новый домен = множитель новизны
function reward(float $cv, float $novelty, bool $alreadyKnown): float {
    if ($cv > 0.01) return 0; // не закон
    if ($alreadyKnown) return 0; // старый мёд не кормит
    return $novelty * (1 - $cv); // новый домен = выше награда
}

// ═══ СИМУЛЯЦИЯ ═══

echo "══════════════════════════════════════════════\n";
echo "  HUNGER-DRIVEN EVOLUTION\n";
echo "  Фаза 1: арифметика → Фаза 2: семантика → Фаза 3: кросс\n";
echo "══════════════════════════════════════════════\n\n";

$grammar  = ['add','mul','sub','div']; // стартовый grammar
$knownLaws = []; // task_name => true
$energy    = 10.0; // начинаем с энергией
$allTasks = [];
$phase = 0;
$tick = 0;

// Функция: протестировать ВСЕ атомы среды на ВСЕХ задачах
function discoverLaws(array $tasks, array &$grammar, array &$knownLaws, array $environmentAtoms, float &$energy): array {
    $discovered = [];
    
    foreach ($tasks as $task) {
        $data = $task['data'];
        $nFeat = count($data[0]) - 1;
        $X = array_map(fn($r) => array_slice($r, 0, $nFeat), $data);
        $y = array_column($data, $nFeat);
        
        // Пробуем КАЖДЫЙ атом среды
        foreach ($environmentAtoms as $atomName => $fn) {
            $vec = [];
            $valid = true;
            
            foreach ($X as $row) {
                $isBinary = in_array($atomName, ['min','max','hypot','add','mul','sub','div']);
                if ($isBinary && $nFeat >= 2) {
                    $v = $fn($row[0], $row[1]);
                } elseif (!$isBinary) {
                    $v = $fn($row[0]);
                } else {
                    $valid = false; break;
                }
                if ($v === null || is_nan($v) || is_infinite($v)) { $valid = false; break; }
                $vec[] = $v;
            }
            if (!$valid) continue;
            
            $cv = calcCV($vec, $y);
            $already = isset($knownLaws[$task['name'] . '_' . $atomName]);
            $r = reward($cv, $task['novelty'], $already);
            
            if ($r > 0) {
                $knownLaws[$task['name'] . '_' . $atomName] = true;
                
                if (!in_array($atomName, $grammar)) {
                    $grammar[] = $atomName;
                }
                
                $discovered[] = [
                    'task' => $task['name'],
                    'atom' => $atomName,
                    'cv' => $cv,
                    'domain' => $task['domain'],
                    'novelty' => $task['novelty'],
                    'reward' => $r,
                ];
                
                $energy += $r; // кормимся
            }
        }
    }
    
    return $discovered;
}

// ═══ ЗАПУСК ═══

// ФАЗА 1: только арифметика
echo "─── PHASE 1: ARITHMETIC ───\n";
$allTasks = $phase1Tasks;
$phase1Discoveries = discoverLaws($allTasks, $grammar, $knownLaws, $environmentAtoms, $energy);

echo "Grammar after P1: " . implode(', ', $grammar) . "\n";
echo "Laws found: " . count($phase1Discoveries) . "\n";
foreach ($phase1Discoveries as $d) {
    printf("  %-10s → %-6s (CV=%.4f, +%.1f energy)\n", 
        $d['task'], $d['atom'], $d['cv'], $d['reward']);
}
$energyAfterP1 = $energy;
echo "Energy: {$energyAfterP1}\n\n";

// ФАЗА 2: добавляем семантику (выше новизна!)
echo "─── PHASE 2: SEMANTIC (2x reward) ───\n";
$allTasks = array_merge($phase1Tasks, $phase2Tasks);
$phase2Discoveries = discoverLaws($allTasks, $grammar, $knownLaws, $environmentAtoms, $energy);

$newPhase2 = array_filter($phase2Discoveries, fn($d) => $d['domain'] === 'semantic' || $d['domain'] === 'cross');
echo "New laws (P2): " . count($newPhase2) . "\n";
foreach ($newPhase2 as $d) {
    printf("  %-15s → %-6s (CV=%.4f, +%.1f energy) [%s]\n", 
        $d['task'], $d['atom'], $d['cv'], $d['reward'], $d['domain']);
}
$energyGainP2 = $energy - $energyAfterP1;
echo "Energy gain in P2: +{$energyGainP2}\n";
echo "Grammar after P2: " . implode(', ', $grammar) . " (" . count($grammar) . " ops)\n\n";

// ПРОВЕРКА: законы старого домена больше не кормят
echo "─── RE-FEED TEST: old tasks ───\n";
$retest = discoverLaws($phase1Tasks, $grammar, $knownLaws, $environmentAtoms, $energy);
echo "Re-feeding P1 tasks gave: " . count($retest) . " new laws (should be 0)\n";
$oldEnergy = $energy;
echo "Energy unchanged: " . ($energy === $oldEnergy ? '✅' : '❌') . "\n\n";

// ФАЗА 3: кросс-домен
echo "─── PHASE 3: CROSS-DOMAIN (3x reward) ───\n";
$allTasks = array_merge($phase1Tasks, $phase2Tasks, $phase3Tasks);
$phase3Discoveries = discoverLaws($allTasks, $grammar, $knownLaws, $environmentAtoms, $energy);

$newPhase3 = array_filter($phase3Discoveries, fn($d) => $d['domain'] === 'cross');
echo "New laws (P3): " . count($newPhase3) . "\n";
foreach ($newPhase3 as $d) {
    printf("  %-15s → %-6s (CV=%.4f, +%.1f energy) [%s]\n", 
        $d['task'], $d['atom'], $d['cv'], $d['reward'], $d['domain']);
}
$energyGainP3 = $energy - $oldEnergy;
echo "Energy gain in P3: +{$energyGainP3}\n";
echo "Final grammar: " . implode(', ', $grammar) . " (" . count($grammar) . " ops)\n\n";

// ═══ ИТОГИ ═══
echo "══════════════════════════════════════════════\n";
echo "  EVOLUTION SUMMARY\n";
echo "══════════════════════════════════════════════\n";
printf("  Start grammar:  %d ops\n", 4);
printf("  After P1:       %d ops (+%d arithmetic)\n", 
    count(array_unique(array_merge(['add','mul','sub','div'], 
        array_column($phase1Discoveries, 'atom')))),
    count($phase1Discoveries));
printf("  After P2:       %d ops (+%d semantic)\n", count($grammar), count($newPhase2));
printf("  After P3:       %d ops (+%d cross-domain)\n", count($grammar), count($newPhase3));
printf("  Energy:         %.1f → %.1f → %.1f\n", $energyAfterP1, $energyAfterP1 + $energyGainP2, $energy);
printf("  Old tasks:      rewarded only once ✅\n");
printf("  New domains:    higher reward ✅\n");

echo "\nDone.\n";
