<?php
declare(strict_types=1);

/**
 * Диагностика достижимости CV→0 на реальных метриках (01.08.2026).
 *
 * Эксперты №1/№2/№3: ПЕРЕД расширением грамматики проверить данные.
 * Вопросы:
 *  1. Есть ли линейная структура (corr, R²)?
 *  2. Какой min CV достижим простейшими выражениями? (если > 0.3 — CV≤0.01 недостижим)
 *  3. Есть ли лаговая структура (x[i−k] → y[i], k=1..7)?
 *  4. ACF(y) — автокорреляция цели (временная структура)?
 *
 * Ноль изменений в src/ — чистое чтение.
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Forager\DataSelfGenerator;
use BeeSwarm\Math\CvCalculator;

function pearson(array $x, array $y): float
{
    $n = count($x);
    if ($n < 3) return 0.0;
    $mx = array_sum($x) / $n;
    $my = array_sum($y) / $n;
    $sxy = 0.0; $sxx = 0.0; $syy = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $sxy += ($x[$i] - $mx) * ($y[$i] - $my);
        $sxx += ($x[$i] - $mx) ** 2;
        $syy += ($y[$i] - $my) ** 2;
    }
    if ($sxx < 1e-12 || $syy < 1e-12) return 0.0;
    return $sxy / sqrt($sxx * $syy);
}

function linregRsq(array $x, array $y): float
{
    $n = count($x);
    if ($n < 3) return 0.0;
    $mx = array_sum($x) / $n;
    $my = array_sum($y) / $n;
    $sxy = 0.0; $sxx = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $sxy += ($x[$i] - $mx) * ($y[$i] - $my);
        $sxx += ($x[$i] - $mx) ** 2;
    }
    if ($sxx < 1e-12) return 0.0;
    $b = $sxy / $sxx;
    $a = $my - $b * $mx;
    $sst = 0.0; $sse = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $sst += ($y[$i] - $my) ** 2;
        $sse += ($y[$i] - ($a + $b * $x[$i])) ** 2;
    }
    return $sst < 1e-12 ? 0.0 : 1.0 - $sse / $sst;
}

function acf(array $v, int $k): float
{
    $n = count($v);
    if ($n <= $k + 2) return 0.0;
    $x = array_slice($v, 0, $n - $k);
    $y = array_slice($v, $k);
    return pearson($x, $y);
}

// === 1. Задачи — те же, что видит система ===
$gen = new DataSelfGenerator();
$tasks = $gen->fromMetrics();
echo "Задач из metrics: " . count($tasks) . "\n\n";

// Сырые строки с датами — для лагов
$metricsPath = '~/ninjacat/Documents/the_lair/ExoCortex/Journal/global/metrics.jsonl';
if (! file_exists($metricsPath)) {
    $metricsPath = __DIR__ . '/../data/metrics.jsonl';
}
$raw = [];
foreach (file($metricsPath) as $l) {
    if (trim($l)) $raw[] = json_decode(trim($l), true);
}
usort($raw, fn ($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));
echo "Сырых записей (по датам): " . count($raw) . "\n\n";

// === 2. По каждой задаче ===
echo str_pad("задача", 28) . str_pad("n", 5) . str_pad("corr", 8) . str_pad("R²", 8) . str_pad("minCV", 8) . "  кандидат\n";
echo str_repeat("─", 80) . "\n";

$rows = [];
foreach ($tasks as $t) {
    $pairs = $t['data'];
    $x = array_column($pairs, 0);
    $y = array_column($pairs, 1);
    $n = count($x);

    $c = pearson($x, $y);
    $r2 = linregRsq($x, $y);

    // Простейшие кандидаты — тот же CvCalculator, что у системы
    $candidates = [
        'y/x'   => fn ($xi, $yi) => $xi != 0 ? $yi / $xi : 9.99,
        'x'     => fn ($xi, $yi) => $xi,
        'y−x'   => fn ($xi, $yi) => $yi - $xi,
        'y/x²'  => fn ($xi, $yi) => ($xi != 0) ? $yi / ($xi * $xi) : 9.99,
        'y/√x'  => fn ($xi, $yi) => $xi > 0 ? $yi / sqrt($xi) : 9.99,
        'x+y'   => fn ($xi, $yi) => $xi + $yi,
    ];
    $bestCv = 9.99;
    $bestName = '—';
    foreach ($candidates as $name => $fn) {
        $vec = [];
        $ok = true;
        foreach ($pairs as $p) {
            $v = $fn($p[0], $p[1]);
            if ($v === 9.99 || is_nan($v) || is_infinite($v)) { $ok = false; break; }
            $vec[] = (float) $v;
        }
        if (! $ok) continue;
        $cv = CvCalculator::compute($vec, $y);
        if ($cv < $bestCv) { $bestCv = $cv; $bestName = $name; }
    }

    // Константа как baseline (mean)
    $mean = array_sum($y) / $n;
    $cvConst = CvCalculator::compute(array_fill(0, $n, $mean), $y);

    $rows[] = [$t['name'], $n, $c, $r2, $bestCv, $bestName, $cvConst];
    printf("%-28s %-5d %-8.3f %-8.3f %-8.3f  %s (const CV=%.3f)\n",
        $t['name'], $n, $c, $r2, $bestCv, $bestName, $cvConst);
}

// === 3. Сводка ===
echo "\n=== СВОДКА ===\n";
$strong = array_filter($rows, fn ($r) => abs($r[2]) > 0.3);
$weak   = array_filter($rows, fn ($r) => abs($r[2]) > 0.15);
$r2good = array_filter($rows, fn ($r) => $r[3] > 0.1);
$cvLow  = array_filter($rows, fn ($r) => $r[4] < 0.3);
$cvVeryLow = array_filter($rows, fn ($r) => $r[4] < 0.1);
echo "Задач с |corr| > 0.30: " . count($strong) . " из " . count($rows) . "\n";
echo "Задач с |corr| > 0.15: " . count($weak) . " из " . count($rows) . "\n";
echo "Задач с R² > 0.10 (линейная): " . count($r2good) . "\n";
echo "Задач с minCV < 0.30 (простые кандидаты): " . count($cvLow) . "\n";
echo "Задач с minCV < 0.10: " . count($cvVeryLow) . "\n";
$minAll = min(array_column($rows, 4));
echo "Глобальный min CV по всем задачам: " . sprintf("%.4f", $minAll) . "\n";
$constBetter = array_filter($rows, fn ($r) => $r[6] < $r[4]);
echo "Задач, где константа лучше лучшего кандидата: " . count($constBetter) . "\n";

// === 4. Лаг-корреляции для ключевых пар (сон→энергия и др.) ===
echo "\n=== ЛАГИ (x[i−k] → y[i]) ===\n";
$timeKeys = [];
foreach ($tasks as $t) {
    $k1 = explode('→', $t['name'])[0];
    $k2 = explode('→', $t['name'])[1];
    $timeKeys[$k1 . '→' . $k2] = true;
}
// Только пары с достаточным числом точек в сырых данных
$numericCols = [];
foreach ($raw as $m) {
    foreach ($m as $k => $v) {
        if (is_numeric($v) && ! isset($numericCols[$k])) $numericCols[$k] = true;
    }
}
$cols = array_keys($numericCols);
$lagReport = [];
foreach ($cols as $xCol) {
    foreach ($cols as $yCol) {
        if ($xCol === $yCol || $xCol === 'date' || $yCol === 'date' || $xCol === 'week' || $yCol === 'week') continue;
        $xs = []; $ys = [];
        foreach ($raw as $m) {
            if (isset($m[$xCol]) && is_numeric($m[$xCol]) && isset($m[$yCol]) && is_numeric($m[$yCol])) {
                $xs[] = (float) $m[$xCol];
                $ys[] = (float) $m[$yCol];
            }
        }
        if (count($xs) < 15) continue;
        $best = ['k' => 0, 'corr' => pearson($xs, $ys)];
        for ($k = 1; $k <= 7; $k++) {
            if (count($xs) <= $k + 3) break;
            $c = pearson(array_slice($xs, 0, count($xs) - $k), array_slice($ys, $k));
            if (abs($c) > abs($best['corr'])) $best = ['k' => $k, 'corr' => $c];
        }
        if (abs($best['corr']) > 0.25) {
            $lagReport[] = sprintf("%-22s k=%-2d corr=%+.3f", $xCol . '→' . $yCol, $best['k'], $best['corr']);
        }
    }
}
if ($lagReport) {
    sort($lagReport);
    echo "Пары с |лаг-corr| > 0.25 (k=1..7):\n";
    foreach ($lagReport as $l) echo "  " . $l . "\n";
} else {
    echo "Нет пар с |лаг-corr| > 0.25 — временная структура не обнаружена.\n";
}

// === 5. ACF целей ===
echo "\n=== ACF(y) ===\n";
foreach ($cols as $yCol) {
    if ($yCol === 'date' || $yCol === 'week') continue;
    $v = [];
    foreach ($raw as $m) {
        if (isset($m[$yCol]) && is_numeric($m[$yCol])) $v[] = (float) $m[$yCol];
    }
    if (count($v) < 20) continue;
    $a1 = acf($v, 1);
    $a3 = acf($v, 3);
    $a7 = acf($v, 7);
    if (abs($a1) > 0.2 || abs($a3) > 0.2 || abs($a7) > 0.2) {
        printf("  %-12s ACF1=%+.3f ACF3=%+.3f ACF7=%+.3f\n", $yCol, $a1, $a3, $a7);
    }
}
echo "\nДиагностика завершена.\n";
