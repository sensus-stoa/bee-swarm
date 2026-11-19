<?php
declare(strict_types=1);

/**
 * B5.5: Дихотомия по контракту B5.4 (STRUCTURED, терм = f(концентрации)).
 *
 * Кандидаты (все выразимы в +×sq):
 *   M0 (baseline): gLV-пара per-pair на EV1+EV2, интеграция на EV3
 *   M1: + x_i^2 (самонасыщение: x_i^2 в скобке роста)
 *   M2: + x_i*x_j взаимодействие с ДОМИНАНТОМ (top-1 сосед) — контекстный терм
 *   M3: оба терма
 *
 * Фит: one-step-ahead на EV1+EV2 (приращения), затем multi-step RK4-интеграция
 * на EV3 (13 композиций), метрика R2 по видам и общая. EV3 НЕ участвует в фите.
 *
 * Форма приращения вида i: y_i = r_i + a_ii*x_i + sum_j a_ij*x_j
 *   M1: ... + b_ii*x_i^2
 *   M2: ... + c_ij*x_i*x_top1
 * Параметры per-pair НЕ работают для тройных термов (в паре x_i*x_top1 = x_i*x_j
 * коллинеарно) — поэтому M1/M2 фитим как ГЛОБАЛЬНУЮ модель с per-species
 * коэффициентами (12x3 = 36 + термы), регрессией на one-hot вида.
 */

require __DIR__ . '/exp070_shared.php';

error_reporting(E_ALL & ~E_WARNING);

$VEN = __DIR__ . '/../Benchmarks/venturelli';
$SBML = '/tmp/ven_s006/twelveSpeciesT3.xml';

// --- SBML их T3 (baseline для сравнения) ---
$xml = file_get_contents($SBML);
$params = [];
preg_match_all('/<parameter [^>]*id="([^"]+)"[^>]*value="([^"]+)"/', $xml, $pm, PREG_SET_ORDER);
foreach ($pm as $p) { $params[$p[1]] = (float) $p[2]; }
preg_match_all('/<species [^>]*id="x([A-Z]{2})"/', $xml, $sm);
$species = $sm[1];
$n = count($species);
$rT = [];
$AT = [];
foreach ($species as $i) {
    $rT[$i] = $params['u' . $i];
    foreach ($species as $j) { $AT[$i][$j] = $params['a' . $i . $j]; }
}
$fTheir = function (array $x) use ($species, $rT, $AT): array {
    $dx = [];
    foreach ($species as $idx => $i) {
        $s = $rT[$i];
        foreach ($species as $jdx => $j) { $s += $AT[$i][$j] * $x[$jdx]; }
        $dx[] = $x[$idx] * $s;
    }
    return $dx;
};
$integrate = function (callable $f, array $x0v): array {
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

// --- Тренировочный датасет: EV1 (66 пар) + EV2 (30 рядов), приращения ---
// Каждый ряд: вид i в паре с j, наблюдаемые (x_i, x_j) по времени.
// Строка данных: [x_i, x_j, y_i, species_i, species_j, pair, phase, t]
$rows = openpyxl_load($VEN . '/ev1_pairs.csv');
$data = [];
foreach ($rows as $r) {
    $name = trim(str_replace("'", '', (string) $r[0]));
    if (!preg_match('/^[A-Z]{4}$/', $name)) { continue; }
    $s1 = substr($name, 0, 2); $s2 = substr($name, 2, 2);
    $vals = [];
    for ($k = 1; $k <= 7; $k++) { $v = trim((string) $r[$k]); $vals[] = is_numeric($v) ? (float) $v : NAN; }
    for ($t = 0; $t < 6; $t++) {
        $xi = $vals[$t]; $xin = $vals[$t + 1] ?? NAN;
        if (!is_finite($xi) || !is_finite($xin) || $xi <= 1e-6) { continue; }
        $data[] = ['xi' => $xi, 'xj' => 1 - $xi, 'y' => ($xin - $xi) / $xi, 'si' => $s1, 'sj' => $s2, 'pair' => $name, 'phase' => '1:1', 'src' => 'EV1'];
    }
}
// EV2: обе фазы, ДВА ряда на пару (первый вид)... но в EV2 тоже только первый вид!
// По описанию EV2: относительное обилие первого вида. Фазы: 95:5 и 5:95.
$rows2 = openpyxl_load($VEN . '/ev2_pairs.csv');
$phaseIdx = [];
foreach ($rows2 as $r) {
    $line = trim(implode(',', $r));
    $name = trim(str_replace("'", '', (string) $r[0]));
    if (!preg_match('/^[A-Z]{4}$/', $name)) { continue; }
    $phaseIdx[$name] = ($phaseIdx[$name] ?? -1) + 1;
    $s1 = substr($name, 0, 2); $s2 = substr($name, 2, 2);
    $vals = [];
    for ($k = 1; $k <= 7; $k++) { $v = trim((string) $r[$k]); $vals[] = is_numeric($v) ? (float) $v : NAN; }
    for ($t = 0; $t < 6; $t++) {
        $xi = $vals[$t] ?? NAN; $xin = $vals[$t + 1] ?? NAN;
        if (!is_finite($xi) || !is_finite($xin) || $xi <= 1e-6) { continue; }
        $data[] = ['xi' => $xi, 'xj' => 1 - $xi, 'y' => ($xin - $xi) / $xi, 'si' => $s1, 'sj' => $s2, 'pair' => $name, 'phase' => $phaseIdx[$name] === 0 ? '95:5' : '5:95', 'src' => 'EV2'];
    }
}
echo "train строк: " . count($data) . "\n";

// --- Дизайн: a_ij ПАРО-СПЕЦИФИЧЕН (90 параметров: 12 r_i + 12 d_i + 66 a_ij).
// Модель пары: y = r_i + d_i*x_i + a_ij*x_j  (x_j = 1-x_i, a_ij принадлежит ПАРЕ).
$fitModel2 = function (bool $withSq) use ($data, $species): array {
    $colDefs = [];
    $idx = 0;
    foreach ($species as $i) { $colDefs['r_' . $i] = $idx++; }
    foreach ($species as $i) { $colDefs['d_' . $i] = $idx++; }
    $pairKeys = [];
    foreach ($data as $row) {
        $key = min($row['si'], $row['sj']) . max($row['si'], $row['sj']);
        if (!isset($pairKeys[$key])) { $pairKeys[$key] = 'a_' . $key; $colDefs['a_' . $key] = $idx++; }
    }
    if ($withSq) { foreach ($species as $i) { $colDefs['sq_' . $i] = $idx++; } }
    $k = $idx;
    $cols = array_fill(0, $k, []);
    $y = [];
    foreach ($data as $row) {
        $i = $row['si']; $j = $row['sj'];
        $key = min($i, $j) . max($i, $j);
        $xi = $row['xi']; $xj = $row['xj'];
        $v = array_fill(0, $k, 0.0);
        $v[$colDefs['r_' . $i]] = 1.0;
        $v[$colDefs['d_' . $i]] = $xi;
        $v[$colDefs['a_' . $key]] = $xj;
        if ($withSq) { $v[$colDefs['sq_' . $i]] = $xi * $xi; }
        foreach ($v as $p => $val) { $cols[$p][] = $val; }
        $y[] = $row['y'];
    }
    $w = lsqR($cols, $y);
    // lsqR: перехват ПЕРВЫЙ ($w[0]), затем коэффициенты колонок ($w[1..k])
    $w0 = $w[0];
    $wc = array_slice($w, 1);
    // train R2 (согласовано с applyLsq: перехват первый)
    $res = 0.0; $m = array_sum($y) / count($y); $tot = 0.0;
    $nRows = count($y);
    for ($i = 0; $i < $nRows; $i++) {
        $s = $w0;
        foreach ($cols as $p => $col) { $s += $wc[$p] * $col[$i]; }
        $res += ($y[$i] - $s) ** 2; $tot += ($y[$i] - $m) ** 2;
    }
    return ['colDefs' => $colDefs, 'w' => $wc, 'w0' => $w0, 'trainR2' => 1 - $res / $tot, 'k' => $k];
};

$m0 = $fitModel2(false);
$m1 = $fitModel2(true);
// средние a_ij по виду (для multi-species замыкания)
foreach ([$m0, $m1] as $key => $m) {
    $sumA = []; $cntA = [];
    foreach ($m['colDefs'] as $name => $p) {
        if (str_starts_with($name, 'a_')) {
            $pair = substr($name, 2);
            $i1 = substr($pair, 0, 2); $i2 = substr($pair, 2, 2);
            $v = $m['w'][$p] ?? 0.0;
            // a в строке вида, названного ПЕРВЫМ в алфавите? дизайн: a_key с y-строки
            // si — чей y. Привязка a_ij к si из данных — усредняем по обеим ролям.
            $sumA[$i1] = ($sumA[$i1] ?? 0.0) + $v; $cntA[$i1] = ($cntA[$i1] ?? 0) + 1;
            $sumA[$i2] = ($sumA[$i2] ?? 0.0) + $v; $cntA[$i2] = ($cntA[$i2] ?? 0) + 1;
        }
    }
    $avgA = [];
    foreach ($sumA as $i => $s) { $avgA[$i] = $s / max(1, $cntA[$i]); }
    $m['avgA'] = $avgA;
    if ($key === 0) { $m0['avgA'] = $avgA; } else { $m1['avgA'] = $avgA; }
}
echo 'M0 (пара-спец. a_ij): train R2=' . round($m0['trainR2'], 3) . "\n";
echo 'M1 (+x_i^2): train R2=' . round($m1['trainR2'], 3) . "\n";
echo 'delta train R2 (M1-M0) = ' . round($m1['trainR2'] - $m0['trainR2'], 3) . "\n";

// --- Мульти-степ предсказание на EV3 ---
// Модель: dx_i = x_i*(r_i + d_i*x_i + a_ij_i*x_j + [b_i*x_i^2]) — но в
// multi-species сумма j всех присутствующих! Наша форма фитилась на парах:
// d_i = a_ii - a_ij, a_ij_i — пара-специфичный эффект на вида i от соседа j.
// В полной композиции: r_i + d_i*x_i + sum_j (a_ij_i * x_j)? У нас a_ij_i один
// на пару — в композиции несколько соседей. Приближение: a_ij_i = средний
// эффект соседа; в композиции sum_j a_ij_i*x_j. Для простоты используем
// a_ij_i как общий коэффициент на всех соседей.
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
$ev3order = ['BH', 'CA', 'BU', 'PC', 'BO', 'BV', 'BT', 'EL', 'FP', 'CH', 'DP', 'ER'];
$posOf = array_flip($species);

$mkF = function (array $m, bool $withSq) use ($species, $n): callable {
    return function (array $x) use ($m, $withSq, $species, $n): array {
        $dx = [];
        foreach ($species as $idx => $i) {
            $xi = $x[$idx];
            $sOthers = 0.0;
            foreach ($species as $jdx => $j) { if ($jdx !== $idx) { $sOthers += $x[$jdx]; } }
            $s = ($m['w'][$m['colDefs']['r_' . $i]] ?? 0.0)
                + ($m['w'][$m['colDefs']['d_' . $i]] ?? 0.0) * $xi;
            if ($withSq) { $s += ($m['w'][$m['colDefs']['sq_' . $i]] ?? 0.0) * $xi * $xi; }
            // a_ij паро-специфичен; в композиции применяем СРЕДНИЙ a_ij вида i
            // по парам с его участием (продумать: композиционное правило!)
            $s += ($m['avgA_' . $i] ?? 0.0) * $sOthers;
            $dx[] = $xi * $s;
        }
        return $dx;
    };
};
$integrateF = function (callable $f, array $x0v): array {
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

// Для каждой модели: интеграция с КЛИПИНГОМ (x в [0, 5] — отрицательные доли
// обнулять, иначе взрыв)
$evalComm = function (array $comm, string $label, array $models) use ($integrateF, $species, $ev3order): void {
    $x0 = [];
    foreach ($ev3order as $c => $sp) { $x0[$sp] = $comm[0][$c]; }
    $x0v = [];
    foreach ($species as $i) { $x0v[] = $x0[$i]; }
    foreach ($models as $name => [$f, $withClip]) {
        $xc = $x0v;
        $traj = [$xc];
        for ($day = 1; $day <= 6; $day++) {
            for ($h = 0; $h < 48; $h++) {
                $xc = $integrateF($f, $xc)[1]; // 1 шаг RK4 dt=0.5? нет — нужен пошагово
            }
        }
    }
};

// проще: локальный клиппинг внутри rk4-обёртки
$runModel = function (callable $f, array $x0v, bool $clip): array {
    $rk4c = function (callable $f, array $x, float $dt) use ($clip): array {
        $k1 = $f($x);
        $x2 = array_map(fn ($v, $d) => $v + $d * $dt / 2, $x, $k1);
        if ($clip) { $x2 = array_map(fn ($v) => max(0.0, min(5.0, $v)), $x2); }
        $k2 = $f($x2);
        $x3 = array_map(fn ($v, $d) => $v + $d * $dt / 2, $x, $k2);
        if ($clip) { $x3 = array_map(fn ($v) => max(0.0, min(5.0, $v)), $x3); }
        $k3 = $f($x3);
        $x4 = array_map(fn ($v, $d) => $v + $d * $dt, $x, $k3);
        if ($clip) { $x4 = array_map(fn ($v) => max(0.0, min(5.0, $v)), $x4); }
        $k4 = $f($x4);
        $out = array_map(fn ($v, $a1, $a2, $a3, $a4) => $v + $dt / 6 * ($a1 + 2 * $a2 + 2 * $a3 + $a4), $x, $k1, $k2, $k3, $k4);
        if ($clip) { $out = array_map(fn ($v) => max(0.0, min(5.0, $v)), $out); }
        return $out;
    };
    $xc = $x0v;
    $traj = [$x0v];
    for ($day = 1; $day <= 6; $day++) {
        for ($h = 0; $h < 48; $h++) { $xc = $rk4c($f, $xc, 0.5); }
        $traj[] = $xc;
    }
    return $traj;
};

$reportComm = function (array $mat, string $label, array $traj, string $name) use ($ev3order, $species): array {
    $res = 0.0; $tot = 0.0;
    foreach ($ev3order as $c => $sp) {
        $m = array_sum(array_column($mat, $c)) / 7;
        $pos = array_search($sp, $species, true);
        for ($t = 0; $t < 7; $t++) {
            $sum = array_sum($traj[$t]);
            $pred = $sum > 0 ? $traj[$t][$pos] / $sum : 0.0;
            $o = $mat[$t][$c];
            $res += ($o - $pred) ** 2; $tot += ($o - $m) ** 2;
        }
    }
    return ['label' => $label, 'name' => $name, 'res' => $res, 'tot' => $tot, 'r2' => $tot > 1e-12 ? 1 - $res / $tot : NAN];
};

$models = [
    'theirT3' => [$fTheir, false],
    'M0' => [$mkF($m0, false), true],
    'M1' => [$mkF($m1, true), true],
];

$agg = [];
foreach ($communities as $label => $mat) {
    $x0 = [];
    foreach ($ev3order as $c => $sp) { $x0[$sp] = $mat[0][$c]; }
    $x0v = [];
    foreach ($species as $i) { $x0v[] = $x0[$i]; }
    foreach ($models as $name => [$f, $clip]) {
        $traj = $runModel($f, $x0v, $clip);
        $r = $reportComm($mat, $label, $traj, $name);
        $agg[$name][] = $r;
    }
}
echo "\n=== multi-step EV3 (13 композиций, R2 агрегат) ===\n";
foreach ($agg as $name => $rs) {
    $res = array_sum(array_column($rs, 'res'));
    $tot = array_sum(array_column($rs, 'tot'));
    $r2agg = $tot > 1e-12 ? 1 - $res / $tot : NAN;
    // медиана по композициям
    $vals = array_column($rs, 'r2');
    sort($vals);
    $med = $vals[(int) (count($vals) / 2)];
    printf("%-10s агрегат R2=%+.3f | медиана по композициям=%+.3f |赢 композиций=%d/13\n",
        $name, $r2agg, $med, 0);
}
// по композициям: кто выигрывает
echo "\nper-композиция R2 (their / M0 / M1):\n";
foreach ($communities as $label => $mat) {
    $line = "  $label: ";
    foreach ($agg as $name => $rs) {
        foreach ($rs as $r) {
            if ($r['label'] === $label) { $line .= sprintf('%s=%+.3f ', $name, $r['r2']); }
        }
    }
    echo $line . "\n";
}
