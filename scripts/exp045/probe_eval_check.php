<?php

declare(strict_types=1);

/**
 * EXP-045 D4 LOOP PROBE — eval-check harness (xdebug trace target).
 * Один маршрут-лестница на dot; после прогона — прямая оценка каждой
 * метки из trail_marks: parse (ExpressionNormalizer) + eval (evaluateFormula).
 * Ответ на вопрос: почему read-путь получает fetched=0 при written=25.
 */

require __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Infra\Database;

putenv('SWARM_DB_PATH=:memory:');
$seed = (int) ($argv[1] ?? 101);

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
    $row = [$u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2)];
    $X[] = $row;
    $y[] = $row[0] * $row[3] + $row[1] * $row[4] + $row[2] * $row[5];
}
$labels = ['c0', 'c1', 'c2', 'c3', 'c4', 'c5'];

$genome = [
    'id' => 'route_' . substr(sha1('probe_eval_check' . $seed), 0, 8),
    'parent_id' => '',
    'condition' => ['kind' => 'ALWAYS'],
    'budget_fraction' => 0.30,
    'max_steps' => 5,
    'actions' => [
        ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
        ['op' => 'USE_MARKS', 'k' => 5],
        ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
    ],
];
$errors = \BeeSwarm\Hive\RouteGenome::validate($genome);
if ($errors !== []) {
    fwrite(STDERR, 'GENOME INVALID: ' . json_encode($errors) . PHP_EOL);
    exit(3);
}

$res = RouteExecutor::run($genome, $X, $y, $labels, 15.0, 0.10, [], 1);
echo 'run done: found=', var_export($res['found'], true), ' wall=', round($res['wall'], 2), PHP_EOL;

$rows = Database::get()
    ->query('SELECT formula, kind, uses FROM trail_marks ORDER BY id')
    ->fetchAll(PDO::FETCH_ASSOC);
echo 'trail_marks rows: ', count($rows), PHP_EOL;

foreach ($rows as $row) {
    $f = $row['formula'];
    $parsed = ExpressionNormalizer::parse($f, []);
    $parseOk = $parsed !== null ? 'OK' : 'PARSE_FAIL';
    $vals = ExpressionEvaluator::evaluateFormula($f, $X);
    if ($vals === null) {
        $eval = 'EVAL_NULL';
    } elseif (! is_array($vals)) {
        $eval = 'EVAL_NOT_ARRAY:' . gettype($vals);
    } else {
        $bad = 0;
        $n = count($vals);
        foreach ($vals as $v) {
            if ($v === null || ! is_finite((float) $v)) {
                $bad++;
            }
        }
        $eval = "OK n={$n} bad={$bad}";
    }
    echo str_pad((string) $row['uses'], 3), ' ', $parseOk, ' ', $eval, '  ', substr($f, 0, 70), PHP_EOL;
}

// Тот же вызов, что в read-пути: первый fetched-кандидат
$fetched = null;
foreach ($res['records'] as $rec) {
    if (($rec['op'] ?? '') === 'FLIGHT') {
        $tr = $rec['trail'] ?? [];
        echo 'flight seq=', $rec['seq'], ' attempts=', var_export($tr['read_attempts'] ?? null, true),
        ' wide=', var_export($tr['read_wide'] ?? null, true),
        ' poolskip=', var_export($tr['read_poolskip'] ?? null, true),
        ' fetched=', count($tr['read_fetched'] ?? []),
        ' injected=', count($tr['read_injected'] ?? []), PHP_EOL;
    }
}
