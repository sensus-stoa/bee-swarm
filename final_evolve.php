<?php
// ~/.bee_swarm/final_evolve.php
// ПОСЛЕДНИЙ МОДУЛЬ. AGI-ЦИКЛ.
// Генерация случайного PHP → песочница → НЕЗАВИСИМАЯ валидация → применить.
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/sandbox.php';

$sandbox = new Sandbox();
$db = BeeSwarm\Database::get();

// ═══ НЕЗАВИСИМАЯ CV-ВАЛИДАЦИЯ ═══
// Код в песочнице возвращает $formula. МЫ сами вычисляем CV.
function validateFormula(string $formula, array $data): float {
    if (!$formula) return 9.99;
    
    $x = array_column($data, 0);
    $x2 = count($data[0]) > 1 ? array_column($data, 1) : null;
    $y = array_column($data, count($data[0]) - 1);
    $n = count($y);
    
    // Простые формулы
    if ($formula === '(x0+x1)' && $x2) {
        $vec = []; for($i=0;$i<$n;$i++) $vec[]=$x[$i]+$x2[$i];
    } elseif ($formula === '(x0×x1)' && $x2) {
        $vec = []; for($i=0;$i<$n;$i++) $vec[]=$x[$i]*$x2[$i];
    } elseif ($formula === '(x0−x1)' && $x2) {
        $vec = []; for($i=0;$i<$n;$i++) $vec[]=$x[$i]-$x2[$i];
    } elseif (preg_match('/^K([\d.]+)$/', $formula, $m)) {
        $k = (float)$m[1];
        $vec = array_fill(0, $n, $k);
    } elseif (preg_match('/^x0×K([\d.]+)$/', $formula, $m)) {
        $k = (float)$m[1];
        $vec = []; for($i=0;$i<$n;$i++) $vec[]=$x[$i]*$k;
    } elseif (str_starts_with($formula, 'K')) {
        $vec = array_fill(0, $n, 0);
    } else {
        return 9.99; // неизвестная формула
    }
    
    // Compute CV
    $exact = true;
    for($i=0;$i<$n;$i++) if(abs($vec[$i]-$y[$i])>0.001){$exact=false;break;}
    if($exact) return 0.0;
    
    $ratios=[]; for($i=0;$i<$n;$i++) $ratios[]=$vec[$i]/($y[$i]+1e-8);
    $mean=array_sum($ratios)/$n; if(abs($mean)<1e-8) return 9.99;
    $var=0; foreach($ratios as $r) $var+=($r-$mean)**2;
    return sqrt($var/$n)/abs($mean);
}

// ═══ ГЕНЕРАТОР СЛУЧАЙНОГО PHP (токены) ═══
function randomPhpCode(): string {
    $ops = ['+', '−', '×', '/'];
    $op = $ops[array_rand($ops)];
    $useBoth = (bool)mt_rand(0,1);
    $tryConstant = mt_rand(0, 2) === 0;
    
    if ($tryConstant) {
        // Просто: вернуть среднее как формулу
        return '
$y = array_column($data, 2);
$mean = array_sum($y) / count($y);
$formula = "K" . round($mean, 1);
';
    }
    
    if ($useBoth) {
        return '
$x = array_column($data, 0);
$x2 = array_column($data, 1);
$y = array_column($data, 2);
$op = "' . $op . '";
$vec = []; 
for ($i=0;$i<count($y);$i++) {
    $a=$x[$i]; $b=$x2[$i];
    if ($op === "+") $vec[]=$a+$b;
    elseif ($op === "−") $vec[]=$a-$b;
    elseif ($op === "×") $vec[]=$a*$b;
    elseif ($op === "/") $vec[]=$b!=0 ? $a/$b : 0;
}
// Проверка: соотношение константно?
$ratios = []; for($i=0;$i<count($y);$i++) $ratios[]=$vec[$i]/($y[$i]+1e-8);
$mean_r = array_sum($ratios)/count($ratios);
if (abs($mean_r-1.0) < 0.01) {
    $formula = "(x0' . $op . 'x1)";
} else {
    $formula = "K" . round($mean_r, 1);
}
';
    }
    
    // Пропорция: y/x
    return '
$x = array_column($data, 0);
$y = array_column($data, 2);
$ratios = []; for($i=0;$i<count($y);$i++) $ratios[] = $y[$i] / ($x[$i]+1e-8);
$mean_r = array_sum($ratios)/count($ratios);
$formula = "x0×K" . round($mean_r, 1);
';
}

// ═══ ТЕСТОВЫЕ ДАННЫЕ ═══
$testSets = [
    [[1,2,3],[2,3,5],[3,4,7],[4,5,9]],   // ADD
    [[1,2,2],[2,3,6],[3,4,12],[4,5,20]],  // MUL
    [[1,2,0],[2,3,0],[3,4,0],[4,5,0]],    // ZERO
];

echo "[AGI-EVOLVE] Starting...\n";

$bestOverall = ['cv'=>9.99, 'formula'=>null, 'gen'=>0, 'code'=>null];
$generation = 0;

for ($gen = 1; $gen <= 20; $gen++) {
    $generation++;
    
    // Генерируем случайный PHP-код
    $code = randomPhpCode();
    
    // Добавляем функцию CV чтобы код компилировался
    $code = 'function cv_vec($v,$y){$n=count($y);$e=true;for($i=0;$i<$n;$i++)if(abs($v[$i]-$y[$i])>0.001){$e=false;break;}if($e)return 0.0;$r=[];for($i=0;$i<$n;$i++)$r[]=$v[$i]/($y[$i]+1e-8);$m=array_sum($r)/$n;if(abs($m)<1e-8)return 9.99;$q=0;foreach($r as $x)$q+=($x-$m)**2;return sqrt($q/$n)/abs($m);}' . $code;
    
    // Тестируем на ВСЕХ наборах
    $totalCv = 0; $totalSolved = 0; $bestFormula = null;
    foreach ($testSets as $i => $data) {
        $result = $sandbox->run($code, $data);
        $formula = $result['formula'] ?? null;
        
        // 🔥 НЕЗАВИСИМАЯ валидация — код не может соврать
        $realCv = validateFormula($formula, $data);
        
        if ($realCv < 0.01) $totalSolved++;
        $totalCv += $realCv;
        if ($realCv < 0.01 && !$bestFormula) $bestFormula = $formula;
    }
    
    $avgCv = round($totalCv / count($testSets), 4);
    $icon = $totalSolved >= 3 ? '🔥' : ($totalSolved >= 1 ? '🔍' : '.');
    
    if ($gen % 5 === 1 || $totalSolved >= 2) {
        echo "  Gen$gen: solved=$totalSolved/3 avgCV=$avgCv $icon";
        if ($bestFormula) echo " f=$bestFormula";
        echo "\n";
    }
    
    if ($avgCv < $bestOverall['cv'] && $totalSolved >= 1) {
        $bestOverall = ['cv'=>$avgCv, 'formula'=>$bestFormula, 'gen'=>$gen, 'code'=>$code];
    }
    
    // Если все 3 решены — сохраняем и выходим
    if ($totalSolved >= 3) {
        $db->prepare("INSERT OR REPLACE INTO hive_state (key, value) VALUES (?,?)")
           ->execute(['best_evolved_code', $code]);
        $db->prepare("INSERT OR REPLACE INTO hive_state (key, value) VALUES (?,?)")
           ->execute(['best_evolved_formula', $bestFormula]);
        echo "  ✅ ALL SOLVED. Code saved.\n";
        break;
    }
}

echo "\n[BEST] gen={$bestOverall['gen']} cv={$bestOverall['cv']} f={$bestOverall['formula']}\n";

// ═══ ПРИМЕНИТЬ ЛУЧШИЙ КОД ═══
if ($bestOverall['code'] && $bestOverall['cv'] < 0.1) {
    $savedFile = __DIR__ . '/src/EvolvedSearch.php';
    $wrapper = "<?php\n// AUTO-EVOLVED by AGI cycle, gen {$bestOverall['gen']}\n// Formula: {$bestOverall['formula']}, CV: {$bestOverall['cv']}\n\n" . $bestOverall['code'];
    file_put_contents($savedFile, $wrapper);
    echo "[APPLIED] Saved to EvolvedSearch.php\n";
}

echo "[DONE] Generations: $generation\n";
