<?php

declare(strict_types=1);

// WU CERT-V2 FREEZE (01.11, аналитик): null-FPR до 0/100.
// Prereg: ДВА типа null, оба обязаны fail (pass=False) на cert_v2:
//   тип-1 shuffled-y: точная 4-term форма на перемешанном y (50 сидов)
//   тип-2 random-form: случайные 2-term формы на реальном y (50 сидов)
// Итог: 0/100 = prereg-критерий 1 закрыт -> CERT-V2 FROZEN.

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/cert_utils.php';

// Данные dot4 (X-координаты после perm E2, как в кампании)
function gen4(int $seed, array $perm): array
{
    $s = $seed;
    $r = function () use (&$s) {
        $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;

        return $s / 0x7FFFFFFF;
    };
    $u = function (float $a, float $b) use ($r): float {
        return $a + $r() * ($b - $a);
    };
    $X = [];
    $y = [];
    for ($i = 0; $i < 200; $i++) {
        $c = [$u(1, 3), $u(2, 5), $u(3, 7), $u(0.5, 2), $u(0, 9)];
        $c[] = $u(0.5, 4);
        $yv = $c[0] * $c[3] + $c[1] * $c[4] + $c[2] * $c[5] + $c[3] * $c[5];
        $row = [];
        foreach ($perm as $src) {
            $row[] = $c[$src];
        }
        $X[] = $row;
        $y[] = $yv;
    }

    return [$X, $y];
}

$perm = [1, 3, 0, 5, 2, 4]; // E2
[$X, $y] = gen4(106239912, $perm);
$exact = '((x0×x1)+(x2×x3))+(x2×x4)+(x4×x5)'; // все 4 терма в X-координатах E6... для E2-координат точная форма:
$exactE2 = '((x1×x4)+(x0×x5))+(x1×x2)+(x4×x2)';

// ---- ТИП 1: shuffled-y (точная форма обязана fail) ----
$pass1 = 0;
$n1 = 0;
$cvMax1 = 0.0;
for ($i = 0; $i < 50; $i++) {
    mt_srand(777000 + $i);
    $ys = $y;
    for ($k = count($ys) - 1; $k > 0; $k--) {
        $j = (int) floor(mt_rand() / (mt_getrandmax() + 1) * ($k + 1));
        [$ys[$k], $ys[$j]] = [$ys[$j], $ys[$k]];
    }
    $res = cert_v2($exactE2, $X, $ys, 106239912 + $i * 7919);
    if ($res === null) { continue; }
    $n1++;
    if ($res['pass']) {
        $pass1++;
        echo "TYPE1 PASS!! i=$i cvs=" . implode('/', array_map(fn ($v) => number_format($v, 4), $res['cvs'])) . "\n";
    }
    foreach ($res['cvs'] as $cv) {
        if ($cv !== null && $cv > $cvMax1) { $cvMax1 = $cv; }
    }
}
echo "TYPE1 shuffled-y: $pass1/$n1 pass, maxCV=" . number_format($cvMax1, 4) . "\n";

// ---- ТИП 2: random-form на реальном y (обязана fail) ----
// Случайные пары колонок в X-координатах (не термы закона E2:
// термы E2 в X: x1·x4, x0·x5, x1·x2, x4·x2 — исключаем их и их перестановки)
$lawPairs = [[0, 5], [1, 4], [1, 2], [2, 4]];
$all = [];
foreach (range(0, 5) as $a) {
    foreach (range(0, 5) as $b) {
        if ($a === $b) { continue; }
        $p = [min($a, $b), max($a, $b)];
        if (in_array($p, $lawPairs)) { continue; }
        $all["$a$b"] = $p;
    }
}
$pairs = array_values($all);
$pass2 = 0;
$n2 = 0;
$cvMax2 = 0.0;
for ($i = 0; $i < 50; $i++) {
    $pr = $pairs[($i * 7) % count($pairs)];
    $op = ['×', '+', '−', 'div'][($i + 1) % 4];
    if ($op === 'div') {
        $form = "(x{$pr[0]}divx{$pr[1]})";
    } else {
        $form = "(x{$pr[0]}{$op}x{$pr[1]})";
    }
    $res = cert_v2($form, $X, $y, 106239912 + 31 + $i * 104729);
    if ($res === null) { continue; }
    $n2++;
    if ($res['pass']) {
        $pass2++;
        echo "TYPE2 PASS!! i=$i form=$form cvs=" . implode('/', array_map(fn ($v) => number_format($v, 4), $res['cvs'])) . "\n";
    }
    foreach ($res['cvs'] as $cv) {
        if ($cv !== null && $cv > $cvMax2) { $cvMax2 = $cv; }
    }
}
echo "TYPE2 random-form: $pass2/$n2 pass, maxCV=" . number_format($cvMax2, 4) . "\n";
echo "TOTAL: " . ($pass1 + $pass2) . "/" . ($n1 + $n2) . "\n";
