#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * verify_1_9.php — WU-5 V0.11: Perturbed Ensemble Certification (§1.9).
 *
 * Калибровочный прогон EnsembleCertifier на 3 классах доменов:
 *  (a) точные законы   → ожидание ENSEMBLE_CERT;
 *  (b) шумовые          → ожидание NO_CONSENSUS (или UNSTABLE);
 *  (c) adversarial CCPP → ожидание UNSTABLE_CERTIFICATE (дегенерат Demo #2).
 *
 * Прогон печатает env-факт в стартовой строке (питфолл 14.09: env-шапка
 * валидируется ФАКТОМ, не предполагается). Бюджет домена = k×budget_sec.
 *
 * Usage: php verify_1_9.php [--quick] [> log 2>&1]
 *   --quick: k=3, budget 5s, null 2x2 — смок, ~3 мин/домен.
 *   боевой:  k=5..10, budget 15s, null 2x3 — ~20-30 мин/домен.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Certification\EnsembleCertifier;
use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Core\LawShape;

// ── env-шапка bench-класса + факт в стартовую строку ─────────────────────────
putenv('SWARM_DB_PATH=:memory:');
putenv('FORAGER_SOURCES=:');
putenv('NO_BIRTH=1');
putenv('SEARCH_NO_PREREG=1');
putenv('SEARCH_BEAM_K=0'); // beam срезает родителей compose (проба 16.09)
putenv('ENSEMBLE_BUDGET_SEC=' . (in_array('--quick', $argv, true) ? '5' : '15'));

$envKeys = ['SWARM_DB_PATH', 'FORAGER_SOURCES', 'NO_BIRTH', 'SEARCH_NO_PREREG', 'SEARCH_BEAM_K', 'ENSEMBLE_BUDGET_SEC'];
foreach ($envKeys as $k) {
    echo "ENV {$k}=" . (getenv($k) === false ? 'LOST' : getenv($k)) . "\n";
}
echo "START verify_1_9 " . gmdate('c') . "\n";

/** @return array{X: list<list<float>>, y: list<float>, labels: list<string>} */
function loadCsv(string $path, int $targetCol, int $maxRows): array
{
    $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($raw === false) {
        throw new RuntimeException("csv not readable: {$path}");
    }
    $first = str_getcsv($raw[0]);
    $hasHeader = ! is_numeric((string) $first[0]);
    if ($hasHeader) {
        array_shift($raw);
        $labels = $first;
    } else {
        $labels = array_map(fn (int $i): string => "x{$i}", range(0, count($first) - 1));
    }
    $rows = [];
    foreach ($raw as $line) {
        $v = array_map('floatval', str_getcsv($line));
        if (count($v) === count($labels)) {
            $rows[] = $v;
        }
    }
    shuffle($rows);
    $rows = array_slice($rows, 0, $maxRows);
    $X = [];
    $y = [];
    foreach ($rows as $r) {
        $y[] = $r[$targetCol];
        unset($r[$targetCol]);
        $X[] = array_values($r);
    }
    // H-мусор CR (coulomb \r\n): floatval строк с \r → 0 → колонки-нули.
    // labels чистим от \r и не-словесных хвостов; empty labels = x{i}.
    $labels = array_map(
        fn ($l, $i) => ($l !== '' && preg_match('/[\w]/u', (string) $l) === 1)
            ? trim((string) $l) : "x{$i}",
        $labels,
        array_keys($labels),
    );

    return ['X' => $X, 'y' => $y, 'labels' => $labels];
}

/** Прогон одного домена → строка вердикта. */
function runDomain(string $name, string $path, int $targetCol, int $maxRows, bool $quick, ?string $note = null): array
{
    $d = loadCsv($path, $targetCol, $maxRows);
    $cfg = $quick
        ? ['k' => 3, 'depth' => 3, 'test_ratio' => 0.2, 'budget_sec' => 20.0,
            'gate_grid' => [0.05, 0.10, 0.15], 'null_ensembles' => 2, 'null_k' => 2]
        : ['k' => 8, 'depth' => 3, 'test_ratio' => 0.2, 'budget_sec' => 25.0,
            'gate_grid' => [0.05, 0.075, 0.10, 0.125, 0.15], 'null_ensembles' => 2, 'null_k' => 3];
    $cfg['log_file'] = sys_get_temp_dir() . "/v011_wu5_{$name}.log";

    $t0 = microtime(true);
    $out = EnsembleCertifier::certify($d['X'], $d['y'], $cfg);
    $wall = round(microtime(true) - $t0, 1);

    // anchor-семантика: shape кандидата (если консенсус есть) для читателя
    $topFormula = $out['members'][0]['formula'] ?? null;

    return [
        'name' => $name,
        'note' => $note,
        'n' => count($d['y']),
        'verdict' => $out['verdict'],
        'shape' => $out['shape'],
        'recurrence' => round($out['recurrence'], 3),
        'found_n' => $out['found_n'],
        'k' => $out['k'],
        'cv_h' => $out['ensemble_cv_h'] !== null ? round($out['ensemble_cv_h'], 4) : null,
        'null_max' => $out['null_max_recurrence'] !== null ? round($out['null_max_recurrence'], 3) : null,
        'anchor' => isset($out['ensemble_anchor']) && $out['ensemble_anchor'] !== null
            ? round($out['ensemble_anchor']['m_hat'], 4) : null,
        'top_formula' => $topFormula !== null ? ExpressionNormalizer::normalize((string) $topFormula) : null,
        'wall_sec' => $wall,
        'log' => $cfg['log_file'],
    ];
}

$quick = in_array('--quick', $argv, true);
// Пробы 16.09: dot find wall линейно от n (n=150:16s, 200:20.6s, 250:25.3s
// при budget 25). maxRows=250 при budget 14 = TIMEOUT → все точные законы
// NO_CONSENSUS. Рабочий профиль смока: n=200 (как Demo #3), budget 20s.
$maxRows = 200;
$D = __DIR__ . '/../../data';

$cases = [
    // ── (a) точные законы: ENSEMBLE_CERT ──
    ['feynman_dot', "{$D}/feynman_dot_product.csv", 6, 'Demo#3 эталон, точный закон (target=col6)'],
    ['feynman_kinetic', "{$D}/feynman_kinetic_energy.csv", 4, 'точный закон (target=col4)'],
    // ── (b) закон + шум: форма переоткрывается → ENSEMBLE_CERT легитимен ──
    ['kinetic_noise5', "{$D}/feynman_kinetic_energy_noise5.csv", 4, 'закон + 5% шума (cv~0.05)'],
    ['coulomb_noise15', "{$D}/feynman_coulomb_noise15.csv", 3, 'закон + 15% шума (target=col3)'],
    // ── (c) эмпирика/дегенерат: NO_CONSENSUS / UNSTABLE ──
    ['airfoil', "{$D}/airfoil_selfnoise.csv", 5, 'эмпирика, не закон'],
    ['ccpp_degenerate', "{$D}/CCPP_data.csv", 4, 'Demo#2 дегенерат −65MW'],
];

$results = [];
foreach ($cases as [$name, $path, $target, $note]) {
    if (! is_file($path)) {
        echo "SKIP {$name}: нет файла {$path}\n";
        continue;
    }
    echo "RUN {$name} ({$note})...\n";
    try {
        $results[$name] = runDomain($name, $path, $target, $maxRows, $quick, $note);
    } catch (Throwable $e) {
        echo "ERROR {$name}: " . $e->getMessage() . "\n";
        $results[$name] = ['name' => $name, 'verdict' => 'ERROR', 'note' => $e->getMessage()];
    }
}

// ── Сводка + гейты ────────────────────────────────────────────────────────────
echo "\n===== SUMMARY verify_1_9 =====\n";
printf("%-18s %-22s %-5s %-6s %-7s %-7s %s\n", 'domain', 'verdict', 'rec', 'cvH', 'nullMax', 'anchor', 'note');
$expectCert = ['feynman_dot', 'feynman_kinetic', 'kinetic_noise5', 'coulomb_noise15'];
$expectRefuse = ['airfoil', 'ccpp_degenerate'];
$failures = [];
foreach ($results as $r) {
    printf("%-18s %-22s %-5s %-6s %-7s %-7s %s\n",
        $r['name'], $r['verdict'] ?? '?',
        $r['recurrence'] ?? '-', $r['cv_h'] ?? '-', $r['null_max'] ?? '-', $r['anchor'] ?? '-',
        $r['note'] ?? '');
    $v = $r['verdict'] ?? 'ERROR';
    if (in_array($r['name'], $expectCert, true) && $v !== 'ENSEMBLE_CERT') {
        $failures[] = "{$r['name']}: ожидался ENSEMBLE_CERT, получен {$v}";
    }
    if (in_array($r['name'], $expectRefuse, true) && in_array($v, ['ENSEMBLE_CERT'], true)) {
        $failures[] = "{$r['name']}: шум/дегенерат получил ENSEMBLE_CERT — провал гейта";
    }
}

echo "\n===== VERDICT =====\n";
if ($failures === []) {
    echo "PASS: точные законы сертифицированы, шум и дегенерат отклонены\n";
    exit(0);
}
echo 'FAIL: ' . count($failures) . " гейт-провалов:\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
echo "STOP-правило §1.9: если ложные отказы >5% на шумных доменах — порог рецидива параметризуется (env, не хардкод).\n";
exit(1);
