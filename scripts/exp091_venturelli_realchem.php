<?php
declare(strict_types=1);

/**
 * B6.5-F: Редокс-градиент на РЕАЛЬНЫХ формулах (PubChem 97/97).
 *
 * Дескрипторы из формулы CxHyNzOwSp:
 *   - degree_of_reduction (DOR): количество окислительных эквивалентов
 *     на атом C: DOR = (4C + H - 2O - 3N + 5P + 6S) / C
 *     (стандартная термодинамическая мера восстановленности органики;
 *     чем выше DOR — тем более восстановленный субстрат = донор e-)
 *   - N/C, S/C, P/C — атомные отношения
 *   - MW — молекулярная масса
 *
 * Гипотеза пользователя (переработанная в строгую форму): прирост R2
 * при углублении терма коррелирует с восстановленностью (DOR)
 * добавляемого метаболита — сложная композиция потребляет более
 * восстановленные субстраты через редокс-каскады. Плюс N/C (азотистость)
 * и S/C (сера — Cys/Met обмен, дисульфидные редокс).
 *
 * Тест: (1) corr(dR2, DOR) на acetate-свободных видах с перестановочным
 * p; (2) там же corr(dR2, N/C); (3) если p<0.05 — гипотеза подтверждена
 * на настоящей химии.
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);

$VEN = __DIR__ . '/../Benchmarks/venturelli';
$ev3orderAll = ['BH', 'CA', 'BU', 'PC', 'BO', 'BV', 'BT', 'EL', 'FP', 'CH', 'DP', 'ER'];
$excluded = ['ER', 'FP'];
$ev3order = array_values(array_diff($ev3orderAll, $excluded));

$xml = file_get_contents('/tmp/ven_s006/twelveSpeciesT3.xml');
$params = [];
preg_match_all('/<parameter [^>]*id="([^"]+)"[^>]*value="([^"]+)"/', $xml, $pm, PREG_SET_ORDER);
foreach ($pm as $p) { $params[$p[1]] = (float) $p[2]; }

$rowsF = openpyxl_load($VEN . '/ev4_metabolites.csv');
$header = array_map(fn ($v) => trim(str_replace(["'", "\r"], '', $v)), $rowsF[0]);
$speciesEV4 = array_slice($header, 1);
$metab = [];
foreach (array_slice($rowsF, 1) as $r) {
    $name = trim(str_replace(["'", "\r"], '', $r[0]));
    if ($name === '') { continue; }
    foreach ($speciesEV4 as $c => $sp) {
        $v = trim((string) ($r[$c + 1] ?? ''));
        if (is_numeric($v)) { $metab[$name][$sp] = (float) $v; }
    }
}

$CHEM = json_decode(file_get_contents('/tmp/ev4_chem.json'), true);
$parseFormula = function (string $f): ?array {
    if (!preg_match_all('/([A-Z][a-z]?)(\d*)/', $f, $m, PREG_SET_ORDER)) { return null; }
    $atoms = [];
    foreach ($m as $x) { $atoms[$x[1]] = ($x[2] === '') ? 1 : (int) $x[2]; }
    return $atoms;
};
$descriptors = function (string $name) use ($CHEM, $parseFormula): ?array {
    $ch = $CHEM[$name] ?? null;
    if (!$ch || empty($ch['formula'])) { return null; }
    $a = $parseFormula($ch['formula']);
    if (!$a || empty($a['C'])) { return null; }
    $C = $a['C']; $H = $a['H'] ?? 0; $O = $a['O'] ?? 0; $N = $a['N'] ?? 0;
    $P = $a['P'] ?? 0; $S = $a['S'] ?? 0;
    $dor = (4 * $C + $H - 2 * $O - 3 * $N + 5 * $P + 6 * $S) / $C;
    $mw = 12.011 * $C + 1.008 * $H + 15.999 * $O + 14.007 * $N + 30.974 * $P + 32.06 * $S;
    return ['DOR' => $dor, 'NC' => $N / $C, 'SC' => $S / $C, 'MW' => $mw, 'C' => $C];
};

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

$buildRows = function (string $sp, array $mNames) use ($communities, $ev3orderAll, $metab, $params): array {
    $out = [];
    foreach ($communities as $label => $mat) {
        for ($t = 0; $t < 6; $t++) {
            $xRow = [];
            foreach ($ev3orderAll as $c => $sp2) { $xRow[$sp2] = $mat[$t][$c]; }
            $xi = $xRow[$sp]; $xin = $mat[$t + 1][array_search($sp, $ev3orderAll, true)] ?? null;
            if ($xin === null || $xi < 0.01) { continue; }
            $s = $params['u' . $sp];
            foreach ($ev3orderAll as $j) { $s += $params['a' . $sp . $j] * $xRow[$j]; }
            $pools = [];
            foreach ($mNames as $mn) {
                $pool = 0.0;
                foreach ($xRow as $j => $xj) { if ($j !== $sp) { $pool += ($metab[$mn][$j] ?? 0.0) * $xj; } }
                $pools[$mn] = $pool;
            }
            $out[] = ['comm' => $label, 't' => $t, 'xi' => $xi, 'pools' => $pools, 'y' => ($xin - $xi) / $xi - $s];
        }
    }
    return $out;
};

$fitR2 = function (array $rows, array $mNames): float {
    $n = count($rows);
    $y = array_column($rows, 'y');
    $k = count($mNames) + 1;
    if ($n < $k + 3) { return NAN; }
    $X = [];
    foreach ($rows as $r) {
        $row = [1.0];
        foreach ($mNames as $mn) { $row[] = $r['pools'][$mn]; }
        $X[] = $row;
    }
    $A = array_fill(0, $k, array_fill(0, $k, 0.0));
    $b = array_fill(0, $k, 0.0);
    for ($i = 0; $i < $n; $i++) {
        for ($p = 0; $p < $k; $p++) {
            $b[$p] += $X[$i][$p] * $y[$i];
            for ($q = 0; $q < $k; $q++) { $A[$p][$q] += $X[$i][$p] * $X[$i][$q]; }
        }
    }
    for ($p = 0; $p < $k; $p++) {
        $mx = $p;
        for ($q = $p + 1; $q < $k; $q++) { if (abs($A[$q][$p]) > abs($A[$mx][$p])) { $mx = $q; } }
        [$A[$p], $A[$mx]] = [$A[$mx], $A[$p]];
        [$b[$p], $b[$mx]] = [$b[$mx], $b[$p]];
        if (abs($A[$p][$p]) < 1e-12) { return NAN; }
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
    $res = 0.0; $tot = 0.0;
    $my = array_sum($y) / $n;
    foreach ($y as $v) { $tot += ($v - $my) ** 2; }
    for ($i = 0; $i < $n; $i++) {
        $pred = 0.0;
        for ($p = 0; $p < $k; $p++) { $pred += $w[$p] * $X[$i][$p]; }
        $res += ($y[$i] - $pred) ** 2;
    }
    return $tot > 1e-12 ? 1 - $res / $tot : NAN;
};

// отбор per-вид: берём ВСЕ 97 (не гейт) и жадно строим терм глубиной до 4:
// на каждом шаге добавляем метаболит с максимальным приростом R2 (жадный фронт)
// — больше шагов, чем в B6.4 (там были только top-5 гейта).
$SEL = json_decode(file_get_contents('/tmp/e080_selection.json'), true);
$mtNames = array_keys($metab);

$allSteps = [];
foreach ($ev3order as $sp) {
    // старт: топ-1 гейта (если есть), иначе лучший одиночный
    $chosen = $SEL[$sp] ?? [];
    if (!$chosen) { continue; }
    $rows = $buildRows($sp, $chosen);
    if (count($rows) < 15) { continue; }
    $chosen = [$chosen[0]];
    $r2prev = $fitR2($rows, $chosen);
    if (!is_finite($r2prev)) { continue; }
    // жадное углубление до 4: перебор всех остальных (94!) — дорого, но
    // ограничим: перебираем все 97 кандидатов, 1 LSQ на кандидата = ~100 LSQ/шаг
    for ($depth = 0; $depth < 3; $depth++) {
        $bestGain = -INF; $bestMn = null;
        foreach ($mtNames as $mn) {
            if (in_array($mn, $chosen, true)) { continue; }
            $r2 = $fitR2($rows, array_merge($chosen, [$mn]));
            if (!is_finite($r2)) { continue; }
            if ($r2 - $r2prev > $bestGain) { $bestGain = $r2 - $r2prev; $bestMn = $mn; }
        }
        if ($bestMn === null || !is_finite($bestGain)) { break; }
        $ch = $descriptors($bestMn);
        if ($ch) {
            $allSteps[] = ['dR2' => $bestGain, 'DOR' => $ch['DOR'], 'NC' => $ch['NC'],
                           'SC' => $ch['SC'], 'MW' => $ch['MW'], 'sp' => $sp, 'depth' => $depth + 2,
                           'mn' => $bestMn];
        }
        $chosen[] = $bestMn;
        $r2prev += $bestGain;
    }
}

printf("шагов собрано: %d\n", count($allSteps));
$stat = function (string $key) use ($allSteps): float {
    $n = count($allSteps);
    $xs = array_column($allSteps, 'dR2'); $ys = array_column($allSteps, $key);
    $mx = array_sum($xs) / $n; $my = array_sum($ys) / $n;
    $sxy = 0.0; $sxx = 0.0; $syy = 0.0;
    for ($i = 0; $i < $n; $i++) { $sxy += ($xs[$i] - $mx) * ($ys[$i] - $my); $sxx += ($xs[$i] - $mx) ** 2; $syy += ($ys[$i] - $my) ** 2; }
    return ($sxx > 1e-12 && $syy > 1e-12) ? $sxy / sqrt($sxx * $syy) : 0.0;
};
// перестановочный null per-дескриптор
$permP = function (string $key, float $rReal) use ($allSteps): float {
    mt_srand(777777);
    $ys = array_column($allSteps, $key);
    $xs = array_column($allSteps, 'dR2');
    $n = count($xs);
    $mx = array_sum($xs) / $n;
    $cnt = 0; $N = 1000;
    for ($p = 0; $p < $N; $p++) {
        $sh = $ys;
        shuffle($sh);
        $my = array_sum($sh) / $n;
        $sxy = 0.0; $sxx = 0.0; $syy = 0.0;
        for ($i = 0; $i < $n; $i++) { $sxy += ($xs[$i] - $mx) * ($sh[$i] - $my); $sxx += ($xs[$i] - $mx) ** 2; $syy += ($sh[$i] - $my) ** 2; }
        $r = ($sxx > 1e-12 && $syy > 1e-12) ? $sxy / sqrt($sxx * $syy) : 0.0;
        if (abs($r) >= abs($rReal)) { $cnt++; }
    }
    return $cnt / $N;
};

echo "\n=== редокс-градиент на РЕАЛЬНОЙ химии (PubChem, жадные термы, acetate-free) ===\n";
foreach (['DOR' => 'степень восстановления', 'NC' => 'N/C азотистость', 'SC' => 'S/C сера', 'MW' => 'мол. масса'] as $key => $label) {
    $r = $stat($key);
    $p = $permP($key, $r);
    printf("  corr(dR2, %-24s) = %+.3f  p=%+.3f %s\n", $label . ')', $r, $p, $p < 0.05 ? 'PASS' : '');
}
echo "\n=== топ-5 шагов с наибольшим dR2 (какая химия даёт прирост) ===\n";
usort($allSteps, fn ($a, $b) => $b['dR2'] <=> $a['dR2']);
foreach (array_slice($allSteps, 0, 5) as $st) {
    $ch = $descriptors($st['mn']);
    printf("  %s d=%d %+.3f  %s DOR=%.1f N/C=%.2f\n", $st['sp'], $st['depth'], $st['dR2'],
        substr($st['mn'], 0, 30), $ch['DOR'], $ch['NC']);
}
