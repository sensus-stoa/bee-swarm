<?php
declare(strict_types=1);

/**
 * B7-External, этап 2: пуриновая ось vs остальные классы на ΔABC Chodkowski.
 *
 * Вопрос: предсказывает ли класс фичи (purine/pyrimidine/other) величину
 * и знак композиционного остатка Δ = Full - nullAdditive?
 *
 * Тест (соответствует уровню 1 «Обнаружение» аналитика):
 *   H1: |Δ| purine-фич систематически отличается от |Δ| other-фич
 *       (Mann-Whitney U на |Δ|, и на signed Δ отдельно).
 *   Null: circular-shift Δ по времени внутри фичи (ломает временную
 *       структуру, сохраняет распределение) + перестановка меток классов.
 *
 * Плюс: корреляция Δ с временем (растёт ли остаток к концу культивирования)
 * отдельно по классам — пуриновый остаток должен накапливаться, если ось живая.
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);

if (!function_exists('median')) {
    function median(array $a): float {
        $a = array_values($a);
        if (!$a) { return NAN; }
        sort($a);
        $n = count($a);
        return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2;
    }
}
if (!function_exists('norm_cdf')) {
    function norm_cdf(float $x): float {
        // Abramowitz & Stegun 7.1.26
        $t = 1 / (1 + 0.2316419 * abs($x));
        $d = 0.3989422804014327; // 1/sqrt(2pi)
        $poly = $t * (0.319381530 + $t * (-0.356563782 + $t * (1.781477937 + $t * (-1.821255978 + $t * 1.330274429))));
        $p = $d * exp(-$x * $x / 2) * $poly;
        return $x >= 0 ? 1 - $p : $p;
    }
}

$rows = json_decode(file_get_contents(__DIR__ . '/../Benchmarks/chodkowski/challenge_table.json'), true);

// сгруппировать: (mode, feat) -> [t => delta], класс по первому
$byFeat = [];
foreach ($rows as $r) {
    $key = $r['mode'] . '|' . $r['feat'];
    $byFeat[$key]['class'] = $r['class'];
    $byFeat[$key]['ident'] = $r['ident'];
    $byFeat[$key]['delta'][$r['t']] = $r['delta'];
    $byFeat[$key]['full'][$r['t']] = $r['full'];
    $byFeat[$key]['null'][$r['t']] = $r['nullAdd'];
}
echo "фич всего: ", count($byFeat), "\n";

// фильтр: фича должна иметь все 6 времен
$byFeat = array_filter($byFeat, fn ($f) => count($f['delta']) === 6);
echo "фич с полным временным рядом: ", count($byFeat), "\n";

$rankSum = function (array $a, array $b): array {
    // Mann-Whitney U с нормальной аппроксимацией (n большие)
    $all = array_merge($a, $b);
    sort($all);
    $rank = [];
    $n = count($all);
    $i = 0;
    $ranks = [];
    while ($i < $n) {
        $j = $i;
        while ($j + 1 < $n && $all[$j + 1] === $all[$i]) { $j++; }
        $avgRank = ($i + $j) / 2 + 1;
        for ($k = $i; $k <= $j; $k++) { $ranks[$k] = $avgRank; }
        $i = $j + 1;
    }
    $values = array_combine($all, $ranks);
    $ra = 0;
    foreach ($a as $v) { $ra += $values[$v]; }
    $na = count($a); $nb = count($b);
    $U = $ra - $na * ($na + 1) / 2;
    $mu = $na * $nb / 2;
    $sigma = sqrt($na * $nb * ($n + 1) / 12);
    $z = $sigma > 0 ? ($U - $mu) / $sigma : 0;
    $p = 2 * (1 - norm_cdf(abs($z)));
    return ['U' => $U, 'z' => $z, 'p' => $p];
};

// собрать |Δ| и signed Δ по классам
$abs = ['purine' => [], 'pyrimidine' => [], 'other' => []];
$sgn = ['purine' => [], 'pyrimidine' => [], 'other' => []];
$dTrend = ['purine' => [], 'pyrimidine' => [], 'other' => []];
$T = ['12pt5' => 12.5, '25' => 25, '30' => 30, '35' => 35, '40' => 40, '45' => 45];
foreach ($byFeat as $f) {
    $cl = $f['class'];
    $ds = array_values($f['delta']);
    foreach ($ds as $d) {
        $abs[$cl][] = abs($d);
        $sgn[$cl][] = $d;
    }
    // тренд: корреляция Δ с t
    $ts = []; $dv = [];
    foreach ($f['delta'] as $t => $d) { $ts[] = $T[$t]; $dv[] = $d; }
    $n = count($ts);
    $mt = array_sum($ts) / $n; $md = array_sum($dv) / $n;
    $sxy = 0; $sxx = 0; $syy = 0;
    for ($i = 0; $i < $n; $i++) {
        $sxy += ($ts[$i] - $mt) * ($dv[$i] - $md);
        $sxx += ($ts[$i] - $mt) ** 2; $syy += ($dv[$i] - $md) ** 2;
    }
    if ($sxx > 1e-12 && $syy > 1e-12) {
        $dTrend[$cl][] = $sxy / sqrt($sxx * $syy);
    }
}

echo "\n=== |Δ| по классам (Mann-Whitney vs other) ===\n";
foreach (['purine', 'pyrimidine'] as $cl) {
    $r = $rankSum($abs[$cl], $abs['other']);
    printf("  %-11.11s n=%5d  медиана|Δ|=%.0f  vs other: z=%+.2f p=%.2e\n", $cl, count($abs[$cl]),
        median($abs[$cl]), $r['z'], $r['p']);
}
printf("  other       n=%5d  медиана|Δ|=%.0f\n", count($abs['other']), median($abs['other']));

echo "\n=== signed Δ (полный - аддитивный) ===\n";
foreach (['purine', 'pyrimidine', 'other'] as $cl) {
    $med = median($sgn[$cl]);
    $pos = count(array_filter($sgn[$cl], fn ($x) => $x > 0));
    printf("  %-11.11s медиана=%+.0f  доля Δ>0: %.2f\n", $cl, $med, $pos / count($sgn[$cl]));
}

echo "\n=== тренд Δ по времени (corr Δ vs t, медиана по фичам) ===\n";
foreach (['purine', 'pyrimidine', 'other'] as $cl) {
    printf("  %-11.11s медиана r = %+.3f  (n=%d фич)\n", $cl, median($dTrend[$cl]), count($dTrend[$cl]));
}

// permutation-тест: перемешиваем метки классов (purine vs other), 2000 раз
mt_srand(2024);
$labels = [];
foreach ($byFeat as $key => $f) { $labels[$key] = $f['class']; }
$realDiff = median($abs['purine']) - median($abs['other']);
$keys = array_keys($byFeat);
$count = 0; $N = 2000;
$absAll = [];
foreach ($byFeat as $key => $f) {
    $absAll[$key] = array_map('abs', array_values($f['delta']));
}
for ($p = 0; $p < $N; $p++) {
    $shuffled = $labels;
    $vals = array_values($shuffled);
    shuffle($vals);
    $shuffled = array_combine($keys, $vals);
    $pa = []; $pb = [];
    foreach ($keys as $k) {
        if ($shuffled[$k] === 'purine') { foreach ($absAll[$k] as $v) { $pa[] = $v; } }
        else { foreach ($absAll[$k] as $v) { $pb[] = $v; } }
    }
    if (median($pa) - median($pb) >= $realDiff) { $count++; }
}
echo "\n=== permutation (метки классов, 2000x) ===\n";
echo "реальная разница медиан purine-other = ", $realDiff, "\n";
echo "p_perm = ", $count / $N, "\n";

// отдельно по каждому пуриновому идентификатору
echo "\n=== Δ по каждому пуриновому соединению (медиана по временам) ===\n";
$byIdent = [];
foreach ($byFeat as $f) {
    if ($f['class'] !== 'purine' && $f['class'] !== 'pyrimidine') { continue; }
    $byIdent[$f['ident']][] = median(array_values($f['delta']));
}
foreach ($byIdent as $ident => $ds) {
    printf("  %-30.30s медиана Δ=%+.0f (n=%d фич)\n", $ident, median($ds), count($ds));
}
