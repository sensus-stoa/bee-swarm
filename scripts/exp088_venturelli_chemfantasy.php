<?php
declare(strict_types=1);

/**
 * B6.4: ХИМИЧЕСКАЯ ФАНТАЗМ-ГИПОТЕЗА (пользователь): чем сложнее форма
 * композиции метаболитов, тем сложнее соединение она должна потреблять
 * и тем больше окислительно-восстановительных процессов вовлечено.
 * Без наложенной химии это не увидеть — фантазируем: строим ХИМИЧЕСКИЕ
 * дескрипторы EV4 из ИМЕНИ метаболита:
 *   - N_atoms (число N в формуле-имени, proxy: количество 'L-', 'amino',
 *     'aden', 'cyt', 'guan', 'ur', 'thym', 'nicot', 'pipec', 'xanth',
 *     'hypoxanth', 'orot', 'histid' — азотистые)
 *   - redox_class: маркёры окислительно-восстановительных групп
 *     (sulfoxide, hydroxy-, oxo-, cyclic-monophosphate, deoxy-...)
 *   - complexity: длина имени + число колец/фрагментов
 *
 * Тест: коррелирует ли «глубина терма» (число метаболитов в композиции,
 * которое движок/отбор тянет в модель) с (a) N-богатством, (b) redox-
 * маркёрами, (c) сложностью? Если сложные термы живут на более
 * редуцированных/сложных субстратах — это уже органическая химия
 * редокс-каскадов, а не случайные корреляции.
 *
 * Плюс главное: совместная модель per-вид с МНОГИМИ метаболитами —
 * если добавление 2-3 метаболитов (композиция) даёт непропорциональный
 * прирост R2 по сравнению с одиночками — это «сложная форма потребляет
 * сложное соединение» = ко-потребление, а не независимые эффекты.
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

// --- химические дескрипторы из имени (fantasy cheminformatics) ---
$chem = function (string $name): array {
    $n = strtolower($name);
    // азотистые группы (число разных N-паттернов)
    $nPatterns = ['amino', 'aden', 'cyt', 'guan', 'urac', 'thym', 'nicot', 'pipec', 'xanth', 'hypoxanth', 'orot', 'histid', 'lys', 'argin', 'ornith', 'prolin', 'purin', 'pyrimid', 'imidaz', 'indol', 'pyrrol'];
    $nCount = 0;
    foreach ($nPatterns as $pat) { if (str_contains($n, $pat)) { $nCount++; } }
    // редокс-маркёры: окисленные/восстановленные формы
    $redoxPatterns = ['sulfoxide', 'hydroxy', 'oxo', 'deoxy', 'cyclic', 'hydroquin', 'quinol', 'glutar', 'citramal', 'ketobuty', 'dihydroxy', 'phospho'];
    $redoxCount = 0;
    foreach ($redoxPatterns as $pat) { if (str_contains($n, $pat)) { $redoxCount++; } }
    // сложность: длина + число '-' и '_'
    $complexity = strlen($name) + substr_count($name, '-') * 2 + substr_count($name, '_');
    // пептидность (дипептиды: 2 amino-кислотных фрагмента)
    $peptide = (int) (preg_match('/^[A-Z][a-z]+-[A-Z][a-z]+/', $name) || preg_match('/yl[A-Z]/', $name));
    return ['n' => $nCount, 'redox' => $redoxCount, 'cplx' => $complexity, 'pep' => $peptide];
};

// --- собираем per-вид последовательное добавление метаболитов (глубина терма)
// и смотрим, растёт ли corr с химическими дескрипторами
$SEL = json_decode(file_get_contents('/tmp/e080_selection.json'), true);

$fitR2 = function (array $rows, array $mNames) use ($params): float {
    $n = count($rows);
    $y = array_column($rows, 'y');
    $k = count($mNames);
    if ($n < $k + 3) { return NAN; }
    $X = [];
    foreach ($rows as $r) {
        $row = [1.0];
        foreach ($mNames as $mn) { $row[] = $r['pools'][$mn]; }
        $X[] = $row;
    }
    $k = count($X[0]);
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
            $out[] = ['comm' => $label, 't' => $t, 'xi' => $xi, 'pools' => $pools, 'y' => ($xin - $xi) / $xi - $s];
        }
    }
    return $out;
};

echo "=== ТЕСТ СЛОЖНОСТИ: прирост R2 при углублении терма vs химия добавляемых метаболитов ===\n";
echo "(гипотеза: глубокие термы тянут более редокс-богатые и сложные соединения)\n\n";
printf("%-3s глубина | dR2 шага | N-богатство | redox | сложность | пептид\n", 'sp');
foreach ($ev3order as $sp) {
    if ($sp === 'PC') { continue; }
    $ms = $SEL[$sp] ?? [];
    if (count($ms) < 2) { continue; }
    $rows = $buildRows($sp, $ms);
    $r2prev = $fitR2($rows, [$ms[0]]);
    for ($d = 1; $d < min(4, count($ms)); $d++) {
        $r2 = $fitR2($rows, array_slice($ms, 0, $d + 1));
        $ch = $chem($ms[$d]);
        printf("%-3s %d->%d     | %+.3f     | %d           | %d     | %d         | %d\n",
            $sp, $d, $d + 1, $r2 - $r2prev, $ch['n'], $ch['redox'], $ch['cplx'], $ch['pep']);
        $r2prev = $r2;
    }
}

echo "\n=== суммарно: corr(dR2 шага, redox/complexity) по всем шагам ===\n";
$allSteps = [];
foreach ($ev3order as $sp) {
    if ($sp === 'PC') { continue; }
    $ms = $SEL[$sp] ?? [];
    if (count($ms) < 2) { continue; }
    $rows = $buildRows($sp, $ms);
    $r2prev = $fitR2($rows, [$ms[0]]);
    for ($d = 1; $d < min(4, count($ms)); $d++) {
        $r2 = $fitR2($rows, array_slice($ms, 0, $d + 1));
        $ch = $chem($ms[$d]);
        $allSteps[] = ['dR2' => $r2 - $r2prev, 'n' => $ch['n'], 'redox' => $ch['redox'], 'cplx' => $ch['cplx'], 'pep' => $ch['pep']];
        $r2prev = $r2;
    }
}
$stat = function (string $key) use ($allSteps): array {
    $n = count($allSteps);
    $xs = array_column($allSteps, 'dR2'); $ys = array_column($allSteps, $key);
    $mx = array_sum($xs) / $n; $my = array_sum($ys) / $n;
    $sxy = 0.0; $sxx = 0.0; $syy = 0.0;
    for ($i = 0; $i < $n; $i++) { $sxy += ($xs[$i] - $mx) * ($ys[$i] - $my); $sxx += ($xs[$i] - $mx) ** 2; $syy += ($ys[$i] - $my) ** 2; }
    return ($sxx > 1e-12 && $syy > 1e-12) ? [$sxy / sqrt($sxx * $syy), $n] : [0.0, $n];
};
foreach (['n' => 'N-богатство', 'redox' => 'redox-маркёры', 'cplx' => 'сложность', 'pep' => 'пептидность'] as $key => $label) {
    [$r, $n] = $stat($key);
    printf("  corr(dR2, %s) = %+.3f (n=%d)\n", $label, $r, $n);
}
