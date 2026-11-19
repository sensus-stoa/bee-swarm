<?php
declare(strict_types=1);

/**
 * B5.2: Воспроизведение T3.
 * 1) Мэппинг колонок EV3 по dropout-блокам (в блоке X- колонка X ~ 0).
 * 2) Интеграция их T3 (SBML) от t=0 строки каждого блока -> R2 против EV3.
 * 3) Наш фит one-step-ahead на EV1+EV2 (тот же класс моделей) -> EV3 R2.
 */

require __DIR__ . '/exp070_shared.php';

$SBML = '/tmp/ven_s006/twelveSpeciesT3.xml';
$EV3 = __DIR__ . '/../Benchmarks/venturelli/ev3_communities.csv';

// --- SBML: параметры и порядок видов ---
$xml = file_get_contents($SBML);
$params = [];
preg_match_all('/<parameter [^>]*id="([^"]+)"[^>]*value="([^"]+)"/', $xml, $pm, PREG_SET_ORDER);
foreach ($pm as $p) { $params[$p[1]] = (float) $p[2]; }
preg_match_all('/<species [^>]*id="x([A-Z]{2})"/', $xml, $sm);
$species = $sm[1]; // порядок x1..x12 из SBML
$n = count($species);

$r = [];
$A = [];
foreach ($species as $i) {
    $r[$i] = $params['u' . $i] ?? 0.0;
    foreach ($species as $j) { $A[$i][$j] = $params['a' . $i . $j] ?? 0.0; }
}

// --- EV3: блоки (csv сделан из xlsx: строки матрицы = одна ячейка с
// пробелами; fgetcsv возвращает одну колонку — разбор вручную) ---
$raw = file($EV3, FILE_IGNORE_NEW_LINES | FILE_IGNORE_NEW_LINES);
$communities = [];
$cur = null;
foreach ($raw as $line) {
    $line = trim($line);
    if ($line === '') { continue; }
    if (preg_match('/^([A-Z]{2}-|NONE)/', $line)) {
        $cur = rtrim($line);
        $communities[$cur] = [];
        continue;
    }
    if ($cur !== null && preg_match('/^[\d\s.eE+-]+$/', $line)) {
        $vals = array_map('floatval', preg_split('/\s+/', trim($line)));
        $communities[$cur][] = $vals;
    }
}
$labels = array_keys($communities);
echo 'EV3 блоков: ' . count($communities) . "\n";

// --- мэппинг колонок: в блоке X- колонка X имеет min max по времени ---
$colScores = []; // [col] => сумма max по всем dropout-блокам, где col соответствует X
$dropouts = array_filter($labels, fn ($l) => preg_match('/^([A-Z]{2})-$/', rtrim($l), $m) && $m[1] !== 'NO');
$colVotes = [];
foreach ($communities as $label => $mat) {
    if (!preg_match('/^([A-Z]{2})-$/', $label, $m)) { continue; }
    $X = $m[1];
    if ($X === 'NO') { continue; }
    // матрица: 7 строк (время) x 12 колонок (виды)
    for ($c = 0; $c < 12; $c++) {
        $colMax = 0.0;
        foreach ($mat as $row) { if (isset($row[$c])) { $colMax = max($colMax, $row[$c]); } }
        $colVotes[$X][$c] = $colMax;
    }
}
// для каждого dropout X колонка X = уникальное жадное назначение (min colMax)
$colOf = [];
$candidates = [];
foreach ($colVotes as $X => $scores) {
    asort($scores);
    $best = array_key_first($scores);
    $candidates[$X] = [$best => $scores[$best]] + $scores;
}
// жадно: сортируем пары (X, col) по colMax, берём уникальные
$all = [];
foreach ($candidates as $X => $scores) {
    foreach ($scores as $c => $v) { $all[] = [$v, $X, $c]; }
}
usort($all, fn ($a, $b) => $a[0] <=> $b[0]);
$usedX = []; $usedC = [];
foreach ($all as [$v, $X, $c]) {
    if (isset($usedX[$X]) || isset($usedC[$c])) { continue; }
    $colOf[$X] = $c;
    $usedX[$X] = true; $usedC[$c] = true;
}
$colMapping = []; // colIndex => speciesCode
foreach ($colOf as $X => $c) { $colMapping[$c] = $X; }
echo 'мэппинг колонок: ';
ksort($colMapping);
foreach ($colMapping as $c => $X) { echo "$c=$X "; }
echo "\n";
$dupes = array_count_values($colOf);
$duplicateCols = array_filter($dupes, fn ($v) => $v > 1);
echo $duplicateCols ? 'КОНФЛИКТЫ: ' . json_encode($duplicateCols) . "\n" : "мэппинг однозначен\n";

// колонки без голоса = виды без dropout-блока? всех 12 имеют dropout (NONE отдельно) — 12 dropouts
$missing = array_diff(range(0, 11), array_keys($colMapping));
echo 'колонок без mapping: ' . count($missing) . ' => ' . implode(',', $missing) . "\n";

// если все 12 замаплены — порядок колонок = species по colMapping
$orderedSpecies = [];
for ($c = 0; $c < 12; $c++) { $orderedSpecies[$c] = $colMapping[$c]; }
echo 'порядок видов по колонкам: ' . implode(',', $orderedSpecies) . "\n";
echo 'SBML порядок: ' . implode(',', $species) . "\n";
$permutation = ($orderedSpecies === $species) ? 'совпадает' : 'ОТЛИЧАЕТСЯ — нужна перестановка';
echo "порядок: $permutation\n";

// --- Интеграция их T3 на NONE (полное сообщество): RK4, 6 дней, 7 точек ---
// t=0 строка матрицы = начальные доли
$mat = $communities['NONE'];
$x0 = [];
for ($c = 0; $c < 12; $c++) { $x0[$colMapping[$c]] = $mat[0][$c]; }
$x0vec = [];
foreach ($species as $i) { $x0vec[] = $x0[$i]; }

// RK4: dt=1h, 6*24 шагов, точки на 0,24,48,...,144h (7 точек)
$f = function (array $x) use ($n, $species, $r, $A): array {
    $dx = [];
    for ($i = 0; $i < $n; $i++) {
        $s = $r[$species[$i]];
        $si = $species[$i];
        for ($j = 0; $j < $n; $j++) { $s += $A[$si][$species[$j]] * $x[$j]; }
        $dx[$i] = $x[$i] * $s;
    }
    return $dx;
};
$dt = 1.0;
$traj = [$x0vec];
$xc = $x0vec;
for ($day = 1; $day <= 6; $day++) {
    for ($h = 0; $h < 24; $h++) {
        $k1 = $f($xc);
        $x2 = array_map(fn ($v, $d) => $v + $d * $dt / 2, $xc, $k1);
        $k2 = $f($x2);
        $x3 = array_map(fn ($v, $d) => $v + $d * $dt / 2, $xc, $k2);
        $k3 = $f($x3);
        $x4 = array_map(fn ($v, $d) => $v + $d * $dt, $xc, $k3);
        $k4 = $f($x4);
        $xc = array_map(fn ($v, $a1, $a2, $a3, $a4) => $v + $dt / 6 * ($a1 + 2 * $a2 + 2 * $a3 + $a4), $xc, $k1, $k2, $k3, $k4);
    }
    $traj[] = $xc;
}
// нормировка в доли и сравнение с EV3 NONE
echo "\n=== Их T3 на NONE (полное 12-видовое) ===\n";
echo 'вид:  ' . implode('  ', $species) . "\n";
$allRes = 0.0; $allTot = 0.0;
foreach ($species as $i => $code) {
    $cCol = array_search($code, $orderedSpecies, true);
    $obs = [];
    foreach ($mat as $row) { $obs[] = $row[$cCol]; }
    $pred = [];
    foreach ($traj as $tp) {
        $sum = array_sum($tp);
        $pred[] = $sum > 0 ? $tp[$i] / $sum : 0.0;
    }
    $m = array_sum($obs) / 7;
    $res = 0.0; $tot = 0.0;
    for ($t = 0; $t < 7; $t++) { $res += ($obs[$t] - $pred[$t]) ** 2; $tot += ($obs[$t] - $m) ** 2; }
    $allRes += $res; $allTot += $tot;
    printf("%s: R2=%6.3f obs[0]=%.3f pred[0]=%.3f obs[6]=%.3f pred[6]=%.3f\n", $code, $tot > 1e-12 ? 1 - $res / $tot : NAN, $obs[0], $pred[0], $obs[6], $pred[6]);
}
echo 'ОБЩИЙ R2 (все виды, все точки, NONE): ' . round(1 - $allRes / $allTot, 3) . "\n";
