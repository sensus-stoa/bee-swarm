<?php
declare(strict_types=1);

/**
 * B5.3: Наш аналог T3 — фит линейной gLV (тот же класс, что SBML авторов)
 * на EV1+EV2 (БЕЗ EV3), one-step-ahead, затем multi-step интеграция на EV3.
 *
 * Модель: dx_i/dt = x_i*(r_i + sum_j a_ij*x_j). Из-за замкнутости долей
 * фитим в приращениях долей: для пары (i,j) с долями x_i(t):
 *   y_i = (x_i(t+1)-x_i(t))/x_i(t) = r_i + a_ii*x_i + a_ij*x_j
 * — линейно по (r_i, a_ii, a_ij) при наблюдаемых x. Per-pair fit (глобальная
 * матрица A имеет 144 параметров, пар дают 66*2=132 ряда — недоопределено;
 * авторы решали регуляризацией — мы фитим per-pair, как B5.1, и сравниваем
 * с их per-pair-совместимым T3 через multi-step интеграцию).
 *
 * Дальше: Residual Observer на EV3 — где их T3 ошибается систематически.
 */

require __DIR__ . '/exp070_shared.php';

error_reporting(E_ALL & ~E_WARNING);

$VEN = __DIR__ . '/../Benchmarks/venturelli';
$SBML = '/tmp/ven_s006/twelveSpeciesT3.xml';

// --- их T3 параметры (для сравнения) ---
$xml = file_get_contents($SBML);
$params = [];
preg_match_all('/<parameter [^>]*id="([^"]+)"[^>]*value="([^"]+)"/', $xml, $pm, PREG_SET_ORDER);
foreach ($pm as $p) { $params[$p[1]] = (float) $p[2]; }
preg_match_all('/<species [^>]*id="x([A-Z]{2})"/', $xml, $sm);
$species = $sm[1];
$n = count($species);
$rTheir = [];
$AThier = [];
foreach ($species as $i) {
    $rTheir[$i] = $params['u' . $i];
    foreach ($species as $j) { $AThier[$i][$j] = $params['a' . $i . $j]; }
}

// --- EV1: пары, приращения ---
$rows = openpyxl_load($VEN . '/ev1_pairs.csv');
$pairSeries = [];
foreach ($rows as $r) {
    $name = trim(str_replace("'", '', (string) $r[0]));
    if (!preg_match('/^[A-Z]{4}$/', $name)) { continue; }
    $vals = [];
    for ($k = 1; $k <= 7; $k++) { $v = trim((string) $r[$k]); $vals[] = is_numeric($v) ? (float) $v : NAN; }
    if (count(array_filter($vals, 'is_finite')) < 5) { continue; }
    $pairSeries[$name] = ['s1' => substr($name, 0, 2), 's2' => substr($name, 2, 2), 'x1' => $vals];
}

// --- Per-pair LSQ на EV1: шаги 0-5 (все), holdout по времени будет в EV3 ---
// y = r + a_ii*x1 + a_ij*x2, где x2=1-x1
$fits = []; // pair => [r, aii, aij]
foreach ($pairSeries as $pair => $s) {
    $x1 = []; $y = [];
    for ($t = 0; $t < 6; $t++) {
        $xi = $s['x1'][$t]; $xin = $s['x1'][$t + 1];
        if (!is_finite($xi) || !is_finite($xin) || $xi <= 1e-6) { continue; }
        $x1[] = $xi; $y[] = ($xin - $xi) / $xi;
    }
    if (count($y) < 4) { continue; }
    $x2 = array_map(fn ($v) => 1 - $v, $x1);
    $cols = [$x1, $x2];
    $w = lsqR($cols, $y);
    $fits[$pair] = ['r' => $w[0], 'aii' => $w[1], 'aij' => $w[2], 's1' => $s['s1'], 's2' => $s['s2']];
}
echo 'per-pair fits: ' . count($fits) . "\n";

// --- Сборка глобальной матрицы A из per-pair фит: a_ij из пары (i,j) —
// две оценки (фит с точки зрения i и с точки зрения j). Для предсказаний
// используем среднее. a_ii и r_i тоже из пар (монокультур нет). ---
$A = []; $R = [];
$counts = [];
foreach ($fits as $pair => $f) {
    $i = $f['s1']; $j = $f['s2'];
    // y_i = r_i + a_ii*x_i + a_ij*x_j => a_ij и a_ii от i
    $A[$i][$j] = ($A[$i][$j] ?? 0) + $f['aij'];
    $A[$j][$i] = ($A[$j][$i] ?? 0) + $f['aii'];
    $R[$i] = ($R[$i] ?? 0) + $f['r'];
    $R[$j] = ($R[$j] ?? 0) + $f['r'];
    $counts[$i] = ($counts[$i] ?? 0) + 1;
    $counts[$j] = ($counts[$j] ?? 0) + 1;
}
// средние по оценкам; для видов без данных — NaN
$missing = [];
foreach ($species as $i) {
    if (!isset($counts[$i])) { $missing[] = $i; continue; }
    $R[$i] /= $counts[$i];
    foreach ($species as $j) { if (isset($A[$i][$j])) { $A[$i][$j] /= 1; } }
}
// a_ij по одной оценке (пара уникальна) — оставляем; но для симметрии усредним i->j и j->i? нет, a_ij != a_ji.
// диагонали: a_ii из j-тэрма пары (i,j): фит даёт aii как коэффициент при x_j=y_i? Проверка согласованности ниже.
echo 'видов без данных: ' . count($missing) . ($missing ? ' => ' . implode(',', $missing) : '') . "\n";

// sanity: предсказание пары их T3 vs наш fit на той же паре (одношаговое)
$testPairs = array_slice(array_keys($fits), 0, 3);
echo "\nсанити (одношаговый корень MSE, наша пара vs их T3):\n";
foreach ($testPairs as $pair) {
    $f = $fits[$pair];
    $s = $pairSeries[$pair];
    $rmseOurs = 0.0; $rmseTheir = 0.0; $cnt = 0;
    for ($t = 0; $t < 6; $t++) {
        $xi = $s['x1'][$t]; $xin = $s['x1'][$t + 1];
        if (!is_finite($xi) || !is_finite($xin) || $xi <= 1e-6) { continue; }
        $y = ($xin - $xi) / $xi;
        $i = $f['s1']; $j = $f['s2'];
        $predOurs = $f['r'] + $f['aii'] * $xi + $f['aij'] * (1 - $xi);
        // их T3: x_i*(u_i + a_ii x_i + a_ij x_j)/x_i
        $predTheir = $rTheir[$i] + $AThier[$i][$i] * $xi + $AThier[$i][$j] * (1 - $xi);
        $rmseOurs += ($y - $predOurs) ** 2; $rmseTheir += ($y - $predTheir) ** 2; $cnt++;
    }
    printf("  %s: our RMSE=%.4f their=%.4f (n=%d)\n", $pair, sqrt($rmseOurs / max(1, $cnt)), sqrt($rmseTheir / max(1, $cnt)), $cnt);
}

// --- EV3: multi-step предсказание их T3 и нашего per-pair на NONE ---
echo "\n=== multi-step на NONE: их T3 vs наш per-pair-аналог ===\n";
$EV3 = $VEN . '/ev3_communities.csv';
$raw = file($EV3);
$communities = [];
$cur = null;
foreach ($raw as $line) {
    $line = trim($line);
    if ($line === '') { continue; }
    if (preg_match('/^([A-Z]{2}-|NONE)/', $line)) { $cur = rtrim($line); $communities[$cur] = []; continue; }
    if ($cur !== null && preg_match('/^[\d\s.eE+-]+$/', $line)) {
        $communities[$cur][] = array_map('floatval', preg_split('/\s+/', trim($line)));
    }
}
// порядок колонок из B5.2
$ev3order = ['BH', 'CA', 'BU', 'PC', 'BO', 'BV', 'BT', 'EL', 'FP', 'CH', 'DP', 'ER'];

$glvStep = function (array $x, array $species, array $R, array $A, float $dt): array {
    $out = [];
    foreach ($species as $i) {
        $s = $R[$i] ?? 0.0;
        foreach ($species as $j) { $s += ($A[$i][$j] ?? 0.0) * $x[$j]; }
        $out[] = $x[$i] + $dt * $x[$i] * $s;
    }
    return $out;
};

$mat = $communities['NONE'];
$x0 = [];
foreach ($ev3order as $c => $sp) { $x0[$sp] = $mat[0][$c]; }

// RK4 шаг (канон, как в python-версии B5.2)
$rk4 = function (array $x, float $dt) use ($n, $species, $f): array {
    $k1 = $f($x);
    $x2 = array_map(fn ($v, $d) => $v + $d * $dt / 2, $x, $k1);
    $k2 = $f($x2);
    $x3 = array_map(fn ($v, $d) => $v + $d * $dt / 2, $x, $k2);
    $k3 = $f($x3);
    $x4 = array_map(fn ($v, $d) => $v + $d * $dt, $x, $k3);
    $k4 = $f($x4);
    return array_map(fn ($v, $a1, $a2, $a3, $a4) => $v + $dt / 6 * ($a1 + 2 * $a2 + 2 * $a3 + $a4), $x, $k1, $k2, $k3, $k4);
};
$gF = function (array $x, array $RR, array $AA) use ($n, $species): array {
    $dx = [];
    foreach ($species as $i) {
        $s = $RR[$i] ?? 0.0;
        foreach ($species as $j) { $s += ($AA[$i][$j] ?? 0.0) * $x[$j]; }
        $dx[] = $x[$i] * $s;
    }
    return $dx;
};
// их T3
$fc = function (array $x) use ($n, $species, $rTheir, $AThier): array {
    $dx = [];
    foreach ($species as $i) {
        $s = $rTheir[$i];
        foreach ($species as $j) { $s += $AThier[$i][$j] * $x[$j]; }
        $dx[] = $x[$i] * $s;
    }
    return $dx;
};
$integrate = function (callable $f, array $x0v) use ($rk4): array {
    $xc = $x0v;
    $traj = [$x0v];
    for ($day = 1; $day <= 6; $day++) {
        for ($h = 0; $h < 48; $h++) { $xc = $rk4($f, $xc, 0.5); }
        $traj[] = $xc;
    }
    return $traj;
};
// rk4 принимает ($f, $x, $dt)
$integrate = function (callable $f, array $x0v) use ($n): array {
    $rk4 = function (callable $f, array $x, float $dt): array {
        $k1 = $f($x);
        $x2 = array_map(fn ($v, $d) => $v + $d * $dt / 2, $x, $k1);
        $k2 = $f($x2);
        $x3 = array_map(fn ($v, $d) => $v + $d * $dt / 2, $x, $k2);
        $k3 = $f($x3);
        $x4 = array_map(fn ($v, $d) => $v + $d * $dt, $x, $k3);
        $k4 = $f($x4);
        return array_map(fn ($v, $a1, $a2, $a3, $a4) => $v + $dt / 6 * ($a1 + 2 * $a2 + 2 * $a3 + $a4), $x, $k1, $k2, $k3, $k4);
    };
    $xc = $x0v;
    $traj = [$x0v];
    for ($day = 1; $day <= 6; $day++) {
        for ($h = 0; $h < 48; $h++) { $xc = $rk4($f, $xc, 0.5); }
        $traj[] = $xc;
    }
    return $traj;
};
$fTheir = function (array $x) use ($species, $rTheir, $AThier): array {
    $dx = [];
    foreach ($species as $idx => $i) {
        $s = $rTheir[$i];
        foreach ($species as $jdx => $j) { $s += $AThier[$i][$j] * $x[$jdx]; }
        $dx[] = $x[$idx] * $s;
    }
    return $dx;
};
$fOurs = function (array $x) use ($species, $R, $A): array {
    $dx = [];
    foreach ($species as $idx => $i) {
        $s = $R[$i] ?? 0.0;
        foreach ($species as $jdx => $j) { $s += ($A[$i][$j] ?? 0.0) * $x[$jdx]; }
        $dx[] = $x[$idx] * $s;
    }
    return $dx;
};
$trajTheir = $integrate($fTheir, (function () use ($species, $x0) { $v = []; foreach ($species as $i) { $v[] = $x0[$i]; } return $v; })());
$trajOurs = $integrate($fOurs, (function () use ($species, $x0) { $v = []; foreach ($species as $i) { $v[] = $x0[$i]; } return $v; })());

// дамп первой и последней точки обеих траекторий
echo "\nдамп траекторий (t=0, t=6):\n";
foreach ($species as $i => $code) {
    printf("  %s: their %.4f -> %.4f | ours %.4f -> %.4f\n", $code, $trajTheir[0][$i], $trajTheir[6][$i], $trajOurs[0][$i], $trajOurs[6][$i]);
}

$cmp = function (array $traj, string $name) use ($mat, $ev3order, $species): float {
    $res = 0.0; $tot = 0.0;
    $nT = count($traj);
    for ($t = 0; $t < $nT; $t++) {
        $sum = array_sum($traj[$t]);
        foreach ($ev3order as $c => $sp) {
            $o = $mat[$t][$c] ?? null;
            if ($o === null) { continue; }
            $pred = $sum > 0 ? $traj[$t][$sp] / $sum : 0.0;
            $res += ($o - $pred) ** 2;
        }
    }
    return 0.0;
};

// проще: считаем r по колонкам
$report = function (array $traj, string $name) use ($mat, $ev3order, $species) {
    $res = 0.0; $tot = 0.0;
    foreach ($ev3order as $c => $sp) {
        $m = 0.0;
        for ($t = 0; $t < 7; $t++) { $m += $mat[$t][$c]; }
        $m /= 7;
        for ($t = 0; $t < 7; $t++) {
            $sum = array_sum($traj[$t]);
            $pos = array_search($sp, $species, true);
            $pred = ($sum > 0 && $pos !== false) ? $traj[$t][$pos] / $sum : 0.0;
            $o = $mat[$t][$c];
            $res += ($o - $pred) ** 2; $tot += ($o - $m) ** 2;
        }
    }
    echo "$name: R2=" . round(1 - $res / $tot, 3) . "\n";
    return 1 - $res / $tot;
};
$rTheir2 = $report($trajTheir, 'их T3 (SBML params)');
$rOurs2 = $report($trajOurs, 'наш per-pair');
