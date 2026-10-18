<?php

// PROBE-1: генератор семейств (DEV mechanistic gate, v1).
//
// КЛАСС G2/G3 ОТКРЫТО КОМПОЗИЦИОННЫЙ (документируется по требованию owner):
//   y = sdr(c0,c1) + (c0−c2),  где sdr(a,b) = (a+b)/(a−b)
//   — истинная формула СОДЕРЖИТ T₁-композит как подтерм. Это DEV-гейт
//   механики вложенного рождения, НЕ подтверждающий эксперимент: задачи
//   специально композиционные, их результат не выдаётся за открытую эволюцию.
//
// Структура:
//   g1.json  — 2 × sdr-EARLY (c0→x0, c1→x1 строго) → T₁ через компрессор
//   g2.json  — 2 × sdrplusdiff, ОДИНАКОВАЯ perm [0,1,2,3,4] (молекулярная
//              группировка компрессора НЕ переименовывает слоты — пара
//              изоморфов требует идентичных колонок), разные сиды
//   g3.json  — 2 × sdrplusdiff (НОВЫЕ сиды — D₃ зафиксирован до G2-прогонов)
//              + 2 null (permuted y)
//
// Класс sdrplusdiff достижим: B-рука — L2-пара (Bform[x0,x1], (x0−x2)[pool]);
// A-рука — L2L1 на depth-3 (explosion → отрицательна); сырая форма содержит
// L1×фичу → вне L2-пула (урок PROBE-0 A1-бис).

$outDir = $argv[1] ?? '';
if ($outDir === '') {
    fwrite(STDERR, "usage: gen_families.php <outDir>\n");
    exit(2);
}
@mkdir($outDir, 0777, true);

$seedOf = fn (string $key): int => (int) hexdec(substr(md5($key), 0, 7));

// G1: sdr EARLY (строгие раскладки — некоммутативность /) × 4 реплики
$g1 = [
    'replicates' => [],
];
for ($r = 1; $r <= 4; $r++) {
    $g1['replicates'][(string) $r] = [
        [
            'id' => 'G1F1',
            'class' => 'sdr',
            'seed' => $seedOf("PROBE1G1{$r}1"),
            'perm' => [0, 1, 2, 3, 4],
            'scale' => [1, 1, 1, 1, 1],
            'noise' => 0.0,
        ],
        [
            'id' => 'G1F2',
            'class' => 'sdr',
            'seed' => $seedOf("PROBE1G1{$r}2"),
            'perm' => [0, 1, 3, 2, 4],
            'scale' => [1, 1, 1, 1, 1],
            'noise' => 0.0,
        ],
    ];
}
file_put_contents("{$outDir}/g1.json", json_encode($g1, JSON_PRETTY_PRINT));

// G2: 2 задачи ОДНОГО композиционного класса, идентичная perm (слоты!), разные сиды
$g2 = [
    'replicates' => [],
];
for ($r = 1; $r <= 4; $r++) {
    $g2['replicates'][(string) $r] = [
        [
            'id' => 'G2A',
            'class' => 'sdrplusdiff',
            'seed' => $seedOf("PROBE1G2{$r}a"),
            'perm' => [0, 1, 2, 3, 4],
            'scale' => [1, 1, 1, 1, 1],
            'noise' => 0.0,
        ],
        [
            'id' => 'G2B',
            'class' => 'sdrplusdiff',
            'seed' => $seedOf("PROBE1G2{$r}b"),
            'perm' => [0, 1, 2, 3, 4],
            'scale' => [1, 1, 1, 1, 1],
            'noise' => 0.0,
        ],
    ];
}
file_put_contents("{$outDir}/g2.json", json_encode($g2, JSON_PRETTY_PRINT));

// G3: новые экземпляры того же класса (D₃ зафиксирован ДО G2-прогонов) + nulls
$g3 = [
    'replicates' => [],
];
for ($r = 1; $r <= 4; $r++) {
    $g3['replicates'][(string) $r] = [
        [
            'id' => 'H1',
            'class' => 'sdrplusdiff',
            'seed' => $seedOf("PROBE1H{$r}1"),
            'perm' => [0, 1, 2, 3, 4],
            'scale' => [1, 1, 1, 1, 1],
            'noise' => 0.0,
            'null_shuffle' => false,
        ],
        [
            'id' => 'H2',
            'class' => 'sdrplusdiff',
            'seed' => $seedOf("PROBE1H{$r}2"),
            'perm' => [0, 1, 2, 3, 4],
            'scale' => [1, 1, 1, 1, 1],
            'noise' => 0.0,
            'null_shuffle' => false,
        ],
        [
            'id' => 'HN1',
            'class' => 'sdrplusdiff',
            'seed' => $seedOf("PROBE1HN{$r}1"),
            'perm' => [0, 1, 2, 3, 4],
            'scale' => [1, 1, 1, 1, 1],
            'noise' => 0.0,
            'null_shuffle' => true,
        ],
        [
            'id' => 'HN2',
            'class' => 'sdrplusdiff',
            'seed' => $seedOf("PROBE1HN{$r}2"),
            'perm' => [0, 1, 2, 3, 4],
            'scale' => [1, 1, 1, 1, 1],
            'noise' => 0.0,
            'null_shuffle' => true,
        ],
    ];
}
file_put_contents("{$outDir}/g3.json", json_encode($g3, JSON_PRETTY_PRINT));
echo "g1.json + g2.json + g3.json written\n";
echo "Класс СОЗНАТЕЛЬНО композиционный (DEV-гейт механики) — открытая документация.\n";
