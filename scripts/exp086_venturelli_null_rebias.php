<?php
declare(strict_types=1);

/**
 * B6.2c: РЕБАЯС null — что именно проверяет каждая перестановка и где
 * их слепые зоны. Ответ на «что-то не так с этими перестановками».
 *
 * Три вида перестановок и что они разрушают:
 *  N1 profile-shuffle (B6.2b): вид j получает ЧУЖОЙ ВЕСЬ профиль (97 значений).
 *     Разрушает: связку метаболит-вид целиком.
 *     СОХРАНЯЕТ: маржинальные распределения каждого метаболита по видам
 *     (тот же вектор значений, переставленный). СЛЕПАЯ ЗОНА: формы,
 *     зависящие от МАСШТАБА пула, а не от соответствия вид-метаболит.
 *  N2 pool-row-shuffle: вектор пула по строкам.
 *     Разрушает: временную структуру. СЛЕПАЯ ЗОНА: любые композиции
 *     через агрегаты пула (mean по строкам) — их не разрушает.
 *  N3 sign-flip per-species: знак log2fold каждого (метаболит,вид)
 *     флипается с p=0.5, абсолютные значения сохраняются.
 *     Разрушает: НАПРАВЛЕНИЕ (производит vs потребляет), сохраняя
 *     магнетуды и вид-структуру. Это ближайший к химии тест: если
 *     форма живёт только на знаках — она о роли (производство/потребление);
 *     если живёт и на флипнутых знаках — она о магнетудах = коллинеарность.
 *
 * Химический смысл: log2fold>0 = вид СЕКРЕТИРУЕТ метаболит (доступен
 * другим), log2fold<0 = ПОТРЕБЛЯЕТ. Форма терма «метаболит m доступен
 * виду i» = sum_j P_jm x_j - U_im * (его потребление) — знак-сенситивна.
 * Если EV4-форма сертифицируется при N3 — она использует НАПРАВЛЕННУЮ
 * химическую информацию, а не магнетуды.
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

// Универсальная сборка: цель residual T3, фичи — пулы по списку метаболитов
// + x_i; форма подаётся как строка вида 'lin:M1', 'mul:M1:M2', 'over:M1' и т.д.
$buildRows = function (string $sp, array $mNames, ?array $M = null) use ($communities, $ev3order, $metab, $params): array {
    $MM = $M ?? $metab;
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
                foreach ($xRow as $j => $xj) { if ($j !== $sp) { $pool += ($MM[$mn][$j] ?? 0.0) * $xj; } }
                $pools[$mn] = $pool;
            }
            $out[] = ['comm' => $label, 't' => $t, 'xi' => $xi, 'pools' => $pools, 'y' => ($xin - $xi) / $xi - $s];
        }
    }
    return $out;
};

// form = ['mul', M1, M2] | ['lin', M1] | ['over', M1]
$makeVec = function (array $spec) use ($buildRows): callable {
    return function (array $rowsFull) use ($spec): array {
        $kind = $spec[0];
        $v = [];
        if ($kind === 'lin') {
            foreach ($rowsFull as $r) { $v[] = $r['pools'][$spec[1]]; }
        } elseif ($kind === 'mul') {
            $m2 = $spec[2];
            $min = INF; $max = -INF;
            foreach ($rowsFull as $r) { $val = $r['pools'][$m2]; $min = min($min, $val); $max = max($max, $val); }
            foreach ($rowsFull as $r) {
                $norm = (abs($max - $min) > 1e-9) ? ($r['pools'][$m2] - $min) / ($max - $min) : 0.0;
                $v[] = $r['pools'][$spec[1]] * $norm;
            }
        } elseif ($kind === 'over') {
            $xmax = 0.0;
            foreach ($rowsFull as $r) { $xmax = max($xmax, $r['xi']); }
            foreach ($rowsFull as $r) { $v[] = $r['pools'][$spec[1]] / ($r['xi'] / max($xmax, 1e-9)); }
        }
        return $v;
    };
};

$metrics = function (array $rowsFull, callable $vecFn): array {
    $n = count($rowsFull);
    $f = $vecFn($rowsFull);
    $y = array_column($rowsFull, 'y');
    $mf = array_sum($f) / $n; $my = array_sum($y) / $n;
    $sxy = 0.0; $sxx = 0.0; $syy = 0.0;
    for ($i = 0; $i < $n; $i++) { $sxy += ($f[$i] - $mf) * ($y[$i] - $my); $sxx += ($f[$i] - $mf) ** 2; $syy += ($y[$i] - $my) ** 2; }
    if ($sxx < 1e-12 || $syy < 1e-12) { return ['corr' => 0.0, 'cv' => 9.99]; }
    $corr = $sxy / sqrt($sxx * $syy);
    $b = $sxy / $sxx; $a = $my - $b * $mf;
    $res = 0.0;
    for ($i = 0; $i < $n; $i++) { $res += ($y[$i] - ($a + $b * $f[$i])) ** 2; }
    return ['corr' => abs($corr), 'cv' => $res / $syy];
};

// N3: sign-flip per (метаболит, вид), p=0.5, магнетуды сохранены
$signFlip = function (int $seed) use ($metab, $speciesEV4): array {
    mt_srand($seed);
    $out = [];
    foreach ($metab as $mn => $bySp) {
        $row = [];
        foreach ($speciesEV4 as $sp) {
            $v = $bySp[$sp] ?? 0.0;
            $row[$sp] = (mt_rand() / mt_getrandmax() < 0.5) ? -$v : $v;
        }
        $out[$mn] = $row;
    }
    return $out;
};

// Скриним по формам: реальные p-значения под N1/N3 для каждой из 11 видов
// формы: топ-1 из B6.1-блоков (кратко: lin по топ-1 B5.12-отбора per-вид)
$SEL = json_decode(file_get_contents('/tmp/e080_selection.json'), true);
mt_srand(999);

printf("%-3s %-40.40s | corr_real | p_N1(shuffle) p_N3(signflip)\n", 'sp', 'форма');
$summary = [];
foreach ($ev3order as $sp) {
    if ($sp === 'PC') { continue; }
    $ms = $SEL[$sp] ?? [];
    if (!$ms) { continue; }
    $m1 = $ms[0];
    $spec = ['lin', $m1];
    $vecFn = $makeVec($spec);
    $rowsReal = $buildRows($sp, [$m1]);
    $real = $metrics($rowsReal, $vecFn);
    // N1: profile-shuffle
    $p1 = 0; $N = 300;
    for ($p = 0; $p < $N; $p++) {
        $sh = [];
        foreach ($metab as $mn => $bySp) {
            $vals = array_values($bySp);
            shuffle($vals);
            $sh[$mn] = array_combine($ev3order, $vals);
        }
        $c = $metrics($buildRows($sp, [$m1], $sh), $vecFn);
        if ($c['corr'] >= $real['corr']) { $p1++; }
    }
    // N3: sign-flip
    $p3 = 0;
    for ($p = 0; $p < $N; $p++) {
        $sf = $signFlip(1000 + $p);
        $c = $metrics($buildRows($sp, [$m1], $sf), $vecFn);
        if ($c['corr'] >= $real['corr']) { $p3++; }
    }
    printf("%-3s %-40.40s | %.3f      | %.3f          %.3f\n", $sp, $m1, $real['corr'], $p1 / $N, $p3 / $N);
    $summary[$sp] = ['real' => $real['corr'], 'pN1' => $p1 / $N, 'pN3' => $p3 / $N];
}

$pass = array_filter($summary, fn ($s) => $s['pN3'] < 0.05 && $s['pN1'] < 0.05);
printf("\nФорм, значимых под ОБОИМИ null (N1+N3, p<0.05): %d из %d\n", count($pass), count($summary));
foreach ($pass as $sp => $s) {
    printf("  %s: corr=%.3f pN1=%.3f pN3=%.3f\n", $sp, $s['real'], $s['pN1'], $s['pN3']);
}
