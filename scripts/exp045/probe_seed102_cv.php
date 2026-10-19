<?php

declare(strict_types=1);

/**
 * EXP-045 сид-102 диагноз (owner GO 19.10, вне критериев шага II):
 * гипотеза 1 (данные: ratio-cv канона взрывается на zero-crossing →
 * закон не сертифицируется) vs гипотеза 2 (композиция: min-мусор в слотах
 * «прочих» вытесняет сборку). Оффлайн, без кампании.
 *
 * CLI: php probe_seed102_cv.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\Search;

// точная копия генератора из probe_loop.php (LCG, детерминизм по сиду)
function dot_task_exact(int $seed): array
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
        $row = [$u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2)];
        $X[] = $row;
        $y[] = $row[0] * $row[3] + $row[1] * $row[4] + $row[2] * $row[5];
    }

    return [$X, $y];
}

$canonical = '((c0×c3)+(c1×c4))+(c2×c5)';
$labels = ['c0', 'c1', 'c2', 'c3', 'c4', 'c5'];
$labelsToX = ['c5' => 'x5', 'c4' => 'x4', 'c3' => 'x3', 'c2' => 'x2', 'c1' => 'x1', 'c0' => 'x0'];
$xform = str_replace(array_keys($labelsToX), array_values($labelsToX), $canonical);

foreach ([101, 102, 103] as $seed) {
    [$X, $y] = dot_task_exact($seed);
    $vec = ExpressionEvaluator::evaluateFormula($xform, $X);
    if ($vec === null) {
        echo "seed $seed: canonical form EVAL_NULL\n";
        continue;
    }
    $cvFull = Search::cv($vec, $y, 0.0);
    $n = count($y);
    $split = (int) ($n * 0.8);
    $cvTrain = Search::cv(array_slice($vec, 0, $split), array_slice($y, 0, $split), 0.0);
    $cvTest = Search::cv(array_slice($vec, $split), array_slice($y, $split), 0.0);
    $cvFirst30 = Search::cv(array_slice($vec, 0, 30), array_slice($y, 0, 30), 0.0);
    // zero-crossing маркер: сколько смен знака в y и отношение |y|max к размаху vec
    $signChanges = 0;
    for ($i = 1; $i < $n; $i++) {
        if ($y[$i - 1] * $y[$i] < 0) {
            $signChanges++;
        }
    }
    // 30-row Pearson канона (гейт stair-сенсора — лестницы должны собираться)
    $gate = 0.15;
    $verdict = ($cvFull < $gate && $cvTrain < $gate && $cvTest < $gate) ? 'CERTIFIABLE' : 'NOT-CERTIFIABLE (гипотеза 1: данные)';
    echo "seed $seed: cv_full=" . round($cvFull, 4) . " cv_train(80%)=" . round($cvTrain, 4)
        . " cv_test(20%)=" . round($cvTest, 4) . " cv_first30=" . round($cvFirst30, 4)
        . " sign_changes_y=$signChanges" . " → $verdict\n";
}
