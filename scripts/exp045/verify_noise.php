<?php
// WU CERT-V2-CALIB шаг 1 (reviewer: измерить CV(full+noise) до freeze).
// Полный dot4-закон (4-term) на данных E3 с шумом 0.05/0.10/0.15/0.20.
// Плюс: два типа null (shuffled-y с точной формой, random-form на real y).
// Сплиты: 5x 60/40 (сравнение с E3-матрицей) и 5x 80/20 (production ratio).

$s = 253262924;
$r = function () use (&$s) {
    $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;
    return $s / 0x7FFFFFFF;
};
$u = function (float $a, float $b) use ($r): float {
    return $a + $r() * ($b - $a);
};
$perm = [5, 2, 4, 1, 0, 3];

function cvRatio(array $f, array $y): ?float
{
    $ratios = [];
    foreach ($f as $i => $p) {
        if ($y[$i] == 0.0 || ! is_finite((float) $p)) return null;
        $ratios[] = (float) $p / $y[$i];
    }
    $mean = array_sum($ratios) / count($ratios);
    if ($mean == 0.0) return null;
    $var = 0.0;
    foreach ($ratios as $rv) { $var += ($rv - $mean) ** 2; }
    return sqrt($var / count($ratios)) / abs($mean);
}

function makeSplits(array $y, int $seed, int $k): array
{
    mt_srand($seed);
    $idx = range(0, count($y) - 1);
    for ($i = count($y) - 1; $i > 0; $i--) {
        $j = (int) floor((mt_rand() / mt_getrandmax()) * ($i + 1));
        [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]];
    }
    return [array_slice($idx, 0, $k), array_slice($idx, $k)];
}

// База: чистые данные E3 (200 строк), форма = полный 4-term (в колонках X)
$X = [];
$yClean = [];
$forms = ['full' => [], 'term2' => []];
$sFull = [];
for ($i = 0; $i < 200; $i++) {
    $c = [$u(1, 3), $u(2, 5), $u(3, 7), $u(0.5, 2), $u(0, 9)];
    $c[] = $u(0.5, 4);
    $t = [$c[0] * $c[3], $c[1] * $c[4], $c[2] * $c[5], $c[3] * $c[5]];
    $row = [];
    foreach ($perm as $src) { $row[] = $c[$src]; }
    $X[] = $row;
    $yClean[] = $t[0] + $t[1] + $t[2] + $t[3];
    $sFull[] = $t[0] + $t[1] + $t[2] + $t[3];
    $forms['term2'][] = $t[1] + $t[2]; // E3-форма (контроль)
}

// ===== ШАГ 1: CV(full+noise) на 0.05/0.10/0.15/0.20 =====
echo "=== CV(full 4-term) при шуме, 5x сплитов, 60/40 и 80/20 ===\n";
foreach ([0.20, 0.25, 0.30, 0.35, 0.40] as $noise) {
    // Шумуем ТУ ЖЕ базу: yN[i] = yClean[i] * (1 + eps), eps из mt_srand
    // (LCG-колонки X не трогаем — форма sFull соответствует колонкам).
    $yN = [];
    mt_srand(1000 + (int) ($noise * 1000));
    for ($i = 0; $i < 200; $i++) {
        $yv = $yClean[$i];
        if ($noise > 0) { $yv *= 1 + ((mt_rand() / mt_getrandmax()) - 0.5) * $noise; }
        $yN[] = $yv;
    }
    foreach (['60/40' => 120, '80/20' => 160] as $lbl => $k) {
        $cvs = [];
        for ($sp = 1; $sp <= 5; $sp++) {
            [$tr, $te] = makeSplits($yN, $sp * 104729, $k);
            $fe = []; $ye = [];
            foreach ($te as $i) { $fe[] = $sFull[$i]; $ye[] = $yN[$i]; }
            $cvs[] = cvRatio($fe, $ye);
        }
        $pass = count(array_filter($cvs, fn ($v) => $v !== null && $v <= 0.10));
        printf("noise=%.2f %s: CV=%s pass=%d/5\n", $noise, $lbl,
            implode('/', array_map(fn ($v) => $v === null ? 'null' : number_format($v, 4), $cvs)), $pass);
    }
}

// ===== ШАГ 2: два типа null =====
echo "\n=== NULL тип-1: точная форма на shuffled-y (5 сидов, 80/20) ===\n";
for ($sh = 1; $sh <= 5; $sh++) {
    mt_srand($sh * 7919);
    $ys = $yClean;
    for ($i = count($ys) - 1; $i > 0; $i--) {
        $j = (int) floor((mt_rand() / mt_getrandmax()) * ($i + 1));
        [$ys[$i], $ys[$j]] = [$ys[$j], $ys[$i]];
    }
    [$tr, $te] = makeSplits($ys, $sh * 104729, 160);
    $fe = []; $ye = [];
    foreach ($te as $i) { $fe[] = $sFull[$i]; $ye[] = $ys[$i]; }
    printf("shuffle#%d: CV=%s\n", $sh, number_format(cvRatio($fe, $ye), 4));
}

echo "\n=== NULL тип-2: случайные 2-term формы на реальном y (80/20) ===\n";
// случайные пары колонок (не термы закона в этих колонках после perm)
$allCols = [[0, 1], [0, 2], [0, 3], [0, 4], [0, 5], [1, 2], [1, 3], [1, 4], [1, 5], [2, 3], [2, 4], [2, 5], [3, 4], [3, 5], [4, 5]];
$lawPairs = [[1, 4], [2, 5]]; // в колонках X это термы закона
$randPairs = array_values(array_filter($allCols, fn ($p) => ! in_array($p, $lawPairs)));
$passAll = 0;
foreach (array_slice($randPairs, 0, 10) as $pi => $pr) {
    [$tr, $te] = makeSplits($yClean, ($pi + 1) * 7919, 160);
    $fe = []; $ye = [];
    foreach ($te as $i) { $fe[] = $X[$i][$pr[0]] * $X[$i][$pr[1]]; $ye[] = $yClean[$i]; }
    $cv = cvRatio($fe, $ye);
    $ok = $cv !== null && $cv <= 0.10;
    if ($ok) $passAll++;
    printf("rand(c%d*c%d): CV=%s %s\n", $pr[0], $pr[1], $cv === null ? 'null' : number_format($cv, 4), $ok ? 'PASS!!' : '');
}
echo "random-form FPR: $passAll/10\n";
