<?php

// PROBE-0: single-flight subprocess runner (изоляция env + захват подсчёта кандидатов).
// Usage: php flight.php <config.json>
// stdout: JSON {found, cv, formula, cv_test, class, diagnosis, wall, mem_peak}
// stderr: Search [SD] debug (exprs= / plausible=) — парсит драйвер.

$config = json_decode(file_get_contents($argv[1] ?? ''), true);
if (! is_array($config)) {
    fwrite(STDERR, "bad config\n");
    exit(2);
}

// Среда вылета: регистр компонента = grammar_ops файла БД этой руки.
putenv('SWARM_DB_PATH=' . $config['db_path']);
// NO_BIRTH НЕ ставится: NO_BIRTH=1 гасит чтение bornBinary в Search —
// зарегистрированный компонент стал бы невидим для пчёл (дефект пойман
// на DEV-инвариантах 14.10). Рождение новых атомов в тесте невозможно
// и без флага: recordDiscovery/compress из direct-Search не вызываются.
putenv('TRAIL_MARKS');           // стигмергия вне скоупа PROBE-0
putenv('SEARCH_NO_PREREG=1');    // без prereg-записей (чистые подсчёты кандидатов)
putenv('SEARCH_DEBUG=1');        // [SD] exprs=N plausible=M → stderr
require $config['autoload'];

$t0 = microtime(true);
$g = new BeeSwarm\Core\Grammar();
// База + регистр этой руки (restrictTo фильтрует инстанс; определения
// зарегистрированных атомов уже загружены ctor'ом из grammar_ops файла).
$g->restrictTo(array_merge($config['ops'], $config['register_ops'] ?? []));

[$X, $y] = $config['data'];
$r = BeeSwarm\Core\Search::find(
    $X, $y, $g,
    (int) $config['depth'],
    $config['labels'],
    (float) ($config['test_ratio'] ?? 0.2),
    (float) ($config['eps'] ?? 0.10),
    (float) $config['budget_s'],
);

echo json_encode([
    'found' => (bool) $r[0],
    'cv' => is_numeric($r[1]) ? (float) $r[1] : 9.99,
    'formula' => is_string($r[2] ?? null) ? (string) $r[2] : 'none',
    'cv_test' => is_numeric($r[3] ?? null) ? (float) $r[3] : 9.99,
    'class' => is_string($r[4] ?? null) ? (string) $r[4] : 'NONE',
    'diagnosis' => is_string($r[5] ?? null) ? (string) $r[5] : null,
    'wall' => round(microtime(true) - $t0, 3),
    'mem_peak' => memory_get_peak_usage(true),
]);
