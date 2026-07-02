<?php
// ~/.bee_swarm/test_self_tasks.php
// ТЕСТ: рой сам генерит задачи растущей сложности
// Варианты: inverse | compose | random-features | holdout

require_once __DIR__ . '/vendor/autoload.php';
use BeeSwarm\AtomRegistry;
use BeeSwarm\Grammar;

// ═══ ВАРИАНТ 1: INVERSE — обратные задачи ═══
// Закон: add(x,y)=z → задача: sub(z,y)=x
function generateInverseTasks(array $grammarOps): array {
    $tasks = [];
    $inverses = [
        'add' => 'sub', 'sub' => 'add',
        'mul' => 'div', 'div' => 'mul',
        'sq'  => 'sqrt', 'sqrt' => 'sq',
    ];
    
    foreach ($inverses as $fwd => $inv) {
        if (!in_array($fwd, $grammarOps) || !in_array($inv, $grammarOps)) continue;
        
        // Генерируем данные: fwd(x, y) → z, задача: inv(z, y) → x
        $data = [];
        for ($i = 0; $i < 8; $i++) {
            $x = mt_rand(1, 20);
            $y = mt_rand(1, 10);
            $z = AtomRegistry::apply($fwd, (float)$x, (float)$y);
            if ($z !== null && !is_nan($z) && !is_infinite($z)) {
                $data[] = [(float)$z, (float)$y, (float)$x];
            }
        }
        if (count($data) >= 4) {
            $tasks[] = ['name' => "INV_{$fwd}_to_{$inv}", 'data' => $data, 'domain' => 'generated'];
        }
    }
    return $tasks;
}

// ═══ ВАРИАНТ 2: COMPOSE — задачи из пар grammar-атомов ═══
function generateComposeTasks(array $grammarOps): array {
    $tasks = [];
    
    foreach ($grammarOps as $outer) {
        foreach ($grammarOps as $inner) {
            if ($outer === $inner) continue;
            if (!AtomRegistry::isUnary($outer) && !AtomRegistry::isBinary($outer)) continue;
            
            $data = [];
            for ($i = 0; $i < 8; $i++) {
                $x = mt_rand(1, 15);
                $y = mt_rand(1, 15);
                
                $v1 = AtomRegistry::apply($inner, (float)$x, (float)$y);
                if ($v1 === null || is_nan($v1) || is_infinite($v1)) continue;
                
                $v2 = AtomRegistry::isBinary($outer) 
                    ? AtomRegistry::apply($outer, $v1, (float)mt_rand(1, 5))
                    : AtomRegistry::apply($outer, $v1);
                    
                if ($v2 === null || is_nan($v2) || is_infinite($v2)) continue;
                
                $data[] = [(float)$x, (float)$y, $v2];
            }
            
            if (count($data) >= 3) {
                $tasks[] = [
                    'name' => "COMP_{$outer}_{$inner}", 
                    'data' => $data, 
                    'domain' => 'generated',
                    'answer' => "{$outer}({$inner})"
                ];
            }
        }
    }
    return $tasks;
}

// ═══ ВАРИАНТ 3: CROSS-FEATURE — смешиваем признаки из разных задач ═══
function generateCrossFeatureTasks(): array {
    $tasks = [];
    $features = [
        'x'  => [1,3,5,7,2,4,6,8],
        'x2' => [2,6,10,14,4,8,12,16],
        'sq' => [1,9,25,49,4,16,36,64],
        'neg'=> [-1,-3,-5,-7,-2,-4,-6,-8],
    ];
    
    $keys = array_keys($features);
    foreach ($keys as $i => $k1) {
        foreach ($keys as $k2) {
            if ($k1 === $k2) continue;
            $data = [];
            for ($j = 0; $j < count($features[$k1]); $j++) {
                $data[] = [$features[$k1][$j], $features[$k2][$j], 
                          $features[$k1][$j] + $features[$k2][$j]];
            }
            if (count($data) >= 4) {
                $tasks[] = ['name' => "CROSS_{$k1}_{$k2}", 'data' => $data, 'domain' => 'generated'];
            }
        }
    }
    return $tasks;
}

// ═══ ВАРИАНТ 4: HOLDOUT — скрытая часть данных ═══
function generateHoldoutTasks(array $baseTasks): array {
    $tasks = [];
    foreach ($baseTasks as $bt) {
        $data = $bt['data'];
        if (count($data) < 6) continue;
        
        // Берём первые 4 для обучения, остальные — holdout
        $holdout = array_slice($data, 4);
        $tasks[] = [
            'name' => "HOLD_{$bt['name']}",
            'data' => $holdout,
            'domain' => 'generated',
        ];
    }
    return $tasks;
}

// ═══ ТЕСТ: генерируем задачи, проверяем какие решаются ═══
echo "══════════════════════════════════════\n";
echo "  SELF-GENERATING TASKS\n";
echo "══════════════════════════════════════\n\n";

// Имитация: grammar после первых открытий
$grammar = ['add','sub','mul','div','sq','sqrt','abs','min','max','and','or','gt','lt','eq','neq'];

// 1. Inverse
$invTasks = generateInverseTasks($grammar);
echo "Inverse:    " . count($invTasks) . " tasks\n";

// 2. Compose (ограничим чтобы не взорваться)
$smallGrammar = ['add','sub','mul','sq','abs','min'];
$compTasks = generateComposeTasks($smallGrammar);
echo "Compose:    " . count($compTasks) . " tasks\n";

// 3. Cross-feature
$crossTasks = generateCrossFeatureTasks();
echo "Cross-feat: " . count($crossTasks) . " tasks\n\n";

// Проверяем: какие из них решаются ТОЛЬКО compose (а не простыми атомами)?
$needCompose = 0;
$solvedSimple = 0;
$unsolved = 0;

foreach (array_merge($invTasks, $compTasks, $crossTasks) as $task) {
    if (count($task['data']) < 2) continue;
    
    $X = array_map(fn($r) => array_slice($r, 0, -1), $task['data']);
    $y = array_column($task['data'], count($task['data'][0]) - 1);
    
    // Простые атомы
    $simple = AtomRegistry::discover($X, $y);
    // Compose
    $composed = AtomRegistry::discoverCompose($X, $y, $grammar);
    
    if (!empty($composed) && empty($simple)) {
        $needCompose++;
        $answer = $composed[0]['atom'];
        $expected = $task['answer'] ?? '?';
        $match = ($answer === $expected) ? '✅' : '🔍';
        echo "$match {$task['name']}: $answer (expected: $expected)\n";
    } elseif (!empty($simple)) {
        $solvedSimple++;
    } else {
        $unsolved++;
    }
}

echo "\nNeed compose: $needCompose | Solved simple: $solvedSimple | Unsolved: $unsolved\n";

// ═══ ИТОГ ═══
echo "\nВывод: compose-задачи = " . round($needCompose/($needCompose+$solvedSimple+$unsolved)*100) . "% от сгенерированных\n";
echo "Эти задачи НЕ решаются простыми атомами — только compose.\n";
echo "Рой может генерировать их сам из grammar_ops каждые N тиков.\n";
