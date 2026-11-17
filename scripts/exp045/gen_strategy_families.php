<?php

// STRATEGY-TRANSFER campaign pool (prereg rev.5, class-table freeze).
// Derived from gen_dev_families.php (FROZEN, sha in prereg MANIFEST) - this
// file ADDS ratio_2/ratio_3 instances (RATIO >= 3 rule, owner edit 2) and
// keeps D1-D6 + DOT4 with the SAME seeds as the frozen generator.
// DN4/DN-nulls excluded from the class table (null controls, not tasks).
// Usage: php gen_strategy_families.php <out.json>

$outPath = $argv[1] ?? '';
if ($outPath === '') {
    fwrite(STDERR, "usage: php gen_strategy_families.php <out.json>\n");
    exit(2);
}

$prefix = 'EXP045DEV';
$classes = ['flux', 'diffprod', 'ratio', 'composition', 'power', 'pureprod'];

$tasks = [];
foreach ($classes as $i => $class) {
    $tasks[] = [
        'id' => 'D' . ($i + 1),
        'class' => $class,
        'seed' => (int) hexdec(substr(md5($prefix . 'D' . ($i + 1)), 0, 7)),
        'perm' => [0, 1, 2, 3, 4],
        'scale' => [1, 1, 1, 1, 1],
        'noise' => 0.0,
    ];
}
// DOT4 (same seed rule as frozen generator)
$tasks[] = [
    'id' => 'DOT4',
    'class' => 'dot4',
    'seed' => (int) hexdec(substr(md5($prefix . 'DOT4'), 0, 7)),
    'perm' => [0, 1, 2, 3, 4, 5],
    'scale' => [1, 1, 1, 1, 1, 1],
    'noise' => 0.0,
];
// RATIO instances 2-3 (fresh seeds, same law class 'ratio') - RATIO >= 3 rule
foreach ([2, 3] as $k) {
    $tasks[] = [
        'id' => 'RATIO' . $k,
        'class' => 'ratio',
        'seed' => (int) hexdec(substr(md5('STRAT' . 'RATIO' . $k), 0, 7)),
        'perm' => [0, 1, 2, 3, 4],
        'scale' => [1, 1, 1, 1, 1],
        'noise' => 0.0,
    ];
}

$out = [
    '_prefix' => $prefix,
    '_class_mapping' => [
        'SUM' => ['power', 'composition', 'dot4'],
        'PRODUCT' => ['pureprod', 'diffprod'],
        'RATIO' => ['ratio', 'flux'],
    ],
    '_benchmarks' => ['dot' => 'SUM', 'kinetic' => 'PRODUCT', 'heat' => 'PRODUCT'],
    'tasks' => $tasks,
];
file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "tasks: " . count($tasks) . "\n";
