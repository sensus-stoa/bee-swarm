<?php
// WU E3-VERIFY (owner 0h: 4 проверки cert-качества формы ((c0×c1)+(c2×c3))).
// 1. Структура: форма = T2+T3 закона? (декомпозиция термов)
// 2. Perm-тест: shuffle y -> CV?
// 3. Мульти-split: 5 разных 60/40 -> CV?
// 4. Constant absorption: raw-форма (K=1 уже); CV при добавлении термов.

$s = 253262924; // E3 seed
$r = function () use (&$s) {
    $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;
    return $s / 0x7FFFFFFF;
};
$u = function (float $a, float $b) use ($r): float {
    return $a + $r() * ($b - $a);
};
$perm = [5, 2, 4, 1, 0, 3];
$X = [];
$y = [];
$terms = []; // T1..T4 генераторные (порядок закона)
for ($i = 0; $i < 200; $i++) {
    $c = [$u(1, 3), $u(2, 5), $u(3, 7), $u(0.5, 2), $u(0, 9)];
    $c[] = $u(0.5, 4);
    $t1 = $c[0] * $c[3]; $t2 = $c[1] * $c[4]; $t3 = $c[2] * $c[5]; $t4 = $c[3] * $c[5];
    $yv = $t1 + $t2 + $t3 + $t4;
    $row = [];
    foreach ($perm as $src) { $row[] = $c[$src]; }
    $X[] = $row;
    $y[] = $yv;
    $terms[] = [$t1, $t2, $t3, $t4];
}
// Форма в колонках X: c0×c1 + c2×c3 = c[5]·c[2] + c[4]·c[1] = T3 + T2
$f23 = [];
$f234 = []; // +T1
$f2341 = []; // +T1+T4 (полный)
foreach ($terms as $i => $tt) {
    $f23[] = $tt[2] + $tt[1];
    $f234[] = $tt[2] + $tt[1] + $tt[0];
    $f2341[] = $tt[2] + $tt[1] + $tt[0] + $tt[3];
}

function cvRatio(array $f, array $y): ?float
{
    $ratios = [];
    foreach ($f as $i => $p) {
        if ($y[$i] == 0.0 || ! is_finite((float) $p)) return null;
        $ratios[] = (float) $p / $y[$i];
    }
    $mean = array_sum($ratios) / count($ratios);
    $var = 0.0;
    foreach ($ratios as $rv) { $var += ($rv - $mean) ** 2; }
    $std = sqrt($var / count($ratios));
    return $mean != 0.0 ? $std / abs($mean) : null;
}

// Доли термов (контекст: доминирование)
$sums = [0.0, 0.0, 0.0, 0.0];
foreach ($terms as $tt) { for ($k = 0; $k < 4; $k++) $sums[$k] += $tt[$k]; }
$tot = array_sum($sums);
echo "=== 1. СТРУКТУРА: доли термов в законе ===\n";
for ($k = 0; $k < 4; $k++) printf("T%d: mean=%.2f share=%.1f%%\n", $k + 1, $sums[$k] / 200, 100 * $sums[$k] / $tot);
printf("Форма = T2+T3 (raw, констант нет): покрывает %.1f%% дисперсии массы\n", 100 * ($sums[1] + $sums[2]) / $tot);
printf("CV полный датасет: 2-term(T2+T3)=%.4f | 3-term(+T1)=%.4f | 4-term=%.4f\n",
    cvRatio($f23, $y), cvRatio($f234, $y), cvRatio($f2341, $y));

echo "\n=== 2. PERM-ТЕСТ: shuffle y (5 сидов) ===\n";
for ($sh = 1; $sh <= 5; $sh++) {
    mt_srand($sh * 7919);
    $ys = $y;
    for ($i = count($ys) - 1; $i > 0; $i--) {
        $j = (int) floor((mt_rand() / mt_getrandmax()) * ($i + 1));
        [$ys[$i], $ys[$j]] = [$ys[$j], $ys[$i]];
    }
    printf("shuffle#%d: CV=%.4f\n", $sh, cvRatio($f23, $ys));
}

echo "\n=== 3. МУЛЬТИ-SPLIT: 5x 60/40 (mt_srand разный) ===\n";
$pass = 0;
for ($sp = 1; $sp <= 5; $sp++) {
    mt_srand($sp * 104729);
    $idx = range(0, 199);
    for ($i = 199; $i > 0; $i--) {
        $j = (int) floor((mt_rand() / mt_getrandmax()) * ($i + 1));
        [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]];
    }
    $k = 120;
    $ye = [];
    $fe = [];
    foreach (array_slice($idx, $k) as $i) { $ye[] = $y[$i]; $fe[] = $f23[$i]; }
    $cv = cvRatio($fe, $ye);
    $ok = $cv !== null && $cv <= 0.10;
    if ($ok) $pass++;
    printf("split#%d: eval-n=%d CV=%s %s\n", $sp, count($ye), $cv === null ? 'null' : number_format($cv, 4), $ok ? 'PASS<=0.10' : '');
}
echo "ИТОГО passes: $pass/5\n";

echo "\n=== 4. CONSTANT ABSORPTION ===\n";
echo "Форма raw (K1=K2=1 уже): CV выше в п.1. Констант в имени нет.\n";
// Если бы константы поглощали структуру — добавление термов НЕ улучшало бы CV. Проверка:
echo "Улучшение 2-term -> 4-term: " . number_format(cvRatio($f23, $y), 4) . " -> " . number_format(cvRatio($f2341, $y), 4) . "\n";
