<?php

declare(strict_types=1);

/**
 * B4.2-RCD шаг 1: Residual Observer на батарейном unified-датасете.
 *
 * Цепочка (спека RCD, абдукция):
 *  1. Лучшая частичная форма (перебор конвейера, depth-2 + potential-дети)
 *     -> joint LSQ (смещение+коэф.) на СЭМПЛЕ.
 *  2. Остаток r = y - pred: ACF/знаковая структура/дисперсия.
 *  3. CCF остатка с каждым входом (и с кандидатом sqrt(t)! — предсказание
 *     контракта: диффузионный SEI-терм) -> z-score против перестановок.
 *  4. Вердикт: STRUCTURED (дефект GRAMMAR/DEPTH — контракт) vs
 *     FORMLESS (дефект DATA — режимные фичи, грамматику не трогать).
 *
 * Запуск: php scripts/exp068_rcd_residual.php [battery|dot] [--steps 2000]
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

function applyOp(array $va, array $vb, string $op, int $m): array
{
    $v = [];
    for ($i = 0; $i < $m; $i++) {
        $p = $va[$i] ?? 0.0; $q = $vb[$i] ?? 0.0;
        $v[] = $op === '+' ? $p + $q : ($op === '×' ? $p * $q : ($op === '−' ? $p - $q
            : ($op === '/' ? (abs($q) > 1e-12 ? $p / $q : 0.0) : ($op === 'min' ? min($p, $q) : max($p, $q)))));
    }
    return $v;
}

function corrf(array $a, array $b): float
{
    $m = min(count($a), count($b), 30);
    $a = array_slice($a, 0, $m); $b = array_slice($b, 0, $m);
    $ma = array_sum($a) / $m; $mb = array_sum($b) / $m;
    $num = 0.0; $da = 0.0; $db = 0.0;
    for ($i = 0; $i < $m; $i++) { $num += ($a[$i] - $ma) * ($b[$i] - $mb); $da += ($a[$i] - $ma) ** 2; $db += ($b[$i] - $mb) ** 2; }
    return ($da > 1e-12 && $db > 1e-12) ? $num / sqrt($da * $db) : 0.0;
}

function cvf(array $a, array $b): float
{
    $m = min(count($a), count($b));
    $ma = array_sum($a) / $m; $mb = array_sum($b) / $m;
    $num = 0.0; $da = 0.0; $db = 0.0;
    for ($i = 0; $i < $m; $i++) { $num += ($a[$i] - $ma) * ($b[$i] - $mb); $da += ($a[$i] - $ma) ** 2; $db += ($b[$i] - $mb) ** 2; }
    return 1.0 - $num / sqrt($da * $db + 1e-30);
}

$fixture = $argv[1] ?? 'battery';
if ($fixture === 'dot') {
    mt_srand(42);
    $labels = ['x0', 'x1', 'x2', 'x3', 'x4'];
    $X = []; $y = [];
    for ($i = 0; $i < 200; $i++) {
        $row = [];
        for ($j = 0; $j < 5; $j++) { $row[] = mt_rand() / mt_getrandmax() * 4.0 - 2.0; }
        $X[] = $row; $y[] = $row[0] * $row[3] + $row[1] * $row[4] + $row[2] * $row[4];
    }
} else {
    $lines = file(__DIR__ . '/../battery_b2/unified_task.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $header = str_getcsv(trim((string) array_shift($lines)));
    $labels = array_slice($header, 0, -1);
    $X = []; $y = [];
    $stp = 5; $i = 0;
    foreach ($lines as $l) {
        if ($i++ % $stp) { continue; }
        $v = str_getcsv(trim($l));
        if (count($v) < count($header)) { continue; }
        $r = [];
        for ($j = 0; $j < count($header) - 1; $j++) { $r[] = (float) $v[$j]; }
        $X[] = $r; $y[] = (float) $v[count($header) - 1];
    }
}
$n = count($y);
$k = count($labels);
echo "fixture=$fixture n=$n k=$k\n";

// ---------- 1. частичная форма: телескоп (чистые формы из сырых) ----------
putenv('TRAIL_MARKS=0');
// L1
$l1 = [];
foreach ($labels as $j => $lab) { $l1[$lab] = array_column($X, $j); }
$keys = array_keys($l1);
for ($a = 0; $a < $k; $a++) {
    for ($b = $a + 1; $b < $k; $b++) {
        foreach (['+', '×', '−', '/'] as $op) {
            $l1 = array_merge($l1, ["({$keys[$a]}$op{$keys[$b]})" => applyOp($l1[$keys[$a]], $l1[$keys[$b]], $op, $n)]);
        }
    }
}
foreach ($keys as $nm) { $l1["sq($nm)"] = applyOp($l1[$nm], $l1[$nm], '×', $n); }
$l1cv = [];
foreach ($l1 as $nm => $v) { $l1cv[$nm] = cvf(array_slice($v, 0, min($n, 30)), array_slice($y, 0, min($n, 30))); }
asort($l1cv);
$pool = array_values(array_unique(array_merge(array_slice(array_keys($l1cv), 0, 40), $keys,
    array_map(fn ($nm) => "sq($nm)", $keys))));
$extra = [];
foreach ($l1cv as $nm => $c) {
    if (abs(corrf($l1[$nm], array_slice($y, 0, min($n, 30)))) >= 0.5) { $extra[] = $nm; }
}
$pool = array_values(array_unique(array_merge($pool, $extra)));
// born2: лучшая по cv30 форма
$best = ['cv' => 9.99, 'name' => '', 'vec' => null];
$pa = count($pool);
for ($a = 0; $a < $pa; $a++) {
    for ($b = $a + 1; $b < $pa; $b++) {
        foreach (['+', '×', '−', '/', 'min', 'max'] as $op) {
            $v = applyOp($l1[$pool[$a]], $l1[$pool[$b]], $op, $n);
            $c = cvf(array_slice($v, 0, min($n, 30)), array_slice($y, 0, min($n, 30)));
            if ($c < $best['cv']) { $best = ['cv' => $c, 'name' => "({$pool[$a]}$op{$pool[$b]})", 'vec' => $v]; }
        }
    }
}
$partial = $best['name'];
echo sprintf("partial: %s (cv30=%.4f)\n", $partial, $best['cv']);
$formX = $best['vec'];
// affine fit: pred = a*vals + b
$ols = function (array $v, array $t): array {
    $m = count($v);
    $mv = array_sum($v) / $m; $mt = array_sum($t) / $m;
    $num = 0.0; $den = 0.0;
    for ($i = 0; $i < $m; $i++) { $num += ($v[$i] - $mv) * ($t[$i] - $mt); $den += ($v[$i] - $mv) ** 2; }
    $a = $den > 1e-12 ? $num / $den : 0.0;
    return [$a, $mt - $a * $mv];
};
[$a, $b] = $ols($formX, $y);
$pred = array_map(fn ($v) => $a * $v + $b, $formX);
$res = [];
for ($i = 0; $i < $n; $i++) { $res[] = $y[$i] - $pred[$i]; }
$varY = 0.0; $varR = 0.0;
$my = array_sum($y) / $n; $mr = array_sum($res) / $n;
for ($i = 0; $i < $n; $i++) { $varY += ($y[$i] - $my) ** 2; $varR += ($res[$i] - $mr) ** 2; }
echo sprintf("partial cv(R2-остаток): %.4f (var ratio %.4f)\n", $varR / $varY, $varR / $varY);

// ---------- 2-3. структура остатка ----------
// ACF (лаг 1..3 по отсортированному t)
$idx = array_keys($y);
array_multisort(array_column($X, 0), $idx);
$rSorted = array_map(fn ($i) => $res[$i], $idx);
$mean = array_sum($rSorted) / $n;
$acf = [];
for ($lag = 1; $lag <= 3; $lag++) {
    $num = 0.0; $den = 0.0;
    for ($i = 0; $i < $n - $lag; $i++) { $num += ($rSorted[$i] - $mean) * ($rSorted[$i + $lag] - $mean); }
    for ($i = 0; $i < $n; $i++) { $den += ($rSorted[$i] - $mean) ** 2; }
    $acf[$lag] = $den > 1e-12 ? $num / $den : 0.0;
}

// CCF остатка с каждым входом + sqrt(t_norm) + знак-структура по химиям
$ccf = function (array $u, array $v): float {
    $m = count($u);
    $mu = array_sum($u) / $m; $mv2 = array_sum($v) / $m;
    $num = 0.0; $du = 0.0; $dv = 0.0;
    for ($i = 0; $i < $m; $i++) {
        $num += ($u[$i] - $mu) * ($v[$i] - $mv2);
        $du += ($u[$i] - $mu) ** 2; $dv += ($v[$i] - $mv2) ** 2;
    }
    return ($du > 1e-12 && $dv > 1e-12) ? $num / sqrt($du * $dv) : 0.0;
};
// перестановочная нулевая гипотеза (Фишер-Йетс, 200 перестановок)
$permZ = function (array $u, array $v) use ($ccf): float {
    $obs = $ccf($u, $v);
    $m = count($u);
    $sims = [];
    for ($p = 0; $p < 200; $p++) {
        $w = $v;
        for ($i = $m - 1; $i > 0; $i--) { $j = mt_rand(0, $i); [$w[$i], $w[$j]] = [$w[$j], $w[$i]]; }
        $sims[] = $ccf($u, $w);
    }
    $mu = array_sum($sims) / count($sims);
    $sd = 0.0;
    foreach ($sims as $s) { $sd += ($s - $mu) ** 2; }
    $sd = sqrt($sd / count($sims) + 1e-30);
    return ($obs - $mu) / $sd;
};
echo "\nCCF остатка (z против перестановок):\n";
$targets = [];
foreach ($labels as $j => $lab) {
    $col = array_column($X, $j);
    if (array_sum($col) < 1e-9) { continue; }
    $targets[$lab] = $col;
}
$sq = array_map(fn ($r) => $r[0] * $r[0], $X);
$targets['sq(t_norm)'] = $sq;
$zScores = [];
foreach ($targets as $lab => $col) {
    $z = $permZ($res, $col);
    $zScores[$lab] = $z;
    printf("  %-12s ccf=%+.3f z=%+.1f\n", $lab, $ccf($res, $col), $z);
}

// знак-структура: средний остаток по химиям
$chems = ['is_nmc', 'is_lfp', 'is_lco'];
$zmean = [];
foreach ($chems as $c) {
    $j = array_search($c, $labels, true);
    if ($j === false) { continue; }
    $vals = [];
    for ($i = 0; $i < $n; $i++) { if ($X[$i][$j] > 0.5) { $vals[] = $res[$i]; } }
    if ($vals) { $zmean[$c] = array_sum($vals) / count($vals); }
}

// ---------- 4. вердикт ----------
$acfMax = max($acf);
$zMax = max(array_map('abs', $zScores));
echo "\nACF: " . implode(', ', array_map(fn ($l, $v) => "lag$l=" . round($v, 3), array_keys($acf), $acf)) . "\n";
echo 'средний остаток по химиям: ' . json_encode(array_map(fn ($v) => round($v, 4), $zmean)) . "\n";
$varRatio = $varR / $varY;
if ($zMax >= 4.0 || $acfMax >= 0.3) {
    $top = array_keys($zScores, max($zScores))[0] ?? '?';
    echo "ВЕРДИКТ: STRUCTURED — остаток структурирован (z_max=" . round($zMax, 1)
        . ", acf=" . round($acfMax, 2) . ", var_ratio=" . round($varRatio, 3)
        . "). Дефект GRAMMAR/DEPTH. Контракт: недостающий терм коррелирует с '$top'.\n";
} elseif ($varRatio < 0.05) {
    echo "ВЕРДИКТ: EXPLAINED — частичная форма покрывает данные (var_ratio=" . round($varRatio, 3) . ").\n";
} else {
    echo "ВЕРДИКТ: FORMLESS — остаток бесструктурен (z_max=" . round($zMax, 1)
        . ", acf=" . round($acfMax, 2) . ", var_ratio=" . round($varRatio, 3)
        . "). Дефект ДАННЫХ: режимных фич нет, грамматику не расширять.\n";
}
