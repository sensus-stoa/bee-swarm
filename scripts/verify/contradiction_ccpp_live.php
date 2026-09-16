<?php

declare(strict_types=1);

/**
 * V0.13 WU-5: CCPP live-эксперимент (§1.11) — ПРЕ-РЕГИСТРАЦИЯ ожиданий.
 *
 * Пре-зарегистрированные ожидания (фиксируются ДО прогона, спека WU-5):
 *  (a) детектор сработает на дегенерате (corr −0.947, recurrence 3/3+);
 *  (b) инверсионный ре-поиск найдёт кандидата с |corr(pred, −y)| > 0.7
 *      НО предсказания останутся вне уровня (грамматика не выразит 454 MW);
 *  (c) ИТОГ: ANOMALY, не INVERTED-LAW (грамматика не умеет аффинный уровень —
 *      OLS-класс невыразим). Если (c) нарушится и инверсия даст полный закон —
 *      это ОТКРЫТИЕ (зеркальная форма выразима и мы её не видели).
 *
 * Usage: php contradiction_ccpp_live.php [> log 2>&1]
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Certification\ContradictionEngine;
use BeeSwarm\Certification\EnsembleCertifier;
use BeeSwarm\Certification\MetricPreflight;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

putenv('SWARM_DB_PATH=:memory:');
putenv('FORAGER_SOURCES=:');
putenv('NO_BIRTH=1');
putenv('SEARCH_NO_PREREG=1');
putenv('SEARCH_BEAM_K=0');
putenv('ENSEMBLE_BUDGET_SEC=10');
foreach (['SWARM_DB_PATH', 'FORAGER_SOURCES', 'NO_BIRTH', 'SEARCH_NO_PREREG', 'SEARCH_BEAM_K', 'ENSEMBLE_BUDGET_SEC'] as $k) {
    echo "ENV {$k}=" . (getenv($k) === false ? 'LOST' : getenv($k)) . "\n";
}
echo "START contradiction_ccpp_live " . gmdate('c') . "\n";

$logFile = sys_get_temp_dir() . '/v013_ccpp_live.log';
@unlink($logFile);

// ── ПРЕ-РЕГИСТРАЦИЯ (до прогона) ─────────────────────────────────────────────
$prereg = [
    '(a) детектор сработает на дегенерате: corr -0.947, recurrence 3/3+',
    '(b) инверсия найдёт кандидата с corr(pred, y) > +0.7, но без уровня (не 454 MW)',
    '(c) итог ANOMALY (OLS-класс невыразим); нарушение (c) = ОТКРЫТИЕ (зеркальная форма выразима)',
];
echo "===== ПРЕ-РЕГИСТРАЦИЯ (до прогона) =====\n";
foreach ($prereg as $i => $line) {
    echo $line . "\n";
    file_put_contents($logFile, '[' . gmdate('c') . '] PRE_REG: ' . $line . PHP_EOL, FILE_APPEND);
}

// ── Данные CCPP ──────────────────────────────────────────────────────────────
$raw = file(__DIR__ . '/../../data/CCPP_data.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
array_shift($raw); // header
$rows = [];
foreach ($raw as $line) {
    $v = array_map('floatval', str_getcsv($line));
    if (count($v) === 5) {
        $rows[] = $v;
    }
}
shuffle($rows);
$rows = array_slice($rows, 0, 250);
$X = array_map(static fn (array $r): array => array_slice($r, 0, 4), $rows);
$y = array_column($rows, 4);
echo "\nДанные: " . count($y) . " строк, 4 фичи, CV(y)=" . round(MetricPreflight::cvOfTarget($y) ?? 0, 4) . "\n";

// ── Шаг 1: single-run find (кандидат) ────────────────────────────────────────
echo "\n===== ШАГ 1: single-run find (грамматика найдёт дегенерат) =====\n";
$grammar = new Grammar();
$res = Search::find($X, $y, $grammar, 3, null, 0.2, 0.15, 25, null);
echo 'found=' . var_export($res[0], true) . ' cv=' . round($res[1], 4) . ' ' . $res[2] . "\n";
if (! $res[0]) {
    echo "Кандидат не найден — pre-flight/грамматика отсекла. Эксперимент завершён без противоречия.\n";
    exit(0);
}
$candidate = [
    'atom' => (string) $res[2],
    'found' => true,
    'cv' => (float) $res[1],
    'domain' => 'ccpp',
];
file_put_contents($logFile, '[' . gmdate('c') . '] CANDIDATE ' . $candidate['atom'] . ' cv=' . $candidate['cv'] . PHP_EOL, FILE_APPEND);

// ── Шаг 2: корреляция кандидата с y (sign-ко-гейт факт) ─────────────────────
$stats = \BeeSwarm\Core\ExpressionEvaluator::collectStats($candidate['atom'], $X, [], []);
$pred = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($candidate['atom'], $X, $stats, [], []);
$cg = MetricPreflight::coGates(is_array($pred) ? $pred : [], $y);
$candidate['corr'] = $cg->corr;
echo 'corr(pred, y) = ' . round($cg->corr, 3) . ' sign_ok=' . var_export($cg->sign_ok, true)
    . ' scale_ratio=' . round($cg->scale_ratio, 3) . "\n";

// ── Шаг 3: ensemble-устойчивость (V0.11 короткий) ────────────────────────────
echo "\n===== ШАГ 2: ensemble-устойчивость (k=4, null off) =====\n";
$ens = EnsembleCertifier::certify($X, $y, ['k' => 4, 'depth' => 3, 'test_ratio' => 0.2,
    'budget_sec' => 15.0, 'gate_grid' => [0.05, 0.10, 0.15], 'null_ensembles' => 0, 'log_file' => $logFile]);
echo 'verdict=' . $ens['verdict'] . ' rec=' . round($ens['recurrence'], 3)
    . ' shape=' . ($ens['shape'] ?? '-') . "\n";
$recurrence = $ens['recurrence'];

// ── Шаг 4: детект противоречия ───────────────────────────────────────────────
echo "\n===== ШАГ 3: детект противоречия =====\n";
$verdict = ContradictionEngine::detect($candidate, (object) ['recurrence' => $recurrence], $cg);
echo 'class=' . $verdict->class . ' mirror=' . round($verdict->mirror_strength, 3)
    . ' action=' . $verdict->action . "\n";
echo 'reason/hyp: ' . ($verdict->pre_registered_hypothesis !== '' ? '(см. лог)' : ($verdict->reason ?? '-')) . "\n";

// ── Шаг 5: инверсионный ре-поиск ─────────────────────────────────────────────
if ($verdict->class === 'CONTRADICTION') {
    echo "\n===== ШАГ 4: инверсионный ре-поиск (−y) =====\n";
    $inv = ContradictionEngine::invertAndResearch($X, $y, $candidate, $logFile,
        ['gate' => 0.15, 'depth' => 3, 'budget' => 25.0]);
    echo 'class=' . $inv->class . ' cv_инв=' . ($inv->inverted_cv !== null ? round($inv->inverted_cv, 4) : 'null')
        . ' corr_инв=' . ($inv->inverted_corr !== null ? round($inv->inverted_corr, 3) : 'null') . "\n";
    echo 'verdict: ' . $inv->verdict_line . "\n";
}

// ── Итог против пре-регистрации ──────────────────────────────────────────────
echo "\n===== ИТОГ против пре-регистрации =====\n";
echo "(a) детект: " . ($verdict->class === 'CONTRADICTION' ? 'СРАБОТАЛ ✅' : 'НЕ сработал (' . $verdict->class . ')') . "\n";
echo "(c) итог: " . (($inv->class ?? '-') === 'ANOMALY' ? 'ANOMALY как ожидалось ✅' : (($inv->class ?? '-') === 'INVERTED_LAW' ? 'INVERTED_LAW — ОТКРЫТИЕ (нарушение (c))' : 'не дошло/прочее'));
echo "\nЛог: {$logFile}\n";
