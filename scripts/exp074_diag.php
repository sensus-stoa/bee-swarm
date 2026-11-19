<?php
declare(strict_types=1);
// B5.5-diag: почему глобальный фит приращений с паро-спец. a_ij даёт R2<0?
require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);

$rows = openpyxl_load(__DIR__ . '/../Benchmarks/venturelli/ev1_pairs.csv');
$y = [];
$nByPair = [];
foreach ($rows as $r) {
    $name = trim(str_replace("'", '', (string) $r[0]));
    if (!preg_match('/^[A-Z]{4}$/', $name)) { continue; }
    $vals = [];
    for ($k = 1; $k <= 7; $k++) { $v = trim((string) $r[$k]); $vals[] = is_numeric($v) ? (float) $v : NAN; }
    for ($t = 0; $t < 6; $t++) {
        $xi = $vals[$t]; $xin = $vals[$t + 1] ?? NAN;
        if (!is_finite($xi) || !is_finite($xin) || $xi <= 1e-6) { continue; }
        $y[] = ($xin - $xi) / $xi;
        $nByPair[$name][] = ($xin - $xi) / $xi;
    }
}
$m = array_sum($y) / count($y);
$tot = 0.0;
foreach ($y as $v) { $tot += ($v - $m) ** 2; }
echo 'n=' . count($y) . ' mean=' . round($m, 4) . ' sd=' . round(sqrt($tot / count($y)), 4) . "\n";
// разброс y внутри пар
$within = 0.0; $nW = 0;
foreach ($nByPair as $pair => $yy) {
    $mm = array_sum($yy) / count($yy);
    foreach ($yy as $v) { $within += ($v - $mm) ** 2; $nW++; }
}
echo 'внутри-парная дисперсия: ' . round($within / $nW, 4) . ' (доля от общей ' . round(100 * $within / $tot) . "%)\n";
// экстремумы y (выбросы деления на малую x_i)
$ySorted = $y;
sort($ySorted);
echo 'y квантили: min=' . round($ySorted[0], 2) . ' 1%=' . round($ySorted[(int) (0.01 * count($ySorted))], 3) . ' медиана=' . round($ySorted[(int) (0.5 * count($ySorted))], 3) . ' 99%=' . round($ySorted[(int) (0.99 * count($ySorted))], 3) . ' max=' . round($ySorted[count($ySorted) - 1], 2) . "\n";
// сколько |y|>2 (деление на крошечную долю)
$big = count(array_filter($y, fn ($v) => abs($v) > 2));
echo "строк с |y|>2: $big\n";
