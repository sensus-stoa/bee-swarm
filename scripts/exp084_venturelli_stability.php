<?php
declare(strict_types=1);

/**
 * B6.2: Стабильность форм-кандидатов — частичная сертификация.
 *
 * Кандидаты из B6.1/B5.12:
 *   BO: (m88_nicotinate_neg/(x_i/Rmaxx_i))  — никотинат / нормированный x_i
 *   ER: (m_5-aminovalerate × Rnorm(m_nicotinamide)) — B5.12 форма
 *       (в B6.1 у ER лучшая форма — чисто собственная ((x_i+x_i²))²,
 *       метаболитов не содержит; для композиционного терма берём B5.12)
 *
 * Тесты:
 *  A. LODO: фит b на 11 dropouts, cv формы на 12-й (12 фолдов).
 *  B. Null: 200 перемешиваний EV4-профилей (вид j получает чужой профиль),
 *     cv формы на ложных пулах. z-оценка.
 *  C. MDL: bits(форма) + bits(остаток) vs bits(нул-модель).
 *
 * Чистая грамматика не нужна — формы применяем вручную (ExpressionEvaluator
 * не используем; считаем вектор формы напрямую по определению).
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);

$VEN = __DIR__ . '/../Benchmarks/venturelli';
$ev3order = ['BH', 'CA', 'BU', 'PC', 'BO', 'BV', 'BT', 'EL', 'FP', 'CH', 'DP', 'ER'];

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

// per-вид строки: y=residual T3, x_i, пулы нужных метаболитов
$buildRows = function (string $sp, array $mNames, ?array $shuffledMetab = null) use ($communities, $ev3order, $metab, $params): array {
    $M = $shuffledMetab ?? $metab;
    $rows = [];
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
                foreach ($xRow as $j => $xj) { if ($j !== $sp) { $pool += ($M[$mn][$j] ?? 0.0) * $xj; } }
                $pools[$mn] = $pool;
            }
            $rows[] = ['comm' => $label, 't' => $t, 'xi' => $xi, 'y' => ($xin - $xi) / $xi - $s, 'pools' => $pools];
        }
    }
    return $rows;
};

// формы: name => fn(xi, pools, rowsFull) => float
// Rmax(x_i) = max x_i по rowsFull; Rnorm(m) = (m-Rmin)/(Rmax-Rmin)
$makeForm = function (string $kind, string $m1, ?string $m2) /* closure factory */ {
    return function (array $row, array $rowsFull) use ($kind, $m1, $m2): float {
        $xi = $row['xi'];
        $m1v = $row['pools'][$m1];
        switch ($kind) {
            case 'nicotinate_over_normxi':
                $xmax = 0.0;
                foreach ($rowsFull as $r) { $xmax = max($xmax, $r['xi']); }
                return $m1v / ($xi / max($xmax, 1e-9));
            case 'aminovalerate_x_normm':
                $m2v = $row['pools'][$m2];
                $vals = array_column($rowsFull, 'pools') ?: [];
                $min = INF; $max = -INF;
                foreach ($rowsFull as $r) { $v = $r['pools'][$m2] ?? 0.0; $min = min($min, $v); $max = max($max, $v); }
                $norm = (abs($max - $min) > 1e-9) ? ($m2v - $min) / ($max - $min) : 0.0;
                return $m1v * $norm;
            default:
                return 0.0;
        }
    };
};

// фит формы: y ~ a + b*formVec, LSQ 2 параметра; cv = 1 - R2
$fitForm = function (array $rows, callable $formFn): array {
    $f = []; $y = [];
    foreach ($rows as $r) { $f[] = $formFn($r, $rows); $y[] = $r['y']; }
    $n = count($y);
    if ($n < 8) { return ['cv' => 9.99]; }
    $mf = array_sum($f) / $n; $my = array_sum($y) / $n;
    $sxx = 0.0; $sxy = 0.0; $syy = 0.0;
    for ($i = 0; $i < $n; $i++) { $sxx += ($f[$i] - $mf) ** 2; $sxy += ($f[$i] - $mf) * ($y[$i] - $my); $syy += ($y[$i] - $my) ** 2; }
    if ($sxx < 1e-12 || $syy < 1e-12) { return ['cv' => 9.99]; }
    $b = $sxy / $sxx; $a = $my - $b * $mf;
    $res = 0.0;
    for ($i = 0; $i < $n; $i++) { $res += ($y[$i] - ($a + $b * $f[$i])) ** 2; }
    return ['cv' => $res / $syy, 'b' => $b, 'a' => $a, 'r' => $sxy / sqrt($sxx * $syy)];
};

$report = function (string $name, string $sp, string $kind, string $m1, ?string $m2) use ($buildRows, $fitForm, $communities, $metab, $makeForm, $ev3order): void {
    echo "\n=== $name ($sp) ===\n";
    $formFn = $makeForm($kind, $m1, $m2);
    $dbgRows = $buildRows($sp, array_filter([$m1, $m2]));
    $dbgF = array_map(fn ($r) => $formFn($r, $dbgRows), $dbgRows);
    echo 'f-вектор: n=' . count($dbgF) . ' min=' . round(min($dbgF), 4) . ' max=' . round(max($dbgF), 4)
        . ' sd=' . round(sqrt(array_sum(array_map(fn ($v) => $v ** 2, $dbgF)) / count($dbgF)), 4) . "\n";
    $mNames = array_filter([$m1, $m2]);

    // A. LODO по композициям (train = все, кроме одной; test = та одна)
    $comms = array_keys($communities);
    $cvs = [];
    foreach ($comms as $testComm) {
        $train = array_values(array_filter($buildRows($sp, $mNames), fn ($r) => $r['comm'] !== $testComm));
        $test = array_values(array_filter($buildRows($sp, $mNames), fn ($r) => $r['comm'] === $testComm));
        if (count($test) < 3) { continue; }
        $fit = $fitForm($train, $formFn);
        if (($fit['cv'] ?? 9.99) > 5) { $cvs[$testComm] = NAN; continue; }
        // cv на holdout-композиции с фит-коэффициентами
        $f = []; $y = [];
        foreach ($test as $r) { $f[] = $fit['b'] * $formFn($r, $train); $y[] = $r['y']; }
        $n = count($y); $my = array_sum($y) / $n;
        $res = 0.0; $tot = 0.0;
        for ($i = 0; $i < $n; $i++) { $res += ($y[$i] - $f[$i]) ** 2; $tot += ($y[$i] - $my) ** 2; }
        $cvs[$testComm] = $tot > 1e-12 ? 1 - $res / $tot : NAN;
    }
    $ok = array_filter($cvs, fn ($v) => is_finite($v));
    if ($ok) {
        $med = $ok; sort($med);
        echo 'LODO: медиана holdout R2=' . round($med[(int) (count($med) / 2)], 3)
            . ' | положительных фолдов: ' . count(array_filter($ok, fn ($v) => $v > 0)) . '/' . count($ok) . "\n";
    } else {
        echo "LODO: нет валидных фолдов\n";
    }

    // B. Null: 200 перемешиваний EV4-профилей (маппинг вид->чужой профиль)
    mt_srand(424242);
    $realCv = $fitForm($buildRows($sp, $mNames), $formFn)['cv'] ?? 9.99;
    $nullCvs = [];
    $spList = $ev3order;
    for ($p = 0; $p < 200; $p++) {
        $shuffled = $metab;
        foreach ($shuffled as $mn => $bySp) {
            $sh = $bySp;
            shuffle($sh);
            $shuffled[$mn] = array_combine($spList, array_values($sh));
        }
        $rowsNull = $buildRows($sp, $mNames, $shuffled);
        $c = $fitForm($rowsNull, $formFn)['cv'] ?? 9.99;
        if (is_finite($c)) { $nullCvs[] = $c; }
    }
    sort($nullCvs);
    $z = 0;
    foreach ($nullCvs as $c) { if ($c >= $realCv) { $z++; } }
    echo sprintf("Null EV4-shuffle: реальный cv=%.3f | null медиана=%.3f, 5%%-квантиль=%.3f | z=%d/200 (меньше лучше)\n",
        $realCv, $nullCvs[(int) (count($nullCvs) / 2)] ?? NAN, $nullCvs[(int) (count($nullCvs) * 0.05)] ?? NAN, $z);

    // C. MDL: биты формы + биты остатка vs нул
    $rows = $buildRows($sp, $mNames);
    $fit = $fitForm($rows, $formFn);
    $n = count($rows);
    $sdNull = 0.0;
    $my = array_sum(array_column($rows, 'y')) / $n;
    foreach ($rows as $r) { $sdNull += ($r['y'] - $my) ** 2; }
    $sdNull = sqrt($sdNull / $n);
    $resBitsNull = $n * log(2 * M_PI * M_E * $sdNull ** 2, 2) / 2;
    $resForm = 0.0;
    foreach ($rows as $r) { $e = $r['y'] - ($fit['a'] + $fit['b'] * $formFn($r, $rows)); $resForm += $e ** 2; }
    $sdForm = sqrt($resForm / $n);
    $resBitsForm = $n * log(2 * M_PI * M_E * $sdForm ** 2, 2) / 2;
    $formBits = 24; // ~3 float-параметра + структура (грубо, консервативно)
    $gain = $resBitsNull - ($resBitsForm + $formBits);
    printf("MDL: null=%.0f bits | форма=%.0f+%d=%.0f | выигрыш=%+.0f bits %s\n",
        $resBitsNull, $resBitsForm, $formBits, $resBitsForm + $formBits, $gain, $gain > 3 ? 'PASS(>3)' : 'FAIL');
};

// BO: никотинат (m88) / norm(x_i)
$report('BO nicotinate/norm(x_i)', 'BO', 'nicotinate_over_normxi', 'nicotinate_negative_7p52', null);
// ER: 5-aminovalerate × Rnorm(nicotinamide)
$report('ER aminovalerate×norm(nicotinamide)', 'ER', 'aminovalerate_x_normm', '5-aminovaleric_acid_positive_13p23', 'nicotinamide_positive_3p16');
