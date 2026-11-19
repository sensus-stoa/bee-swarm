<?php
declare(strict_types=1);

/**
 * B6.3: ГИПОТЕЗА КИСЛОРОДА. Пять «значимых» метаболитов (B6.2c) могут
 * быть ПРОКСИ кислородного фактора, а не настоящими метаболическими
 * ролями: в статических 96-well культурах O2 диффундирует сверху,
 * его доступность зависит от СУММАРНОГО потребления сообществом
 * (дыхание). Пул любого сильно-загруженного метаболита коррелирует
 * с "плотностью/структурой" сообщества → коллинеарен с кислородом.
 *
 * Тест 1 (дискриминация): регрессия residual ~ (а) пул метаболита,
 * (б) ОБЩАЯ биомасса sum_j x_j, (в) оба. Если (б) объясняет то же,
 * что (а) — метаболит был прокси плотности. Если (а) добавляет сверх
 * (б) — метаболическая роль живёт.
 *
 * Тест 2 (структура потребления): у видов EV4 есть отрицательные
 * log2fold (потребление). Дыхательные виды (аэробы) должны показывать
 * потребление O2-связанных метаболитов. Сортировка 12 видов по
 * аэротолерантности (литературные данные: B. thetaotaomicron —
 * облигатный анаэроб; E. rectale, F. prausnitzii — анаэробы;
 * B. vulgatus — анаэроб; C. aerofaciens — анаэроб; E. lenta —
 * аэротолерант; D. pneumosintes — анаэроб; A. soehngenii — анаэроб;
 * B. producta — аэротолерант; R. gnavus — анаэроб; C. symbiosum —
 * анаэроб; B. hansenii — аэротолерант). Если пятёрка значимых форм
 * сосредоточена на видах с ОДИНАКОВЫМ O2-профилем, а не с метаболической
 * ролью — прокси.
 *
 * Тест 3 (прямой): добавить в пер-видовую регрессию hidden-фактор
 * «k*sum_j x_j» и посмотреть, сколько остаётся от метаболических
 * коэффициентов (частная F-статистика).
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);

$VEN = __DIR__ . '/../Benchmarks/venturelli';
$ev3order = ['BH', 'CA', 'BU', 'PC', 'BO', 'BV', 'BT', 'EL', 'FP', 'CH', 'DP', 'ER'];

// аэротолерантность (0=облигатный анаэроб, 1=аэротолерант/факультатив)
// источники: VE202-12 описание + NCBI. Пометки осторожные — это приближение.
$AERO = ['BH' => 1, 'CA' => 0, 'BU' => 0, 'PC' => 0, 'BO' => 0, 'BV' => 0,
         'BT' => 0, 'EL' => 1, 'FP' => 0, 'CH' => 0, 'DP' => 0, 'ER' => 1];

$xml = file_get_contents('/tmp/ven_s006/twelveSpeciesT3.xml');
$params = [];
preg_match_all('/<parameter [^>]*id="([^"]+)"[^>]*value="([^"]+)"/', $xml, $pm, PREG_SET_ORDER);
foreach ($pm as $p) { $params[$p[1]] = (float) $p[2]; }

$rows = openpyxl_load($VEN . '/ev4_metabolites.csv');
$header = array_map(fn ($v) => trim(str_replace(["'", "\r"], '', $v)), $rows[0]);
$speciesEV4 = array_slice($header, 1);
$metab = [];
foreach (array_slice($rows, 1) as $r) {
    $name = trim(str_replace(["'", "\r"], '', $r[0]));
    if ($name === '') { continue; }
    foreach ($speciesEV4 as $c => $sp) {
        $v = trim((string) ($r[$c + 1] ?? ''));
        if (is_numeric($v)) { $metab[$name][$sp] = (float) $v; }
    }
}

$raw = file($VEN . '/ev3_communities.csv');
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

$buildRows = function (string $sp, array $mNames) use ($communities, $ev3order, $metab, $params): array {
    $out = [];
    foreach ($communities as $label => $mat) {
        for ($t = 0; $t < 6; $t++) {
            $xRow = [];
            foreach ($ev3order as $c => $sp2) { $xRow[$sp2] = $mat[$t][$c]; }
            $xi = $xRow[$sp]; $xin = $mat[$t + 1][array_search($sp, $ev3order, true)] ?? null;
            if ($xin === null || $xi < 0.01) { continue; }
            $s = $params['u' . $sp];
            foreach ($ev3order as $j) { $s += $params['a' . $sp . $j] * $xRow[$j]; }
            $pools = [];
            foreach ($mNames as $mn) {
                $pool = 0.0;
                foreach ($xRow as $j => $xj) { if ($j !== $sp) { $pool += ($metab[$mn][$j] ?? 0.0) * $xj; } }
                $pools[$mn] = $pool;
            }
            // общая биомасса остальных (прокси дыхательной нагрузки на O2)
            $sumOthers = 0.0;
            foreach ($xRow as $j => $xj) { if ($j !== $sp) { $sumOthers += $xj; } }
            $out[] = ['comm' => $label, 't' => $t, 'xi' => $xi, 'pools' => $pools,
                      'sumOthers' => $sumOthers, 'y' => ($xin - $xi) / $xi - $s];
        }
    }
    return $out;
};

// LSQ: y ~ a + b1*pool + [b2*sumOthers]; partial F для b1 при (б)
$lsq = function (array $rows, bool $withSum) use ($buildRows): array {
    $n = count($rows);
    $y = array_column($rows, 'y');
    $my = array_sum($y) / $n;
    // дизайн-матрица
    $k = $withSum ? 3 : 2; // [1, pool, sum?]
    $X = [];
    foreach ($rows as $r) {
        $row = [1.0, $r['pools'][array_key_first($r['pools'])]];
        if ($withSum) { $row[] = $r['sumOthers']; }
        $X[] = $row;
    }
    // нормальные уравнения (k мал)
    $A = array_fill(0, $k, array_fill(0, $k, 0.0));
    $b = array_fill(0, $k, 0.0);
    for ($i = 0; $i < $n; $i++) {
        for ($p = 0; $p < $k; $p++) {
            $b[$p] += $X[$i][$p] * $y[$i];
            for ($q = 0; $q < $k; $q++) { $A[$p][$q] += $X[$i][$p] * $X[$i][$q]; }
        }
    }
    // Гаусс
    for ($p = 0; $p < $k; $p++) {
        $mx = $p;
        for ($q = $p + 1; $q < $k; $q++) { if (abs($A[$q][$p]) > abs($A[$mx][$p])) { $mx = $q; } }
        [$A[$p], $A[$mx]] = [$A[$mx], $A[$p]];
        [$b[$p], $b[$mx]] = [$b[$mx], $b[$p]];
        if (abs($A[$p][$p]) < 1e-12) { return ['b1' => NAN, 'r2' => NAN]; }
        for ($q = $p + 1; $q < $k; $q++) {
            $f = $A[$q][$p] / $A[$p][$p];
            for ($r2 = $p; $r2 < $k; $r2++) { $A[$q][$r2] -= $f * $A[$p][$r2]; }
            $b[$q] -= $f * $b[$p];
        }
    }
    $w = array_fill(0, $k, 0.0);
    for ($p = $k - 1; $p >= 0; $p--) {
        $s = $b[$p];
        for ($q = $p + 1; $q < $k; $q++) { $s -= $A[$p][$q] * $w[$q]; }
        $w[$p] = $s / $A[$p][$p];
    }
    // R2
    $res = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $pred = 0.0;
        for ($p = 0; $p < $k; $p++) { $pred += $w[$p] * $X[$i][$p]; }
        $res += ($y[$i] - $pred) ** 2;
    }
    $tot = 0.0;
    foreach ($y as $v) { $tot += ($v - $my) ** 2; }
    return ['b1' => $w[1], 'b2' => $withSum ? $w[2] : null, 'r2' => 1 - $res / $tot, 'sse' => $res];
};

$TOP5 = [
    'CA' => 'Uric_acid_negative_12p53',
    'BO' => 'gamma-Glutamylisoleucine_positive_13p22',
    'BT' => 'DL-pipecolic_acid_positive_23p80',
    'FP' => 'thymidine_negative_3p33',
    'ER' => 'L-lysine_positive_23p71',
];

echo "=== ТЕСТ КИСЛОРОДА: пул метаболита vs общая биомасса (sum_j x_j) ===\n";
echo "(partial: b1 при контроле sumOthers; если b1 -> 0 — метаболит был прокси плотности)\n\n";
printf("%-3s %-30.30s %-22s %-22s\n", 'sp', 'метаболит', 'b1(пул) alone/b оба', 'dR2 от sumOthers');
foreach ($TOP5 as $sp => $mn) {
    $rows = $buildRows($sp, [$mn]);
    $fitA = $lsq($rows, false); // только пул
    $fitB = $lsq($rows, true);  // пул + sumOthers
    // R2 sumOthers-only
    $y = array_column($rows, 'y'); $s = array_column($rows, 'sumOthers');
    $n = count($y);
    $ms = array_sum($s) / $n; $my = array_sum($y) / $n;
    $sxy = 0.0; $sxx = 0.0; $syy = 0.0;
    for ($i = 0; $i < $n; $i++) { $sxy += ($s[$i] - $ms) * ($y[$i] - $my); $sxx += ($s[$i] - $ms) ** 2; $syy += ($y[$i] - $my) ** 2; }
    $r2Sum = ($sxx > 1e-12 && $syy > 1e-12) ? ($sxy / sqrt($sxx * $syy)) ** 2 : 0.0;
    printf("%-3s %-30.30s b1: %.3f -> %.3f | r2: pool %.3f + sum %.3f -> %.3f  аэро=%d\n",
        $sp, $mn, $fitA['b1'], $fitB['b1'] ?? NAN, $fitA['r2'], $r2Sum, $fitB['r2'], $AERO[$sp]);
}

// Тест 2: распределение пятёрки по аэротолерантности
echo "\n=== аэротолерантность пятёрки: ", implode(',', array_map(fn ($sp) => $AERO[$sp], array_keys($TOP5))), " ===\n";
echo "(если все 0 или все 1 — подозрение на O2-конфаундер; если смешанно — скорее метаболическая роль)\n";

// Тест 3: как много residual объясняет sumOthers ГЛОБАЛЬНО (все виды сразу)
echo "\n=== глобально: corr(sum_j x_j, residual) per вид ===\n";
foreach ($TOP5 as $sp => $mn) {
    $rows = $buildRows($sp, [$mn]);
    $y = array_column($rows, 'y'); $s = array_column($rows, 'sumOthers');
    $n = count($y);
    $ms = array_sum($s) / $n; $my = array_sum($y) / $n;
    $sxy = 0.0; $sxx = 0.0; $syy = 0.0;
    for ($i = 0; $i < $n; $i++) { $sxy += ($s[$i] - $ms) * ($y[$i] - $my); $sxx += ($s[$i] - $ms) ** 2; $syy += ($y[$i] - $my) ** 2; }
    $r = ($sxx > 1e-12 && $syy > 1e-12) ? $sxy / sqrt($sxx * $syy) : 0.0;
    printf("  %s: corr(sum, residual)=%+.3f\n", $sp, $r);
}
