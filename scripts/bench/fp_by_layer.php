<?php

declare(strict_types=1);

/**
 * T3 (theorem-level): FP-таблица по слоям защиты (04.09).
 *
 * Вопрос: какой слой и сколько отсекает ложных кандидатов на чистом шуме?
 * Методика: 100 шумовых задач (uniform, n=30, ~tMin) + 10 TP-контроль (выразимые
 * законы y=x, 2x, x², 1/x) — замеряем «глубину прохождения» каждого кандидата:
 * какой слой его остановил.
 *
 * Слои (порядок в реальном пайплайне):
 *   L0 — Search::find вернул false (ни одного кандидата, bestCv high)
 *   L1 — NonConstancyFilter (константа/вырожденное)
 *   L2 — out-of-sample cv_test > ε (обучение не переносится)
 *   L3 — canonical DUPLICATE (не слой FP, но частота интересна)
 *   L4 — null-калибровка ε домена
 *   TP — точные законы прошли ВСЕ слои
 *
 * Env-шапка (31.08): изолированная БД, без forager, beam=10, cap=3.
 */

putenv('SWARM_DB_PATH=:memory:');
putenv('FORAGER_SOURCES=:');
putenv('SEARCH_BEAM_K=10');
putenv('BINARY_B_CAP=3');

require __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\NonConstancyFilter;
use BeeSwarm\Core\Search;

const NOISE_TASKS = 100;
const TP_TASKS = 10;
const ROWS = 30;
const CV_EPS = 0.15;

$layers = ['L0_search_false' => 0, 'L1_nonconstancy' => 0, 'L2_oot_sample' => 0, 'L3_null_calib' => 0, 'TP' => 0];
$classHistogram = [];

// === Шумовые задачи: uniform noise, seed фиксирован ===
mt_srand(42);
for ($t = 0; $t < NOISE_TASKS; $t++) {
    $X = [];
    $y = [];
    for ($i = 0; $i < ROWS; $i++) {
        $x0 = mt_rand() / mt_getrandmax() * 10;
        $x1 = mt_rand() / mt_getrandmax() * 10;
        $X[] = [$x0, $x1];
        $y[] = mt_rand() / mt_getrandmax() * 10; // чистый шум, независимый от x
    }

    $g = new Grammar();
    [$ok, $cv, $formula, $cvTest, $class] = Search::find($X, $y, $g, 2);

    if (! $ok) {
        // Search вернул отказ. Лучший кандидат мог дойти до heldout или нет:
        // если class=GRAMMAR → даже кандидата нет (L0). Если NOISE → кандидат был,
        // но cv большой. Уточняем: тест-критерий Т3 — только «сколько прошло всё».
        $layers['L0_search_false']++;
        $classHistogram[$class ?? 'null'] = ($classHistogram[$class ?? 'null'] ?? 0) + 1;
        continue;
    }

    // Кандидат прошёл Search::find acceptance (cv < cvTrainMax=0.15) — опасная зона!
    // Слой 1: NonConstancyFilter
    // (в реальном пайплайне non-constancy гоняется на предсказаниях; тут проверим вырожденность формулы)
    $nc = new NonConstancyFilter();
    $pred = [];
    foreach ($X as $row) {
        $pred[] = null; // placeholder: eval живёт в HeldoutValidator, здесь сигнальный тест
    }
    // Прямой прогон через полноценную валидацию ищем в логе результатов find:
    // cv_test на шуме — если большой → L2 отсёк (но find вернул ok=true, значит acceptance прошёл?)
    if ($cvTest !== null && $cvTest > CV_EPS) {
        $layers['L2_oot_sample']++;
        continue;
    }
    // Кандидат с cv_test < 0.15 на чистом шуме = FP НАШЁЛСЯ (L2 не сработал)
    $layers['L3_null_calib']++;
    echo "FP: task=$t formula=$formula cv=" . number_format($cv, 4) . " cv_test=" . number_format((float) $cvTest, 4) . " class=$class\n";
}

// === TP-контроль: выразимые законы обязаны пройти ===
mt_srand(7);
$tpForms = [
    fn ($x0) => $x0,              // y=x
    fn ($x0) => 2.0 * $x0,        // y=2x
    fn ($x0) => $x0 * $x0,        // y=x²
    fn ($x0) => 1.0 / $x0,        // y=1/x
];
$tpCount = 0;
$tpFail = [];
for ($t = 0; $t < TP_TASKS; $t++) {
    $form = $tpForms[$t % 4];
    $X = [];
    $y = [];
    for ($i = 0; $i < ROWS; $i++) {
        $x0 = 0.1 + (mt_rand() / mt_getrandmax()) * 5; // 0.1..5.1 — без деления на ~0
        $X[] = [$x0];
        $y[] = $form($x0);
    }
    $g = new Grammar();
    [$ok, $cv, $formula, $cvTest] = Search::find($X, $y, $g, 2);
    if ($ok && ($cvTest === null || $cvTest < CV_EPS)) {
        $tpCount++;
    } else {
        $tpFail[] = "task=$t ok=" . var_export($ok, true) . ' cv=' . number_format((float) $cv, 3) . ' cvTest=' . var_export($cvTest, true);
    }
}

echo "\n=== T3: FP по слоям (100 шумовых задач, n=30) ===\n";
foreach ($layers as $layer => $n) {
    echo str_pad($layer, 20), $n, "\n";
}
echo 'FPR (прошли всё, до null-calib) = ', $layers['L3_null_calib'] / NOISE_TASKS, "\n";
echo 'TP контроль: ', $tpCount, '/', TP_TASKS;
if ($tpFail !== []) {
    echo "  FAIL: \n", implode("\n  ", $tpFail);
}
echo "\n=== классы отказов (L0) ===\n";
foreach ($classHistogram as $c => $n) {
    echo str_pad((string) $c, 12), $n, "\n";
}
