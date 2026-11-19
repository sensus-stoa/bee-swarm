<?php
declare(strict_types=1);

/**
 * B5.8-пилот: метаболиты как базис композиционного терма.
 *
 * Гипотеза B5.6-контракта: одношаговое приращение вида i в композиции
 * зависит от МЕТАБОЛИЧЕСКОГО контекста: y_i = base_i + sum_f b_if * m_f(context),
 * где m_f — метаболиты, ПРОИЗВОДИМЫЕ соседями в композиции (EV4: log2 fold
 * метаболита f монокультурой вида j => его вклад в пул).
 *
 * Пилот (лёгкий, без рук/дорог):
 *   x_f(j) = log2fold метаболита f у вида j (EV4).
 *   метаболический контекст вида i в композиции: m_f = sum_j x_f(j) * x_j
 *   (взвешенный пул по наблюдаемым долям).
 *   Модель: y_i = r_i + d_i*x_i + sum_f b_if*m_f  (b_if —Assembly собирает).
 *
 * Режим: Search::find на per-species данных с метаболическими фичами,
 * depth-2, без trail-рук. Смотр: корреляция и R2 one-step EV3 vs B5.6-M0.
 */

require __DIR__ . '/exp070_shared.php';
require __DIR__ . '/../vendor/autoload.php';
error_reporting(E_ALL & ~E_WARNING);

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

$VEN = __DIR__ . '/../Benchmarks/venturelli';

// --- EV4: 97 метаболитов x 12 видов ---
$rows = openpyxl_load($VEN . '/ev4_metabolites.csv');
$header = array_map(fn ($v) => trim(str_replace(["'", "\r"], '', $v)), $rows[0]);
$speciesEV4 = array_slice($header, 1);
$metab = [];
foreach (array_slice($rows, 1) as $r) {
    $name = trim(str_replace(["'", "\r"], '', $r[0]));
    if ($name === '') { continue; }
    foreach ($speciesEV4 as $c => $sp) {
        $v = trim((string) ($r[$c + 1] ?? ''));
        if (is_numeric($v)) { $metab[$name][$sp] = (float) $v; }
    }
}
echo 'метаболитов: ' . count($metab) . ' x видов: ' . count($speciesEV4) . "\n";

// отбор: топ-3 метаболита по дисперсии (5 фич x depth-2 = OOM пула форм)
$scores = [];
foreach ($metab as $name => $bySp) {
    $vals = array_values($bySp);
    $m = array_sum($vals) / count($vals);
    $v = 0.0;
    foreach ($vals as $x) { $v += ($x - $m) ** 2; }
    $scores[$name] = $v / count($vals);
}
arsort($scores);
$topM = array_slice(array_keys($scores), 0, 1, true); // пилот: 1 метаболит (2 фичи) — пул depth-2 квадратичен
echo "топ-1 по дисперсии: ";
foreach ($topM as $mn) { echo substr($mn, 0, 18) . '(' . round($scores[$mn], 1) . ') '; }
echo "\n";

// --- EV3: строки приращений с метаболическим контекстом ---
$raw = file($VEN . '/ev3_communities.csv');
$communities = [];
$cur = null;
foreach ($raw as $line) {
    $line = trim($line);
    if ($line === '') { continue; }
    if (preg_match('/^([A-Z]{2}-|NONE)/', $line)) { $cur = rtrim($line); $communities[$cur] = []; continue; }
    if ($cur !== null && preg_match('/^[\d\s.eE+-]+$/', $line)) {
        $communities[$cur][] = array_map('floatval', preg_split('/\s+/', trim($line)));
    }
}
$ev3order = ['BH', 'CA', 'BU', 'PC', 'BO', 'BV', 'BT', 'EL', 'FP', 'CH', 'DP', 'ER'];

// собрка: [y_i, x_i, m_1..m_5, sp, comm, t]
$records = [];
foreach ($communities as $label => $mat) {
    for ($t = 0; $t < 6; $t++) {
        $xRow = [];
        foreach ($ev3order as $c => $sp) { $xRow[$sp] = $mat[$t][$c]; }
        foreach ($ev3order as $c => $sp) {
            $xi = $mat[$t][$c]; $xin = $mat[$t + 1][$c] ?? null;
            if ($xin === null || $xi < 0.01) { continue; }
            $feats = [$xi];
            foreach ($topM as $mname) {
                // метаболический пул: sum_j x_f(j)*x_j, j != i
                $pool = 0.0;
                foreach ($xRow as $j => $xj) {
                    if ($j === $sp) { continue; }
                    $pool += ($metab[$mname][$j] ?? 0.0) * $xj;
                }
                $feats[] = $pool;
            }
            $records[] = ['y' => ($xin - $xi) / $xi, 'feats' => $feats, 'sp' => $sp, 'comm' => $label, 't' => $t];
        }
    }
}
echo 'строк с метаболическим контекстом: ' . count($records) . "\n";

// --- прогон Search на данных ОДНОГО вида (BH — больше всего строк) и глобально ---
// per-species: y_i, фичи [x_i, m_1..m_5]
$labels = array_merge(['x_i'], array_map(fn ($n) => 'm_' . substr($n, 0, 12), $topM));
foreach (['BH', 'BO', 'ER'] as $target) {
    $X = []; $y = [];
    foreach ($records as $rec) {
        if ($rec['sp'] !== $target) { continue; }
        $X[] = $rec['feats'];
        $y[] = $rec['y'];
    }
    if (count($y) < 20) { echo "$target: мало строк (" . count($y) . ")\n"; continue; }
    // ПИЛОТ: движок на 2 фичах (x_i + 1 метаболит).
    // ЧИСТАЯ ГРАММАТИКА: дефолтный swarm.db несёт 3562 custom-опов из чужих
    // доменов (semantic/preg_match мусор) — они раздували пул форм в 40x.
    // Для числового домена нужны только BASE_OPS: изолированная :memory: БД.
    \BeeSwarm\Infra\Database::setPath(':memory:');
    $grammar = new Grammar();
    putenv('TRAIL_MARKS=0');
    $res = Search::find($X, $y, $grammar, 2, $labels, 0.3, 0.15, 540.0); // budget 60s
    $form = $res[2] ?? 'none';
    $cvTest = $res[3] ?? null;
    printf("%s: движок: %s cv=%s diag=%s\n", $target, $form, is_numeric($cvTest) ? round($cvTest, 4) : var_export($cvTest, true), $res[4] ?? '?');
    // базовый corr x_i-only
    $xs = array_column($X, 0);
    $mo = array_sum($y) / count($y); $mx = array_sum($xs) / count($xs);
    $cov = 0.0; $va = 0.0; $vb = 0.0;
    for ($i = 0; $i < count($y); $i++) { $cov += ($y[$i] - $mo) * ($xs[$i] - $mx); $va += ($y[$i] - $mo) ** 2; $vb += ($xs[$i] - $mx) ** 2; }
    $rX = ($va > 1e-15 && $vb > 1e-15) ? $cov / sqrt($va * $vb) : 0.0;
    printf("%s (n=%d): corr(x_i)=%+.3f\n", $target, count($y), $rX);
    // per-метаболит corr с y_i
    for ($f = 1; $f < count($labels); $f++) {
        $ms = array_column($X, $f);
        $mm = array_sum($ms) / count($ms);
        $cov = 0.0; $vb = 0.0;
        for ($i = 0; $i < count($y); $i++) { $cov += ($y[$i] - $mo) * ($ms[$i] - $mm); $vb += ($ms[$i] - $mm) ** 2; }
        $r = ($va > 1e-15 && $vb > 1e-15) ? $cov / sqrt($va * $vb) : 0.0;
        printf("  corr(y, %s) = %+.3f\n", $labels[$f], $r);
    }
}

// --- корреляция метаболических пулов с y (глобально, все виды вместе) ---
echo "\nглобальные corr(y, метаболический пул):\n";
foreach ($topM as $mname) {
    $lbl = 'm_' . substr($mname, 0, 12);
    $fi = array_search($lbl, $labels, true);
    if ($fi === false) { continue; }
    $ms = []; $ys = [];
    foreach ($records as $rec) { $ms[] = $rec['feats'][$fi]; $ys[] = $rec['y']; }
    $mo = array_sum($ys) / count($ys); $mm = array_sum($ms) / count($ms);
    $cov = 0.0; $va = 0.0; $vb = 0.0;
    for ($i = 0; $i < count($ys); $i++) { $cov += ($ys[$i] - $mo) * ($ms[$i] - $mm); $va += ($ys[$i] - $mo) ** 2; $vb += ($ms[$i] - $mm) ** 2; }
    $r = ($va > 1e-15 && $vb > 1e-15) ? $cov / sqrt($va * $vb) : 0.0;
    printf("  corr(y, %s): %+.3f\n", substr($mname, 0, 24), $r);
}
