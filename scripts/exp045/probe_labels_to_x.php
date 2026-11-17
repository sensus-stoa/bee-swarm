<?php
// 0h no_eval: проверка labels_to_x (width-agnostic c→x) на классах форм.
require __DIR__ . '/../../vendor/autoload.php';

// Копия функции с фиксом (driver не автозагружается как класс).
function labels_to_x(string $formula): string
{
    $out = $formula;
    $kept = [];
    $out = (string) preg_replace_callback('/RL[0-9a-f]{8}/', function ($m) use (&$kept) {
        $key = "\x00" . count($kept) . "\x00";
        $kept[$key] = $m[0];

        return $key;
    }, $out);
    $out = (string) preg_replace_callback('/c(\d+)/', fn ($m) => 'x' . $m[1], $out);

    return strtr($out, $kept);
}

$cases = [
    '((c0×c1)+(c2×c3))',                       // E3 partial (чистые cN, до c4)
    '((c1×c4)+(c2×c5))',                       // no_eval класс: содержит c5
    '(((c0×c1)+(c2×c5))+(x4×c5))',             // уже смешанная
    '(((c0×c1)+(c2×c3))+(c4×c5))',             // 3-term dot4, c4+c5
    'Rminc5',                                   // R-атом с c5
    '((c0minc2)div(c3maxc5))',                  // склеенные R-суффиксы
    '(RLa1c0f3e5addc5)',                        // RL-имя + c5 (protect-чек)
    'c10',                                      // двузначный индекс
];
$pass = 0;
foreach ($cases as $f) {
    $x = labels_to_x($f);
    $hasC = preg_match('/(?<![0-9a-zA-Z])c\d+/', $x) === 1;
    // RL-имена возвращаются и НЕ должны пострадать
    $rlOk = true;
    if (str_contains($f, 'RL') && ! str_contains($x, 'RLa1c0f3e5')) {
        $rlOk = false;
    }
    $ok = ! $hasC || str_contains($f, 'RL'); // 'c' внутри RL-гекса не считается
    if ($rlOk && $ok) { $pass++; }
    printf("%s\n  -> %s\n", $f, $x);
}
echo "structure: $pass/" . count($cases) . "\n";

// Функциональный чек: cert_x на фикстуре с 7 колонками (c5 существует)
$X = [];
$y = [];
$s = 253262924;
$r = function () use (&$s) {
    $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;
    return $s / 0x7FFFFFFF;
};
$u = function (float $a, float $b) use ($r): float {
    return $a + $r() * ($b - $a);
};
$perm = [5, 2, 4, 1, 0, 3];
for ($i = 0; $i < 200; $i++) {
    $c = [$u(1, 3), $u(2, 5), $u(3, 7), $u(0.5, 2), $u(0, 9)];
    $c[] = $u(0.5, 4);
    $yv = $c[0] * $c[3] + $c[1] * $c[4] + $c[2] * $c[5] + $c[3] * $c[5];
    $row = [];
    foreach ($perm as $src) { $row[] = $c[$src]; }
    $X[] = $row;
    $y[] = $yv;
}
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
// 3-term форма с c5: (c0c1)+(c2c3)+(c4c5) — в колонках X: c5·c2 + c4·c1 + c0·c3
$form5 = '((c0×c1)+(c2×c3))';
$form3 = '(((c0×c1)+(c2×c3))+(c4×c5))';
foreach ([$form5 => 'E3 2-term', $form3 => '3-term c5'] as $f => $name) {
    $fx = labels_to_x($f);
    $vals = BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($fx, $X);
    $cv = $vals === null ? null : cvRatio($vals, $y);
    printf("%s: %s -> CV=%s\n", $name, $fx, $cv === null ? 'null' : number_format($cv, 4));
}
