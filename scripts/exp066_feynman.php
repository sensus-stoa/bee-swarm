<?php

declare(strict_types=1);

// EXP-066: семья Feynman20 — независимый бенчмарк (pmlb × Feynman SR DB).
// Пререг: Benchmarks/feynman20/PREREG.md. Одна задача = один CSV
// (Benchmarks/feynman20/<name>.csv: x0..x{K-1},y; 200 строк).
// run <name> <arm: A1|A0|L|T> <out.jsonl> [attempt]
// A1: niche=1 + diversity=1 (наш механизм); A0: всё off (голодная);
// L: лотерея, attempt = соль; T: долгий полёт 12000 steps.

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/exp045/cert_utils.php';

use BeeSwarm\Hive\RouteExecutor;

putenv('SWARM_DB_PATH=:memory:');
putenv('TRAIL_BUDGET_MODE=steps');
putenv('TRAIL_BUDGET_STEPS=1500');
putenv('TRAIL_READ_K=5');

$name = (string) ($argv[1] ?? '');
$arm = (string) ($argv[2] ?? 'A1');
$outPath = (string) ($argv[3] ?? '/tmp/exp066_run.jsonl');
$attempt = (int) ($argv[4] ?? 0);

$tasks = json_decode(file_get_contents(__DIR__ . '/../Benchmarks/feynman20/tasks.json'), true);
$task = null;
foreach ($tasks as $t) { if ($t['name'] === $name) { $task = $t; break; } }
if ($task === null) { fwrite(STDERR, "task $name not found\n"); exit(1); }
$seed = (int) $task['seed'];

// Руки — ДО полётов (контракт: флаги не менять между полётами)
if ($arm === 'A1') {
    putenv('TRAIL_ADMIT_NICHE=1');
    putenv('TRAIL_WRITE_DIVERSITY=1');
    putenv('TRAIL_READ_DIVERSITY=1');
} elseif ($arm === 'A0') {
    putenv('TRAIL_ADMIT_NICHE=0');
    putenv('TRAIL_WRITE_DIVERSITY=0');
    putenv('TRAIL_READ_DIVERSITY=0');
} elseif ($arm === 'L') {
    putenv('TRAIL_ADMIT_NICHE=0');
    putenv('TRAIL_WRITE_DIVERSITY=0');
    putenv('TRAIL_READ_DIVERSITY=0');
} elseif ($arm === 'T') {
    putenv('TRAIL_ADMIT_NICHE=1');
    putenv('TRAIL_WRITE_DIVERSITY=1');
    putenv('TRAIL_READ_DIVERSITY=1');
    putenv('TRAIL_BUDGET_STEPS=12000');
}

// Контракт детерминизма: (task, seed, code, budget) → trajectory
mt_srand($seed);
putenv('SEARCH_ROTATION_SEED=' . $seed);

// Данные: первые 200 строк CSV (прегерег B3), колонки x0..x{K-1}
$csvPath = __DIR__ . '/../Benchmarks/feynman20/' . $name . '.csv';
$lines = file($csvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$header = str_getcsv(array_shift($lines));
$xIdx = [];
foreach ($header as $i => $h) { if ($h !== 'y') { $xIdx[$h] = $i; } }
$labels = array_keys($xIdx);
$X = []; $Y = [];
foreach (array_slice($lines, 0, 200) as $line) {
    $v = str_getcsv($line);
    $r = [];
    foreach ($xIdx as $i) { $r[] = (float) $v[$i]; }
    $X[] = $r; $Y[] = (float) $v[count($header) - 1];
}
// B3.3-амендмент: дистрактор-колонки (конкуренция за верхушку, условие
// EXP-065: нерелевантные колонки выдавливают доноров из ranked/пула).
// Детерминированно от seed; диапазон — как у первой релевантной колонки.
$nDist = (int) (getenv('B3_DISTRACTORS') ?: '0');
if ($nDist > 0) {
    $lo = min(array_column($X, 0)); $hi = max(array_column($X, 0));
    foreach ($X as $i => $row) {
        for ($d = 0; $d < $nDist; $d++) {
            $X[$i][] = $lo + (mt_rand() / mt_getrandmax()) * ($hi - $lo);
        }
    }
    $base = count($labels);
    for ($d = 0; $d < $nDist; $d++) { $labels[] = 'x' . ($base + $d); }
}

$nPasses = ($arm === 'T') ? 1 : 8;
$salt = 'f' . $name . '_' . $arm . (($arm === 'L') ? '_t' . $attempt : '');
$certs = 0; $certForm = null; $certPass = null;
$passRows = [];
$t0 = microtime(true);

for ($p = 1; $p <= $nPasses; $p++) {
    $genome = ['id' => 'f_' . substr(sha1($salt . '_' . $p), 0, 8), 'parent_id' => '',
        'condition' => ['kind' => 'ALWAYS'], 'budget_fraction' => 0.30, 'max_steps' => 5,
        'actions' => [
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => (string) (getenv('B3_BEAM') ?: 'beam10nl'),
             'depth' => (int) (getenv('B3_DEPTH') ?: '2')],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => (string) (getenv('B3_BEAM') ?: 'beam10nl'),
             'depth' => (int) (getenv('B3_DEPTH') ?: '2')],
        ]];
    $res = RouteExecutor::run($genome, $X, $Y, $labels, 0.001, 0.10, [], 1);
    $wd = 0; $rd = 0;
    foreach (($res['records'] ?? []) as $rec) {
        if (($rec['op'] ?? '') !== 'FLIGHT') { continue; }
        $trail = $rec['trail'] ?? [];
        foreach (($trail['written'] ?? []) as $w) { if (str_contains((string) $w, '×')) { $wd++; } }
        $rd += count($trail['read_injected'] ?? []);
    }
    $partial = 'none';
    foreach (($res['records'] ?? []) as $rec) {
        if (($rec['op'] ?? '') === 'FLIGHT' && (($rec['trail']['partial_form'] ?? 'none') !== 'none')) {
            $partial = (string) $rec['trail']['partial_form']; break;
        }
    }
    $found = false; $fx = null;
    if (! empty($res['found'])) {
        $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
        $noEval = null;
        $v2 = cert_v2($fx, $X, $Y, $seed + 600, $noEval);
        $found = (is_array($v2) && $v2['pass']);
        if ($found) { $certs = 1; $certForm = is_string($fx) ? $fx : (string) $res['cert_formula']; $certPass = $p; }
    }
    $passRows[] = ['pass' => $p, 'found_cert' => $found ? 1 : 0, 'wd' => $wd, 'rd' => $rd,
        'partial' => $partial, 'cv' => is_numeric($res['best_cv_seen'] ?? null) ? round((float) $res['best_cv_seen'], 4) : null];
    if ($found) { break; }
}

$row = ['exp' => 'exp066', 'task' => $name, 'formula' => $task['formula'],
    'arm' => $arm, 'attempt' => $attempt, 'seed' => $seed, 'certs' => $certs,
    'cert_pass' => $certPass, 'cert_form' => $certForm, 'pass_records' => $passRows,
    'wall_ms' => (int) round((microtime(true) - $t0) * 1000)];
file_put_contents($outPath, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
echo 'EXP066 ' . $name . ' arm=' . $arm . ' certs=' . $certs . ' pass=' . ($certPass ?? '-') . PHP_EOL;
