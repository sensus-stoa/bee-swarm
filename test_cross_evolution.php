<?php
// ~/.bee_swarm/test_cross_evolution.php
// ТЕСТ: кросс-домен = высшая награда = путь к человеку
// Арифметика (1x) + Логика (1x) → конечны
// Кросс-домен (5x) → требует compose → бесконечен
// Выживают те, кто решает кросс-домен

date_default_timezone_set('Europe/Moscow');

// ═══ АТОМЫ СРЕДЫ ═══
$environmentAtoms = [
    // АРИФМЕТИЧЕСКИЕ (унарные)
    'neg'   => fn($x) => -$x,
    'sq'    => fn($x) => $x*$x,
    'inv'   => fn($x) => $x!=0 ? 1/$x : null,
    'abs'   => fn($x) => abs($x),
    // АРИФМЕТИЧЕСКИЕ (бинарные)
    'add'   => fn($a,$b) => $a+$b,
    'sub'   => fn($a,$b) => $a-$b,
    'mul'   => fn($a,$b) => $a*$b,
    'div'   => fn($a,$b) => $b!=0 ? $a/$b : null,
    'min'   => fn($a,$b) => min($a,$b),
    'max'   => fn($a,$b) => max($a,$b),
    // ЛОГИЧЕСКИЕ
    'and'   => fn($a,$b) => ($a>0 && $b>0) ? 1.0 : 0.0,
    'or'    => fn($a,$b) => ($a>0 || $b>0) ? 1.0 : 0.0,
    'not'   => fn($x) => $x>0 ? 0.0 : 1.0,
    'gt'    => fn($a,$b) => $a > $b ? 1.0 : 0.0,
    'lt'    => fn($a,$b) => $a < $b ? 1.0 : 0.0,
    'eq'    => fn($a,$b) => abs($a-$b)<0.001 ? 1.0 : 0.0,
];

$unaryAtoms  = ['neg','sq','inv','abs','not'];
$binaryAtoms = ['add','sub','mul','div','min','max','and','or','gt','lt','eq'];

// ═══ ЗАДАЧИ ═══

// ДОМЕН A: арифметика (1x reward, конечный)
$domainA = [
    ['name'=>'A_ADD',    'data'=>[[1,2,3],[3,4,7],[5,6,11]],        'domain'=>'arith'],
    ['name'=>'A_MUL',    'data'=>[[1,2,2],[2,3,6],[3,4,12]],        'domain'=>'arith'],
    ['name'=>'A_MIN',    'data'=>[[0,0,0],[2,3,2],[5,1,1]],         'domain'=>'arith'],
    ['name'=>'A_DIV',    'data'=>[[6,2,3],[12,3,4],[20,4,5]],       'domain'=>'arith'],
    ['name'=>'A_ABS',    'data'=>[[-3,3],[-1,1],[0,0],[2,2]],       'domain'=>'arith'],
];

// ДОМЕН B: логика (1x reward, конечный)
$domainB = [
    ['name'=>'B_AND',    'data'=>[[0,0,0],[0,1,0],[1,0,0],[1,1,1]], 'domain'=>'logic'],
    ['name'=>'B_OR',     'data'=>[[0,0,0],[0,1,1],[1,0,1],[1,1,1]], 'domain'=>'logic'],
    ['name'=>'B_GT',     'data'=>[[1,2,0],[3,2,1],[5,1,1],[0,4,0]], 'domain'=>'logic'],
    ['name'=>'B_EQ',     'data'=>[[1,1,1],[2,2,1],[3,3,1],[1,2,0]], 'domain'=>'logic'],
    ['name'=>'B_NOT',    'data'=>[[0,1],[1,0],[0,1],[1,0]],          'domain'=>'logic'],
];

// ДОМЕН X: кросс-домен (5x reward) — решаемо через 2-level compose
$domainX = [
    // X1: abs(x-y) → abs(sub(x,y)) — унарная над бинарной
    ['name'=>'X_ABS_DIFF', 'data'=>[
        [1,3, 2],[5,1, 4],[2,2, 0],[0,5, 5],[3,0, 3],
    ], 'domain'=>'cross'],
    
    // X2: min(x,y) × z → mul(min(x,y), z) — бинарная над бинарной
    ['name'=>'X_MIN_MUL', 'data'=>[
        [2,5,3, 6],[3,1,2, 2],[4,4,1, 4],[0,6,5, 0],
    ], 'domain'=>'cross'],
    
    // X3: (x+y)² → sq(add(x,y)) — унарная над бинарной
    ['name'=>'X_SQ_SUM', 'data'=>[
        [1,2, 9],[3,1, 16],[0,0, 0],[2,3, 25],
    ], 'domain'=>'cross'],
    
    // X4: max(x,y) / z → div(max(x,y), z) — бинарная над бинарной
    ['name'=>'X_MAX_DIV', 'data'=>[
        [6,3,3, 2],[8,4,2, 4],[10,2,4, 2.5],[0,5,1, 5],
    ], 'domain'=>'cross'],
    
    // X5: |x| × y → mul(abs(x), y) — бинарная над унарной
    ['name'=>'X_ABS_MUL', 'data'=>[
        [-2,3, 6],[5,2, 10],[-1,5, 5],[0,7, 0],
    ], 'domain'=>'cross'],
];

// ═══ ФУНКЦИИ ═══
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

function applyAtom(string $name, array $row, int $nFeat, array $environmentAtoms): ?float {
    global $unaryAtoms, $binaryAtoms;
    $fn = $environmentAtoms[$name];
    if (in_array($name, $binaryAtoms) && $nFeat >= 2) {
        return $fn($row[0], $row[1]);
    } elseif (in_array($name, $unaryAtoms)) {
        return $fn($row[0]);
    }
    return null;
}

// Compose: все комбинации grammar-операций на доступных признаках
function applyCompose(string $outer, string $inner, array $row, int $nFeat, array $env): ?float {
    global $unaryAtoms, $binaryAtoms;
    $innerFn = $env[$inner] ?? null;
    $outerFn = $env[$outer] ?? null;
    if (!$innerFn || !$outerFn) return null;
    
    $innerBinary = in_array($inner, $binaryAtoms);
    $outerBinary = in_array($outer, $binaryAtoms);
    
    if ($innerBinary && $nFeat >= 2) {
        // Бинарная inner: применить к двум признакам
        $v = $innerFn($row[0], $row[1]);
        if ($v === null || is_nan($v) || is_infinite($v)) return null;
        
        if ($outerBinary && $nFeat >= 3) {
            return $outerFn($v, $row[2]);
        } elseif (!$outerBinary) {
            return $outerFn($v);
        } else {
            return null; // binary outer but not enough features
        }
    } elseif (!$innerBinary) {
        // Унарная inner
        $v = $innerFn($row[0]);
        if ($v === null || is_nan($v) || is_infinite($v)) return null;
        
        if ($outerBinary && $nFeat >= 2) {
            return $outerFn($v, $row[1]);
        } elseif (!$outerBinary) {
            return $outerFn($v);
        } else {
            return null;
        }
    }
    return null;
}

// ═══ ТРИ СТРАТЕГИИ ПЧЁЛ ═══

function beeArithOnly(array $tasks, array &$grammar, array &$known, array $env, float &$energy): int {
    $arithTasks = array_filter($tasks, fn($t) => $t['domain'] === 'arith');
    return discover($arithTasks, $grammar, $known, $env, $energy);
}

function beeAllDomains(array $tasks, array &$grammar, array &$known, array $env, float &$energy): int {
    return discover($tasks, $grammar, $known, $env, $energy);
}

function beeCrossFocus(array $tasks, array &$grammar, array &$known, array $env, float &$energy): int {
    // Приоритет: кросс-домен первым (выше награда), потом всё остальное
    $crossTasks = array_filter($tasks, fn($t) => $t['domain'] === 'cross');
    $found = discover($crossTasks, $grammar, $known, $env, $energy);
    // Всегда пробуем всё — compose может решить кросс-домен через простые атомы
    $found += discover($tasks, $grammar, $known, $env, $energy);
    return $found;
}

function discover(array $tasks, array &$grammar, array &$known, array $env, float &$energy): int {
    global $unaryAtoms, $binaryAtoms;
    $found = 0;
    
    foreach ($tasks as $task) {
        $data = $task['data'];
        $nFeat = count($data[0]) - 1;
        $X = array_map(fn($r) => array_slice($r, 0, $nFeat), $data);
        $y = array_column($data, $nFeat);
        
        $novelty = $task['domain'] === 'cross' ? 5.0 : 1.0;
        
        // 1. ПРОСТЫЕ АТОМЫ
        foreach ($env as $atomName => $fn) {
            $vec = []; $valid = true;
            foreach ($X as $row) {
                $v = applyAtom($atomName, $row, $nFeat, $env);
                if ($v === null || is_nan($v) || is_infinite($v)) { $valid = false; break; }
                $vec[] = $v;
            }
            if (!$valid) continue;
            
            $cv = calcCV($vec, $y);
            $key = $task['name'] . '_' . $atomName;
            $already = isset($known[$key]);
            
            if ($cv < 0.001 && !$already) {
                $known[$key] = true;
                if (!in_array($atomName, $grammar)) $grammar[] = $atomName;
                $energy += $novelty;
                $found++;
            }
        }
        
        // 2. COMPOSE: все пары grammar-атомов
        if (count($grammar) >= 2) {
            foreach ($grammar as $outer) {
                foreach ($grammar as $inner) {
                    if ($outer === $inner) continue;
                    if (!isset($env[$outer]) || !isset($env[$inner])) continue;
                    
                    $vec = []; $valid = true;
                    foreach ($X as $row) {
                        $v = applyCompose($outer, $inner, $row, $nFeat, $env);
                        if ($v === null || is_nan($v) || is_infinite($v)) { $valid = false; break; }
                        $vec[] = $v;
                    }
                    if (!$valid) continue;
                    
                    $cv = calcCV($vec, $y);
                    $compName = "{$outer}({$inner})";
                    $key = $task['name'] . '_' . $compName;
                    $already = isset($known[$key]);
                    
                    if ($cv < 0.001 && !$already) {
                        $known[$key] = true;
                        if (!in_array($compName, $grammar)) $grammar[] = $compName;
                        $energy += $novelty * 1.5; // compose бонус
                        $found++;
                    }
                }
            }
        }
    }
    
    return $found;
}

// ═══ СИМУЛЯЦИЯ ═══
echo "══════════════════════════════════════════════\n";
echo "  CROSS-DOMAIN EVOLUTION\n";
echo "  Кросс-домен = 5x reward = путь к человеку\n";
echo "══════════════════════════════════════════════\n\n";

$allTasks = array_merge($domainA, $domainB, $domainX);
echo "Tasks: " . count($domainA) . " arith + " . count($domainB) . " logic + " . count($domainX) . " cross\n\n";

// ТРИ ПЧЕЛЫ — три стратегии
$bees = [
    'Bee_ArithOnly' => ['grammar' => ['add','sub','mul','div'], 'known' => [], 'energy' => 10.0, 'fn' => 'beeArithOnly'],
    'Bee_AllDomains'=> ['grammar' => ['add','sub','mul','div'], 'known' => [], 'energy' => 10.0, 'fn' => 'beeAllDomains'],
    'Bee_CrossFirst'=> ['grammar' => ['add','sub','mul','div'], 'known' => [], 'energy' => 10.0, 'fn' => 'beeCrossFocus'],
];

for ($gen = 1; $gen <= 10; $gen++) {
    foreach ($bees as $name => &$bee) {
        $fn = $bee['fn'];
        $found = $fn($allTasks, $bee['grammar'], $bee['known'], $environmentAtoms, $bee['energy']);
        
        if ($gen === 1 || $gen === 5 || $gen === 10) {
            $crossFound = 0;
            foreach (array_filter($allTasks, fn($t) => $t['domain']==='cross') as $t) {
                foreach ($bee['known'] as $k => $_) {
                    if (str_starts_with($k, $t['name'])) $crossFound++;
                }
            }
            if ($gen === 10 || $found > 0) {
                printf("GEN %2d %-18s energy=%5.1f grammar=%2d ops cross=%d\n", 
                    $gen, $name, $bee['energy'], count($bee['grammar']), $crossFound);
            }
        }
    }
    unset($bee);
}

// ═══ ФИНАЛЬНЫЕ ИТОГИ ═══
echo "\n══════════════════════════════════════════════\n";
echo "  FINAL STANDINGS\n";
echo "══════════════════════════════════════════════\n\n";

echo sprintf("%-20s %8s %10s %10s\n", "BEE", "ENERGY", "GRAMMAR", "CROSS-SOLVED");
echo str_repeat("─", 55) . "\n";

foreach ($bees as $name => $bee) {
    $crossSolved = 0;
    $crossTasks = array_filter($allTasks, fn($t) => $t['domain']==='cross');
    foreach ($crossTasks as $t) {
        foreach ($bee['known'] as $k => $_) {
            if (str_starts_with($k, $t['name'])) { $crossSolved++; break; }
        }
    }
    
    $icon = $crossSolved >= 3 ? '👑' : ($crossSolved >= 1 ? '🔍' : '💀');
    printf("%-20s %8.1f %10d %10d %s\n", 
        "$icon $name", $bee['energy'], count($bee['grammar']), $crossSolved, $icon);
}

// Показать конкретные кросс-доменные открытия лучшей пчелы
$best = $bees['Bee_CrossFirst'];
$crossDiscoveries = [];
foreach ($best['known'] as $key => $_) {
    foreach ($domainX as $t) {
        if (str_starts_with($key, $t['name'])) {
            $crossDiscoveries[] = $key;
        }
    }
}

if ($crossDiscoveries) {
    echo "\nCross-domain discoveries (Bee_CrossFirst):\n";
    foreach ($crossDiscoveries as $d) {
        echo "  $d\n";
    }
} else {
    echo "\n❌ No cross-domain laws discovered by any bee.\n";
}

echo "\nDone.\n";
