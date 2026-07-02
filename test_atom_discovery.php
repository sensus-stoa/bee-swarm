<?php
// ~/.bee_swarm/test_atom_discovery.php
// ГИПОТЕЗА: среда (PHP + данные) содержит отношения.
// Случайная мутация → тест на данных → CV→0 → отношение → атом grammar.
// Без хардкода операций. Без MetaInventor.

require_once __DIR__ . '/vendor/autoload.php';
use BeeSwarm\Grammar;
use BeeSwarm\Search;
use BeeSwarm\Database;

date_default_timezone_set('Europe/Moscow');

// ═══ СРЕДА: базовые отношения (алфавит PHP) ═══
// Это НЕ хардкод атомов. Это то, что УЖЕ доступно в среде исполнения.
// Как H₂O, CH₄, NH₃ — доступны, не изобретаются.

$environment = [
    // Унарные преобразования (всегда один аргумент)
    'unary' => [
        'abs'     => fn($x) => abs($x),
        'neg'     => fn($x) => -$x,
        'inv'     => fn($x) => $x != 0 ? 1/$x : null,
        'sq'      => fn($x) => $x * $x,
        'cube'    => fn($x) => $x * $x * $x,
        'sqrt'    => fn($x) => $x >= 0 ? sqrt($x) : null,
        'sin'     => fn($x) => sin($x),
        'cos'     => fn($x) => cos($x),
        'exp'     => fn($x) => $x < 20 ? exp($x) : null,
        'log'     => fn($x) => $x > 0 ? log($x) : null,
        'log10'   => fn($x) => $x > 0 ? log10($x) : null,
        'floor'   => fn($x) => floor($x),
        'ceil'    => fn($x) => ceil($x),
        'round'   => fn($x) => round($x),
        'sign'    => fn($x) => $x > 0 ? 1 : ($x < 0 ? -1 : 0),
        'relu'    => fn($x) => $x > 0 ? $x : 0,
        'sigmoid' => fn($x) => $x < 20 ? 1/(1+exp(-$x)) : ($x > 0 ? 1.0 : 0.0),
    ],
    // Бинарные преобразования
    'binary' => [
        'add'  => fn($a,$b) => $a + $b,
        'sub'  => fn($a,$b) => $a - $b,
        'mul'  => fn($a,$b) => $a * $b,
        'div'  => fn($a,$b) => $b != 0 ? $a/$b : null,
        'mod'  => fn($a,$b) => $b != 0 ? $a % $b : null,
        'pow'  => fn($a,$b) => $a >= 0 || $b == (int)$b ? $a ** $b : null,
        'min'  => fn($a,$b) => min($a,$b),
        'max'  => fn($a,$b) => max($a,$b),
        'hypot'=> fn($a,$b) => hypot($a,$b),
    ],
    // Сравнения (всегда 2 аргумента, возвращают 0 или 1)
    'compare' => [
        'gt'  => fn($a,$b) => $a > $b ? 1.0 : 0.0,
        'lt'  => fn($a,$b) => $a < $b ? 1.0 : 0.0,
        'eq'  => fn($a,$b) => abs($a-$b) < 0.001 ? 1.0 : 0.0,
        'neq' => fn($a,$b) => abs($a-$b) >= 0.001 ? 1.0 : 0.0,
    ],
];

// ═══ ТЕСТОВЫЕ ЗАДАЧИ (среда предъявляет) ═══

$tasks = [
    // Уже решаемые существующей grammar
    ['name'=>'ADD',   'data'=>[[1,2,3],[3,4,7],[5,6,11]]],
    ['name'=>'MUL',   'data'=>[[1,2,2],[2,3,6],[3,4,12]]],
    ['name'=>'DIV',   'data'=>[[6,2,3],[12,3,4],[20,4,5]]],
    // Требуют новых атомов
    ['name'=>'SQRT',  'data'=>[[1,1],[4,2],[9,3],[16,4]]],
    ['name'=>'MIN',   'data'=>[[0,0,0],[2,3,2],[5,1,1],[4,4,4]]],
    ['name'=>'POW2',  'data'=>[[0,1],[1,2],[2,4],[3,8],[4,16]]],
    ['name'=>'SIN_WAVE','data'=>[[0,0,0],[1,3.14,0],[2,6.28,0],[3,1.57,1]]],
    // Семантические (требуют сравнений)
    ['name'=>'GT',    'data'=>[[1,2,0],[3,2,1],[5,1,1],[0,4,0]]],
    ['name'=>'EQ',    'data'=>[[1,1,1],[2,2,1],[3,3,1],[1,2,0]]],
];

// ═══ CV CALC ═══
function computeCV(array $vec, array $y): float {
    $n = count($vec);
    $exact = true;
    for ($i = 0; $i < $n; $i++) {
        if (abs($vec[$i] - $y[$i]) > 0.001) { $exact = false; break; }
    }
    if ($exact) return 0.0;
    $ratio = [];
    for ($i = 0; $i < $n; $i++) $ratio[] = $vec[$i] / ($y[$i] + 1e-8);
    $mean = array_sum($ratio) / $n;
    if (abs($mean) < 1e-8) return 9.99;
    $variance = 0;
    foreach ($ratio as $r) $variance += ($r - $mean) ** 2;
    return sqrt($variance / $n) / abs($mean);
}

// ═══ БРУТФОРС: перебор всех отношений на всех задачах ═══
// Это аналог Search::find, но на ВСЕХ отношениях среды.
// Цель: узнать КАКИЕ отношения дают CV→0 на каких задачах.

echo "══════════════════════════════════════\n";
echo "  ATOM DISCOVERY — брутфорс среды\n";
echo "══════════════════════════════════════\n\n";

$discoveries = []; // [task_name => [atom_name => cv]]

foreach ($tasks as $task) {
    $name = $task['name'];
    $data = $task['data'];
    $X = array_map(fn($r) => array_slice($r, 0, -1), $data);
    $y = array_column($data, count($data[0]) - 1);
    $n = count($y);
    $nFeat = count($X[0] ?? []);
    
    echo "─── $name (feat=$nFeat, pts=$n) ───\n";
    
    $bestPerCategory = [];
    
    // 1. ПЕРЕБОР УНАРНЫХ на каждом признаке
    foreach (['unary', 'binary', 'compare'] as $cat) {
        $bestPerCategory[$cat] = ['cv' => 9.99, 'atom' => null];
    }
    
    foreach ($environment['unary'] as $opName => $fn) {
        for ($fi = 0; $fi < $nFeat; $fi++) {
            $col = array_column($X, $fi);
            $vec = [];
            $valid = true;
            foreach ($col as $v) {
                $r = $fn($v);
                if ($r === null || is_nan($r) || is_infinite($r)) { $valid = false; break; }
                $vec[] = $r;
            }
            if (!$valid) continue;
            $cv = computeCV($vec, $y);
            if ($cv < $bestPerCategory['unary']['cv']) {
                $bestPerCategory['unary'] = ['cv' => $cv, 'atom' => "{$opName}(x{$fi})"];
            }
        }
    }
    
    // 2. ПЕРЕБОР БИНАРНЫХ на парах признаков
    if ($nFeat >= 2) {
        foreach ($environment['binary'] as $opName => $fn) {
            for ($a = 0; $a < $nFeat; $a++) {
                for ($b = 0; $b < $nFeat; $b++) {
                    $cola = array_column($X, $a);
                    $colb = array_column($X, $b);
                    $vec = [];
                    $valid = true;
                    for ($i = 0; $i < $n; $i++) {
                        $r = $fn($cola[$i], $colb[$i]);
                        if ($r === null || is_nan($r) || is_infinite($r)) { $valid = false; break; }
                        $vec[] = $r;
                    }
                    if (!$valid) continue;
                    $cv = computeCV($vec, $y);
                    if ($cv < $bestPerCategory['binary']['cv']) {
                        $bestPerCategory['binary'] = ['cv' => $cv, 'atom' => "{$opName}(x{$a},x{$b})"];
                    }
                }
            }
        }
    }
    
    // 3. ПЕРЕБОР СРАВНЕНИЙ
    if ($nFeat >= 2) {
        foreach ($environment['compare'] as $opName => $fn) {
            for ($a = 0; $a < $nFeat; $a++) {
                for ($b = 0; $b < $nFeat; $b++) {
                    $cola = array_column($X, $a);
                    $colb = array_column($X, $b);
                    $vec = [];
                    for ($i = 0; $i < $n; $i++) {
                        $vec[] = $fn($cola[$i], $colb[$i]);
                    }
                    $cv = computeCV($vec, $y);
                    if ($cv < $bestPerCategory['compare']['cv']) {
                        $bestPerCategory['compare'] = ['cv' => $cv, 'atom' => "{$opName}(x{$a},x{$b})"];
                    }
                }
            }
        }
    }
    
    // Вывод результатов
    foreach ($bestPerCategory as $cat => $best) {
        $icon = $best['cv'] < 0.01 ? '✅' : ($best['cv'] < 0.1 ? '🔍' : '❌');
        printf("  %s %-8s CV=%.4f  %s\n", $icon, $cat, $best['cv'], $best['atom'] ?: '-');
        if ($best['cv'] < 0.01) {
            $discoveries[] = [
                'task' => $name,
                'category' => $cat,
                'atom' => $best['atom'],
                'cv' => $best['cv'],
            ];
        }
    }
    echo "\n";
}

// ═══ ИТОГИ: какие атомы обнаружены ═══
echo "══════════════════════════════════════\n";
echo "  DISCOVERED ATOMS (CV=0)\n";
echo "══════════════════════════════════════\n";

if (empty($discoveries)) {
    echo "  Ничего не обнаружено.\n";
} else {
    $atomsByTask = [];
    foreach ($discoveries as $d) {
        $atomsByTask[$d['task']][] = $d['atom'];
    }
    foreach ($atomsByTask as $task => $atoms) {
        echo "  $task:\n";
        foreach ($atoms as $a) echo "    → $a\n";
    }
}

// ═══ СРАВНЕНИЕ С ТЕКУЩЕЙ GRAMMAR ═══
echo "\n══════════════════════════════════════\n";
echo "  ТЕКУЩАЯ GRAMMAR vs СРЕДА\n";
echo "══════════════════════════════════════\n";

$grammar = new Grammar();
$grammarOps = $grammar->all();
echo "  Grammar ops: " . implode(', ', $grammarOps) . "\n";
echo "  Grammar count: " . count($grammarOps) . "\n";

$envOps = array_merge(
    array_keys($environment['unary']),
    array_keys($environment['binary']),
    array_keys($environment['compare'])
);
echo "  Environment ops: " . count($envOps) . " available\n";

$missing = [];
foreach ($discoveries as $d) {
    $baseOp = explode('(', $d['atom'])[0];
    if (!in_array($baseOp, $grammarOps) && !in_array($baseOp, $grammarOps)) {
        $missing[$baseOp] = true;
    }
}
if ($missing) {
    echo "  Missing from grammar: " . implode(', ', array_keys($missing)) . "\n";
    echo "  → Эти атомы среда дала, но grammar их не видит.\n";
}

echo "\nDone.\n";
