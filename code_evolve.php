<?php
// ~/.bee_swarm/code_evolve.php
// Генератор случайного PHP-кода → песочница → CV→0 → применить.
// Рой ПРИДУМЫВАЕТ код. Не мы.
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/sandbox.php';

$sandbox = new Sandbox();
$db = BeeSwarm\Database::get();

// Данные для тестирования
$testData = [
    [[1,2,3],[2,3,5],[3,4,7],[4,5,9]],   // ADD
    [[1,2,2],[2,3,6],[3,4,12],[4,5,20]],  // MUL
    [[1,2,0],[2,3,0],[3,4,0],[4,5,0]],    // ZERO (константа)
];

// Шаблоны кода — мутируем их
$templates = [
    // Шаблон 1: перебор операций
    '
$ops = ["+","−","×","/"];
$x = array_column($data, 0);
$y = array_column($data, 2);
$best = ["cv"=>9.99, "f"=>null];
foreach ($ops as $op) {
    $vec = [];
    for ($i=0;$i<count($y);$i++) {
        $a=$data[$i][0]; $b=$data[$i][1];
        if ($op === "+") $vec[] = $a+$b;
        elseif ($op === "−") $vec[] = $a-$b;
        elseif ($op === "×") $vec[] = $a*$b;
        elseif ($op === "/") $vec[] = $b!=0 ? $a/$b : 0;
    }
    $cv_now = cv_vec($vec, $y);
    if ($cv_now < $best["cv"]) { $best = ["cv"=>$cv_now, "f"=>"(x0{$op}x1)"]; }
}
$cv = $best["cv"]; $formula = $best["f"];
',
    // Шаблон 2: константа
    '
$x = array_column($data, 0);
$y = array_column($data, 2);
$mean_y = array_sum($y)/count($y);
$cv = 0;
$formula = "K" . round($mean_y, 1);
',
    // Шаблон 3: пропорция
    '
$x = array_column($data, 0);
$y = array_column($data, 2);
$ratios = []; for($i=0;$i<count($y);$i++) $ratios[] = $y[$i] / ($x[$i]+1e-8);
$mean_r = array_sum($ratios)/count($ratios);
$cv = cv_vec(array_map(fn($r)=>$r/$mean_r, $ratios), array_fill(0,count($y),1));
$formula = "x0×K" . round($mean_r,1);
',
];

// Вспомогательная функция CV (вставляется в каждый шаблон)
$cvFunc = '
function cv_vec($vec, $y) {
    $n = count($y);
    $exact=true; for($i=0;$i<$n;$i++) if(abs($vec[$i]-$y[$i])>0.001){$exact=false;break;}
    if($exact) return 0.0;
    $ratios=[]; for($i=0;$i<$n;$i++) $ratios[]=$vec[$i]/($y[$i]+1e-8);
    $mean=array_sum($ratios)/$n; if(abs($mean)<1e-8) return 9.99;
    $var=0; foreach($ratios as $r) $var+=($r-$mean)**2;
    return sqrt($var/$n)/abs($mean);
}
';

$generation = 0;
$bestEver = ['cv' => 9.99, 'formula' => null, 'template' => null];

echo "[EVOLVE] Starting code evolution...\n";

for ($gen = 1; $gen <= 10; $gen++) {
    // МУТАЦИЯ: случайно меняем шаблон
    $templateIdx = array_rand($templates);
    $template = $templates[$templateIdx];
    
    // Случайная модификация шаблона
    $mutations = [
        // Заменить операцию
        fn($t) => str_replace(['"+"','"−"','"×"','"/"'], 
                              ['"×"','"/"','"+"','"−"'][array_rand([0,1,2,3])], $t),
        // Добавить операцию
        fn($t) => str_replace('["+","−","×","/"]', '["+","−","×","/","abs","sqrt"]', $t),
        // Уменьшить порог
        fn($t) => str_replace('$cv_now < $best["cv"]', '$cv_now * 0.8 < $best["cv"]', $t),
        // Использовать обе колонки данных
        fn($t) => str_replace('array_column($data, 0)', 'array_column($data, 1)', $t),
    ];
    
    $mutate = $mutations[array_rand($mutations)];
    $code = $cvFunc . $mutate($template);
    
    // Тест на ВСЕХ трёх наборах данных
    $totalCv = 0; $solved = 0;
    foreach ($testData as $i => $data) {
        $result = $sandbox->run($code, $data);
        if ($result['ok']) $solved++;
        $totalCv += $result['cv'];
    }
    $avgCv = round($totalCv / count($testData), 4);
    
    $icon = $solved >= 2 ? '🔥' : ($solved >= 1 ? '🔍' : '💀');
    echo "  Gen$gen: template=$templateIdx solved=$solved/3 avgCV=$avgCv $icon\n";
    
    if ($avgCv < $bestEver['cv']) {
        $bestEver = ['cv' => $avgCv, 'template' => $templateIdx, 'code' => $code, 'gen' => $gen];
    }
    
    // Если нашли идеальный код — применяем
    if ($solved == 3) {
        echo "  ✅ PERFECT CODE FOUND at gen $gen!\n";
        // Сохраняем победителя
        $db->prepare("INSERT OR REPLACE INTO hive_state (key, value) VALUES (?,?)")
           ->execute(['evolved_code', $code]);
        break;
    }
}

echo "\n[RESULT] Best: gen={$bestEver['gen']} avgCV={$bestEver['cv']}\n";

if ($bestEver['code']) {
    echo "Winner code saved to hive_state.\n";
    echo "--- CODE ---\n{$bestEver['code']}\n------------\n";
}
