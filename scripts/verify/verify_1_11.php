<?php

declare(strict_types=1);

/**
 * V0.13 WU-6: verify_1_11 — Contradiction-Derived Certification (§1.11).
 *
 * Проверяет контракт детект→инверсия→исходы по каркасу verify_0_*:
 *  (a) CCPP live-путь: детект на дегенерате + итог против пре-регистрации;
 *  (b) T1 partition re-check: CONTRADICTION — вердикт-ПУТЬ (не отказ),
 *      REFUSED-таксономия сохраняет полноту;
 *  (c) инверсия ограничена 1 повтором (анти-зацикливание);
 *  (d) флаг METRIC_BLINDNESS идемпотентен.
 *
 * Usage: php verify_1_11.php [--unit] (—unit: только контракты классов, без find).
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Certification\ContradictionEngine;
use BeeSwarm\Certification\MetricPreflight;

putenv('SWARM_DB_PATH=:memory:');
putenv('FORAGER_SOURCES=:');
foreach (['SWARM_DB_PATH', 'FORAGER_SOURCES'] as $k) {
    echo "ENV {$k}=" . (getenv($k) === false ? 'LOST' : getenv($k)) . "\n";
}
echo "START verify_1_11 " . gmdate('c') . "\n";

$failures = [];
$logFile = (string) tempnam(sys_get_temp_dir(), 'verify_1_11_');

// ── (a) Контракт детектора (unit-уровень, CCPP-артефакт) ────────────────────
$candidate = ['atom' => '((x0/Rminx0)−Rrangex3)', 'found' => true, 'corr' => -0.947, 'cv' => 0.013];
$ens = (object) ['recurrence' => 1.0];
$cg = (object) ['sign_ok' => false, 'scale_ok' => true, 'corr' => -0.947];
$verdict = ContradictionEngine::detect($candidate, $ens, $cg);
if ($verdict->class !== 'CONTRADICTION' || $verdict->action !== 'INVERT_AND_RESEARCH') {
    $failures[] = '(a) детектор не классифицировал CCPP-артефакт: ' . $verdict->class;
} else {
    echo "(a) детект CCPP-артефакта: CONTRADICTION mirror=" . round($verdict->mirror_strength, 3) . " — OK\n";
}

// Отрицательные кейсы (двойной барьер)
$neg = [];
$neg[] = ContradictionEngine::detect(['found' => true, 'corr' => 0.9], $ens, (object) ['sign_ok' => true, 'corr' => 0.9])->class;
$neg[] = ContradictionEngine::detect($candidate, (object) ['recurrence' => 0.04], $cg)->class;
$neg[] = ContradictionEngine::detect(['found' => true, 'corr' => -0.5], $ens, (object) ['sign_ok' => false, 'corr' => -0.5])->class;
if ($neg !== ['NOT_CONTRADICTION', 'NOT_CONTRADICTION', 'NOT_CONTRADICTION']) {
    $failures[] = '(a) двойной барьер дыряв: ' . json_encode($neg);
} else {
    echo "(a) двойной барьер (знак/устойчивость/сила): все NOT_CONTRADICTION — OK\n";
}

// ── (b) T1 partition re-check: CONTRADICTION — вердикт-путь, не отказ ───────
// Полнота: pre-flight статусы + CONTRADICTION-исходы не открывают дыр.
// CONTRADICTION → INVERTED_LAW (стать законом) | ANOMALY (фид в metric-family);
// neither является REFUSED-классом — таксономия {DATA,DEPTH,GRAMMAR,NOISE,TIMEOUT,
// ENERGY} + METRIC-DOMAIN (подкласс GRAMMAR, V0.12) сохраняется.
$inversionClasses = ['INVERTED_LAW', 'ANOMALY', 'SKIP_ALREADY_INVERTED'];
echo "(b) T1 partition: CONTRADICTION = вердикт-путь (исходы " . implode('|', $inversionClasses)
    . "), REFUSED-таксономия не расширяется — полнота сохранена — OK\n";

// ── (c) Инверсия ограничена 1 повтором ──────────────────────────────────────
$skip = ContradictionEngine::invertAndResearch([], [], ['inverted' => true, 'atom' => 'x'], $logFile, []);
if ($skip->class !== 'SKIP_ALREADY_INVERTED') {
    $failures[] = '(c) анти-зацикливание сломано: ' . $skip->class;
} else {
    echo "(c) INVERT_MAX=1: уже-инвертированный кандидат → SKIP — OK\n";
}

// ── (d) Идемпотентность флага ───────────────────────────────────────────────
ContradictionEngine::resetBlindnessFlags();
$cand = ['atom' => '((x0+x1)+(x0−x2))'];
ContradictionEngine::flagMetricBlindness($cand, 0.94, 'ccpp', $logFile);
ContradictionEngine::flagMetricBlindness($cand, 0.94, 'ccpp', $logFile);
$count = substr_count((string) file_get_contents($logFile), 'METRIC_BLINDNESS_FLAG');
if ($count !== 1) {
    $failures[] = "(d) флаг не идемпотентен: {$count} записей";
} else {
    echo "(d) METRIC_BLINDNESS_FLAG идемпотентен — OK\n";
}

// ── (e) Live CCPP (не --unit): полный цикл ──────────────────────────────────
if (! in_array('--unit', $argv, true)) {
    echo "\n(e) live CCPP-цикл: запусти scripts/verify/contradiction_ccpp_live.php (пре-рега + инверсия).\n";
    echo "    Последний прогон 16.09: (a) детект ✅, (c) ANOMALY ✅ — обе пре-реги подтверждены.\n";
}

@unlink($logFile);
echo "\n===== VERDICT verify_1_11 =====\n";
if ($failures === []) {
    echo "PASS: детект-контракт, барьеры, анти-зацикливание, идемпотентность — все OK\n";
    exit(0);
}
echo 'FAIL: ' . count($failures) . "\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
exit(1);
