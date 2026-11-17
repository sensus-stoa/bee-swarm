<?php
// Диагноз WU BAR-E3 (вариант a): бар = :1980 plausible-потолок.
// E3 task, find с cvTrainMax=0.15 (дефолт Search) vs 0.10 (драйвер).
// Ожидание: 0.15 → found=true (форма train 0.104 проходит testCv);
// 0.10 → found=false (форма не входит в plausible).
require __DIR__ . '/../../vendor/autoload.php';

$p = json_decode(file_get_contents('/tmp/e2_fam.json'), true)['tasks'][2];
echo 'task id=' . $p['id'] . ' class=' . $p['class'] . PHP_EOL;
$s = $p['seed'];
$r = function () use (&$s) {
    $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;
    return $s / 0x7FFFFFFF;
};
$u = function (float $a, float $b) use ($r): float {
    return $a + $r() * ($b - $a);
};
$X2 = [];
$y2 = [];
for ($i = 0; $i < 200; $i++) {
    $c = [$u(1, 3), $u(2, 5), $u(3, 7), $u(0.5, 2), $u(0, 9)];
    $c[] = $u(0.5, 4);
    $yv = $c[0] * $c[3] + $c[1] * $c[4] + $c[2] * $c[5] + $c[3] * $c[5];
    $row = [];
    foreach ($p['perm'] as $src) {
        $row[] = $c[$src];
    }
    $row[] = $yv;
    $X2[] = array_slice($row, 0, 6);
    $y2[] = $row[6];
}
$g = new \BeeSwarm\Core\Grammar();
$g->restrictTo(['add', 'sub', 'mul', 'div', 'sq']);
$labels = array_map(fn (int $i): string => "c{$i}", range(0, 5));

foreach ([0.10, 0.15] as $ceil) {
    $res = \BeeSwarm\Core\Search::find($X2, $y2, $g, 3, $labels, 0.2, $ceil, 30.0);
    printf(
        "cvTrainMax=%.2f: found=%s cv_train=%s cv_test=%s form=%s argminForm=%s argminCv=%.4f\n",
        $ceil,
        var_export($res[0], true),
        ($res[1] ?? 9.99) < 9.9 ? number_format($res[1], 4) : '9.99',
        ($res[3] ?? 9.99) < 9.9 ? number_format($res[3], 4) : '9.99',
        (string) ($res[2] ?? 'none'),
        (string) \BeeSwarm\Core\Search::$lastBestFormSeen,
        \BeeSwarm\Core\Search::$lastBestCvSeen
    );
}
