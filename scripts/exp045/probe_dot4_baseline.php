<?php

declare(strict_types=1);

/**
 * EXP045-0h (флаг owner): baseline DOT4 в конфигурации driver (2-3 полёта,
 * budget 8s = BASELINE_SHARE 0.2 × 40s, полная BASE_OPS грамматика, depth
 * эскалация 2→3). Вопрос: сертифицирует ли baseline DOT4?
 *   FAILS  → DOT4 headroom → кампания тестирует stigmergic selection
 *   FINDS  → DOT4 saturated → A-PASS-plain, claim «meta-search transfer»
 * Реплика baseline_flights вербатим, labels c0..c5, данные DOT4 (LCG 101).
 */

require __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Core\RouteExecutor as RE; // computeL живёт тут? нет — ниже прямой вызов

function labelsToX(string $f, array $labels): string
{
    $x = $f;
    foreach (array_reverse($labels) as $i => $c) {
        $x = str_replace($c, 'x' . $i, $x);
    }
    return $x;
}

// DOT4 данные (LCG 101 — тот же генератор, что probe_loop dot_task 4-продукт)
$s = 101;
$r = function () use (&$s): float {
    $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;
    return $s / 0x7FFFFFFF;
};
$X = [];
$y = [];
for ($i = 0; $i < 200; $i++) {
    $row = [];
    for ($j = 0; $j < 6; $j++) {
        $row[] = round($r(), 6);
    }
    $X[] = $row;
    // DOT4: c0·c3 + c1·c4 + c2·c5 + c3·c5 (как probe_loop dot4_task)
    $y[] = $row[0] * $row[3] + $row[1] * $row[4] + $row[2] * $row[5] + $row[3] * $row[5];
}
$labels = ['c0', 'c1', 'c2', 'c3', 'c4', 'c5'];
$n = count($y);
$cut = (int) floor($n * 0.8);
$Xe = array_slice($X, $cut);
$ye = array_slice($y, $cut);

$budget = 8.0; // BASELINE_SHARE 0.2 × TASK_BUDGET 40
$t0 = microtime(true);
$flights = [];
$found = false;
$formula = '';
$bestCv = 9.99;
$g = Grammar::fromOps(array_keys(Grammar::BASE_OPS));
for ($f = 1; $f <= 3; $f++) {
    $remain = $budget - (microtime(true) - $t0);
    if ($remain < 2.0) {
        break;
    }
    putenv('TRAIL_MARKS');
    $depth = min(2 + $f - 1, 3);
    $res = Search::find($X, $y, $g, $depth, $labels, 0.2, 0.10, $remain);
    $flights[] = [
        'f' => $f,
        'depth' => $depth,
        'found' => (bool) $res[0],
        'cv' => is_numeric($res[1]) ? (float) $res[1] : 9.99,
        'formula' => is_string($res[2] ?? null) ? (string) $res[2] : '',
    ];
    if ($res[0]) {
        $found = true;
        $formula = (string) $res[2];
        $bestCv = 0.0;
        break;
    }
    if (is_numeric($res[1]) && (float) $res[1] < $bestCv) {
        $bestCv = (float) $res[1];
    }
}

$wall = round(microtime(true) - $t0, 2);
echo "wall={$wall}s flights=" . count($flights) . " found=" . var_export($found, true) . " bestCv={$bestCv}\n";
foreach ($flights as $fl) {
    echo "  f{$fl['f']} d{$fl['depth']}: found=" . var_export($fl['found'], true)
        . " cv=" . round($fl['cv'], 4) . " formula=" . ($fl['formula'] ?: '-') . "\n";
}
// Сертификация как в драйвере: found ∧ cv_eval ≤ 0.10 на 20% eval-срезе
if ($found) {
    $fx = labelsToX($formula, $labels);
    $rcv = \BeeSwarm\Core\RouteExecutor::computeL($fx, $Xe, $ye);
    $cert = $rcv !== null && $rcv <= 0.10;
    echo "cv_eval=" . var_export($rcv, true) . " → CERTIFIED=" . var_export($cert, true) . "\n";
} else {
    echo "CERTIFIED=false (не найдено)\n";
}
