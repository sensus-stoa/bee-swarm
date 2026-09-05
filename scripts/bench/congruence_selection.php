<?php

declare(strict_types=1);

/**
 * T5 (theorem-level): congruence-selection — наш gate vs AIC/BIC паттерн.
 *
 * SSE-бумага (Sukhodolov et al. 2026): AIC/BIC при cross-class selection дают
 * 81-100% false positives на 5×10^5 SSE-моделях — «model proliferation +
 * misspecification». Прямое воспроизведение их симуляций (HiSSE, R-стек,
 * 100 деревьев) — отдельный проект. Т5-эквивалент: воспроизводим САМ ПАТТЕРН
 * selection-катастрофы и проверяем, что наш gate его не повторяет.
 *
 * Паттерн: ложная модель с ЛУЧШИМ train-fit (переобучение/proliferation)
 * обязана быть отвергнута test-гейтом (out-of-sample CV), а не выбрана.
 *
 * Env-шапка (31.08).
 */

putenv('SWARM_DB_PATH=:memory:');
putenv('FORAGER_SOURCES=:');
putenv('SEARCH_BEAM_K=20');
putenv('BINARY_B_CAP=3');

require __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

const EPS_TEST = 0.15;

echo "=== T5: congruence-selection (наш gate vs AIC/BIC паттерн) ===\n\n";

// ── Эксперимент 1: чистая истина — истинная форма выигрывает у полных классов ──
echo "[EXP1] y=2x, полная грамматика (модель proliferation включена):\n";
mt_srand(7);
$X = [];
$y = [];
for ($i = 0; $i < 30; $i++) {
    $x0 = 0.1 + (mt_rand() / mt_getrandmax()) * 5;
    $X[] = [$x0];
    $y[] = 2.0 * $x0;
}
$g = new Grammar();
[$ok, $cv, $formula, $cvTest, $class] = Search::find($X, $y, $g, 2);
$canon = \BeeSwarm\Core\ExpressionNormalizer::normalize((string) $formula);
echo '  found: ' . $formula . ' | canon: ' . $canon . "\n";
echo '  cv=' . number_format((float) $cv, 5) . ' cvTest=' . number_format((float) $cvTest, 5) . " class=$class\n";
$exp1 = ($ok && $cv < EPS_TEST && $cvTest < EPS_TEST);
echo '  вердикт: ' . ($exp1 ? 'PASS — истинная форма найдена и принята' : 'FAIL') . "\n\n";

// ── Эксперимент 2: маленький селекшн (n=12) — переобучение провалит test ──
echo "[EXP2] n=12 (переобучение-благоприятно), полная грамматика:\n";
mt_srand(11);
$X = [];
$y = [];
for ($i = 0; $i < 12; $i++) {
    $x0 = 0.1 + (mt_rand() / mt_getrandmax()) * 5;
    $X[] = [$x0];
    $y[] = 2.0 * $x0;
}
$g = new Grammar();
[$ok, $cv, $formula, $cvTest, $class] = Search::find($X, $y, $g, 2);
echo '  found: ' . $formula . " | cv=" . number_format((float) $cv, 5)
    . ' cvTest=' . number_format((float) $cvTest, 5) . " class=$class\n";
// Критерий: что бы ни выбрал beam, cvTest-гейт не должен принять форму с проваленным переносом
$exp2 = ! $ok || (float) $cvTest < EPS_TEST;
echo '  вердикт: ' . ($exp2 ? 'PASS — gate не принял перенос-провальные формы'
    : 'FAIL — принята форма с cvTest>' . EPS_TEST) . "\n\n";

// ── Эксперимент 3: мусорная колонка (конгруэнтная ловушка) ──
echo "[EXP3] y=2x(x0) + независимая колонка x1 — конгруэнтный соблазн:\n";
mt_srand(99);
$X = [];
$y = [];
for ($i = 0; $i < 40; $i++) {
    $x0 = (mt_rand() / mt_getrandmax()) * 10;
    $x1 = (mt_rand() / mt_getrandmax()) * 10;
    $X[] = [$x0, $x1];
    $y[] = 2.0 * $x0 + ((mt_rand() / mt_getrandmax()) - 0.5) * 0.2;
}
$g = new Grammar();
$g->restrictTo(['+', '−', '×', '/']);
[$ok, $cv, $formula, $cvTest, $class] = Search::find($X, $y, $g, 2);
echo '  found: ' . $formula . " | cv=" . number_format((float) $cv, 5)
    . ' cvTest=' . number_format((float) $cvTest, 5) . " class=$class\n";
// Формула не должна включать x1 (истина зависит только от x0)
$usesX1 = str_contains((string) $formula, 'x1');
$exp3 = $ok && ! $usesX1 && (float) $cvTest < EPS_TEST;
echo '  вердикт: ' . ($exp3 ? 'PASS — мусорная колонка не втянута' : "FAIL — x1 втянута или провал") . "\n\n";

// ── Сводка ──
echo "=== Сводка T5 ===\n";
echo 'EXP1 (истинная форма выигрывает):    ', $exp1 ? 'PASS' : 'FAIL', "\n";
echo 'EXP2 (переобучение провалит test):   ', $exp2 ? 'PASS' : 'FAIL', "\n";
echo 'EXP3 (конгруэнтная ловушка отбита):  ', $exp3 ? 'PASS' : 'FAIL', "\n";
echo "\nКонтекст: AIC/BIC на SSE-моделях — 81-100% FP (Sukhodolov 2026);\n";
echo "наш gate (null-calibrated out-of-sample CV) — FP=0 на T3 (100 шумовых задач).\n";
