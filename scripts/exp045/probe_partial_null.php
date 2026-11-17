<?php
// BUGFIX-верификация 31.10: parse_csv ширина-агностична, labels = ширина X.
require __DIR__ . '/../../vendor/autoload.php';

$path = sys_get_temp_dir() . '/exp045_E1_probe_verify.csv';
$lines = [
    '1.5,2.5,4.0,1.2,7.0,3.3,42.5',   // dot4-формат: 6 фич + y (7 колонок)
    '2.0,3.0,4.5,1.8,8.0,2.2,31.0',
    '1.1,2.2,3.3,0.9,5.0,4.4,55.1',
];
file_put_contents($path, implode("\n", $lines) . "\n");

// Функции объявлены в driver.php — включить нельзя (скрипт исполняется).
// Реплицировать логику точь-в-точь и сверить с ожиданием:
function parse_csv_fixed(string $path): array
{
    $X = [];
    $y = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $v = str_getcsv($line);
        if (count($v) < 6 || ! is_numeric($v[0])) {
            continue;
        }
        $nFeat = count($v) - 1;
        $X[] = array_map('floatval', array_slice($v, 0, $nFeat));
        $y[] = (float) $v[$nFeat];
    }
    return [$X, $y];
}

[$X, $y] = parse_csv_fixed($path);
$labels = array_map(fn (int $i): string => "c{$i}", range(0, count($X[0]) - 1));

$okX = count($X[0]) === 6 && abs($X[0][5] - 3.3) < 1e-9 && abs($X[2][5] - 4.4) < 1e-9;
$okY = $y === [42.5, 31.0, 55.1];
$okL = $labels === ['c0', 'c1', 'c2', 'c3', 'c4', 'c5'];
echo 'X width=6 and col5 intact: ' . ($okX ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'y = last column:           ' . ($okY ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'labels c0..c5:             ' . ($okL ? 'PASS' : 'FAIL') . PHP_EOL;

// Живой end-to-end: dot4 задача E2, поиск С 6 метками обязан видеть 6 фич.
$p = json_decode(file_get_contents('/tmp/e2_fam.json'), true)['tasks'][1];
echo 'task under probe: id=' . $p['id'] . ' class=' . $p['class'] . PHP_EOL;
$s = $p['seed'];
$r = function () use (&$s) {
    $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;
    return $s / 0x7FFFFFFF;
};
$u = function (float $a, float $b) use ($r): float {
    return $a + $r() * ($b - $a);
};
$rows = [];
for ($i = 0; $i < 200; $i++) {
    $c = [$u(1, 3), $u(2, 5), $u(3, 7), $u(0.5, 2), $u(0, 9)];
    $c[] = $u(0.5, 4);
    $yv = $c[0] * $c[3] + $c[1] * $c[4] + $c[2] * $c[5] + $c[3] * $c[5];
    $row = [];
    foreach ($p['perm'] as $src) {
        $row[] = $c[$src];
    }
    $row[] = $yv;
    $rows[] = $row;
}
$X2 = [];
$y2 = [];
foreach ($rows as $rw) {
    $X2[] = array_slice($rw, 0, 6);
    $y2[] = $rw[6];
}
$g = new \BeeSwarm\Core\Grammar();
$g->restrictTo(['add', 'sub', 'mul', 'div', 'sq']);
$labels2 = array_map(fn (int $i): string => "c{$i}", range(0, 5));
$res = \BeeSwarm\Core\Search::find($X2, $y2, $g, 3, $labels2, 0.2, 0.10, 30.0);
$fx = $res[3] ?? ($res['formula'] ?? 'n/a');
echo 'baseline find depth3 (6 labels): found=' . var_export($res[0], true) .
    ' cv_eval=' . (isset($res[4]) ? var_export($res[4], true) : '?') .
    ' form=' . (is_string($fx) ? $fx : 'n/a') . PHP_EOL;
echo 'lastBestFormSeen=' . \BeeSwarm\Core\Search::$lastBestFormSeen .
    ' lastBestCvSeen=' . \BeeSwarm\Core\Search::$lastBestCvSeen . PHP_EOL;
