<?php

// EXP-045 E2: жёсткое семейство задач («экологическая ниша», ветка (c)
// решения B++ 31.10). ТОЛЬКО dot4-класс: baseline 8s не сертифицирует
// (probe_dot4_baseline 20.10; blFound=false) — plain-поиск не решает,
// дорога получает пространство проявиться. Ceiling-проверка обязательна:
// 0h-чек baseline arm до кампании (ожидание blCert ≈ 0).
// Null-контроли: 2 × permuted-y dot4 (fuse-дисциплина).
// Perm'ы разные → fp-изолированные ниши (clone-only: дорога между
// задачами не переносится — каждая ниша самостоятельна).
// Usage: php gen_e2_families.php <out.json>

$outPath = $argv[1] ?? '';
if ($outPath === '') {
    fwrite(STDERR, "usage: gen_e2_families.php <out.json>\n");
    exit(2);
}

$prefix = 'EXP045E2';
$perms = [
    [0, 1, 2, 3, 4, 5],
    [1, 3, 0, 5, 2, 4],
    [5, 2, 4, 1, 0, 3],
    [3, 0, 1, 4, 5, 2],
    [2, 5, 3, 0, 4, 1],
    [4, 1, 5, 2, 3, 0],
];

$tasks = [];
for ($i = 0; $i < 6; $i++) {
    $id = 'E' . ($i + 1);
    $tasks[] = [
        'id' => $id,
        'class' => 'dot4',
        'seed' => (int) hexdec(substr(md5($prefix . $id), 0, 7)),
        'perm' => $perms[$i],
        'scale' => [1, 1, 1, 1, 1, 1],
        'noise' => 0.0,
    ];
}
for ($i = 0; $i < 2; $i++) {
    $id = 'EN' . ($i + 1);
    $tasks[] = [
        'id' => $id,
        'class' => 'dot4',
        'seed' => (int) hexdec(substr(md5($prefix . 'NULL' . $id), 0, 7)),
        'perm' => $perms[$i],
        'scale' => [1, 1, 1, 1, 1, 1],
        'noise' => 0.0,
        'null_shuffle' => true,
    ];
}

$out = [
    '_prefix' => $prefix,
    '_seal' => false,
    'tasks' => $tasks,
];
file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "e2_fam written: " . count($tasks) . " tasks -> {$outPath}\n";
