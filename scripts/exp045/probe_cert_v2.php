<?php

declare(strict_types=1);

// WU DRIVER-CERT-V2: probe cert_v2 по driver-канону split_eval.
// Печатает тройки CV для E2-partial и E6-exact (фикстуры теста).

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/cert_utils.php';

function genDot4Probe(int $seed, array $perm): array
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

$cases = [
    'E2-partial' => [106239912, [1, 3, 0, 5, 2, 4], '((x0×x5)+(x3×x4))'],
    'E6-exact' => [63956647, [4, 1, 5, 2, 3, 0], '(((x0×x1)+(x2×x3))+(x2×x4))+(x4×x5)'],
];
foreach ($cases as $name => [$seed, $perm, $fx]) {
    [$Xall, $yall] = genDot4Probe($seed, $perm);
    $res = cert_v2($fx, $Xall, $yall, $seed);
    printf("%s: pass=%s cvs=%s reason=%s\n", $name, $res['pass'] ? 'true' : 'false',
        implode('/', array_map(fn ($v) => $v === null ? 'null' : number_format($v, 4), $res['cvs'])),
        $res['reason'] ?? '-');
}
