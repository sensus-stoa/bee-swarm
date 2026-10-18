<?php

// EXP-045: генератор семейств задач.
// DEV (уже раскрытые задачи, диагностика): сиды от префикса EXP045DEV.
// --seal: запечатанный набор для подтверждающей фазы C (префикс EXP045H,
// содержимое не печатается, файл запечатывается манифестом). H1-H6 из
// EXP-044 СГОРЕЛИ (просмотрены в фазе C) — не переиспользуются.
// Usage: php gen_dev_families.php <out.json> [--seal]

$outPath = $argv[1] ?? '';
$seal = in_array('--seal', $argv, true);
if ($outPath === '') {
    fwrite(STDERR, "usage: gen_dev_families.php <out.json> [--seal]\n");
    exit(2);
}

$prefix = $seal ? 'EXP045H' : 'EXP045DEV';
// Классы = D-классы EXP-044 (переиспользуется генератор драйвера).
$classes = ['flux', 'diffprod', 'ratio', 'composition', 'power', 'pureprod'];

$tasks = [];
foreach ($classes as $i => $class) {
    $id = ($seal ? 'H' : 'D') . ($i + 1);
    $tasks[] = [
        'id' => $id,
        'class' => $class,
        'seed' => (int) hexdec(substr(md5($prefix . $id), 0, 7)),
        'perm' => [0, 1, 2, 3, 4],
        'scale' => [1, 1, 1, 1, 1],
        'noise' => 0.0,
    ];
}

// Null-контроли: permuted y (frozen сиды), по 2 на прогон arm-seed.
for ($i = 0; $i < 2; $i++) {
    $id = ($seal ? 'HN' : 'DN') . ($i + 1);
    $tasks[] = [
        'id' => $id,
        'class' => $classes[$i % count($classes)],
        'seed' => (int) hexdec(substr(md5($prefix . 'NULL' . $id), 0, 7)),
        'perm' => [0, 1, 2, 3, 4],
        'scale' => [1, 1, 1, 1, 1],
        'noise' => 0.0,
        'null_shuffle' => true,
    ];
}

$out = [
    '_prefix' => $prefix,
    '_seal' => $seal,
    'tasks' => $tasks,
];
file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo ($seal ? 'SEALED' : 'DEV') . ' families written: ' . $outPath . ' (' . count($tasks) . " tasks)\n";
if ($seal) {
    echo "ЗАПЕЩАТАНО: содержимое не печатать, не прогонять до фазы C (prereg freeze).\n";
}
