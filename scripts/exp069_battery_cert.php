<?php

declare(strict_types=1);

/**
 * B4.2-СЕРТ: движок + сертификация на unified-задаче батарей.
 *
 * Постановка (по итогам шагов 1-5): форма t²+химия-взаимодействий доказано
 * выразима (sq+×) и переносима на невидимые протоколы. Вопрос этого скрипта:
 * НАЙДЁТ ли движок (Search::find, beam10nl, depth-3) сам эту форму, и
 * сертифицирует ли её cert_v2 (3×60/40 dual-channel) + null-гейт перестановок.
 *
 * Данные: unified_task_gid (t_norm, is_nmc, is_lfp, is_lco, cell_id) —
 * сэмпл как в exp067 (--rows 0, шаг 5 → n=1287) + полный.
 * Сцены: 1) движок full 2) контроль — form seeded (подсадка формы в кандидаты
 * не делаем: honest — только движок + отдельно ручной cert формы-референса).
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Core\ExpressionEvaluator;

require __DIR__ . '/exp045/cert_utils.php';

$csv = $argv[1] ?? __DIR__ . '/../battery_b2/unified_task_gid.csv';
$sampleStep = (int) ($argv[2] ?? 5);

$lines = file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$header = str_getcsv(trim((string) array_shift($lines)));
// header: cell_id, t_norm, is_nmc, is_lfp, is_lco, cap_norm
$X = []; $y = []; $i = 0;
foreach ($lines as $l) {
    if ($i++ % $sampleStep) { continue; }
    $v = str_getcsv(trim($l));
    if (count($v) < 6) { continue; }
    $X[] = [(float) $v[1], (float) $v[2], (float) $v[3], (float) $v[4]];
    $y[] = (float) $v[5];
}
$labels = ['t_norm', 'is_nmc', 'is_lfp', 'is_lco'];
$n = count($y);
echo "n=$n\n";

// ---------- 1. движок: Search::find ----------
putenv('TRAIL_MARKS=0');
putenv('SEARCH_BEAM_K=10');
putenv('SEARCH_DEPTH_MAX=3');
putenv('SWARM_DB_PATH=:memory:');
putenv('FORAGER_SOURCES=');

$grammar = new Grammar();
$t0 = microtime(true);
$res = Search::find($X, $y, $grammar, 3, $labels, 0.0, 0.15, 0.0);
$wall = round(microtime(true) - $t0, 1);
// return: [found, cv_train, bestName, cv_test, class, diagnosis]
$bestForm = $res[2] ?? 'none';
$bestCv = $res[1] ?? null;
$cvTest = $res[3] ?? null;
$cls = $res[4] ?? '?';
$diag = $res[5] ?? '?';
echo "движок: found=" . var_export($res[0], true) . " form=$bestForm cv_train=" . var_export($bestCv, true)
    . " cv_test=" . var_export($cvTest, true) . " class=$cls diag=$diag ({$wall}s)\n";

// ---------- 2. cert_v2 формы-референса (результат шагов 2/5, x-пространство) ----------
// x0=t_norm, x1=is_nmc, x2=is_lfp, x3=is_lco
$refForms = [
    '(x0×x0)',
    '((x0×x0)×x1)',
    '((x0×x0)×x2)',
    '((x0×x0)/(1+x2))',
];
echo "\ncert_v2 (3×60/40, dual-channel, порог 0.10):\n";
foreach ($refForms as $fx) {
    // labels_to_x не нужен — уже x-пространство (t_norm=... но evaluator ждёт имена)
    $vals = ExpressionEvaluator::evaluateFormula($fx, $X);
    if (! is_array($vals)) { echo "  $fx: eval_fail\n"; continue; }
    $cvs = [];
    for ($sp = 0; $sp < 3; $sp++) {
        [, , $Xe, $ye] = split_eval($X, $y, 424000 + $sp * 104729);
        $cv = cert_x($fx, $Xe, $ye, $r);
        $cvs[] = $cv;
    }
    $pass = count(array_filter($cvs, fn ($v) => $v !== null && $v <= 0.10)) === 3;
    printf("  %-46s pass=%s cvs=%s\n", $fx, $pass ? 'YES' : 'no', json_encode(array_map(fn ($v) => $v === null ? null : round($v, 4), $cvs)));
}

// ---------- 3. per-cell cert: fade = c·t² внутри ячейки (иерархия Finding 1) ----------
// Цель fade = 1 − cap_norm; форма (x0×x0) пропорциональна fade при α=2.
// cert_v2 внутри каждой ячейки (60/40 по точкам), null — перестановка y внутри ячейки.
echo "\nper-cell cert формы (x0×x0) на fade=1−cap (минимум 12 точек):\n";
$byCell = [];
$j = 0;
foreach ($lines as $l) {
    $v = str_getcsv(trim($l));
    if (count($v) < 6) { continue; }
    $byCell[$v[0]][] = [(float) $v[1], 1.0 - (float) $v[5], (float) $v[2], (float) $v[3]];
}
$cert = ['NMC' => [0, 0], 'LFP' => [0, 0], 'LCO' => [0, 0]];
foreach ($byCell as $cid => $pts) {
    if (count($pts) < 12) { continue; }
    $Xc = []; $yc = [];
    foreach ($pts as $p) { $Xc[] = [$p[0]]; $yc[] = $p[1]; }
    $chem = ((float) $pts[0][2]) > 0.5 ? 'NMC' : (((float) $pts[0][3]) > 0.5 ? 'LFP' : 'LCO');
    if (! isset($cert[$chem])) { continue; }
    $cvs = [];
    for ($sp = 0; $sp < 3; $sp++) {
        [, , $Xe, $ye] = split_eval($Xc, $yc, 424000 + $sp * 104729);
        $cvs[] = cert_x('(x0×x0)', $Xe, $ye, $r);
    }
    $pass = count(array_filter($cvs, fn ($v) => $v !== null && $v <= 0.10)) === 3;
    $cert[$chem][0] += $pass ? 1 : 0;
    $cert[$chem][1] += 1;
}
foreach ($cert as $chem => [$pass, $total]) {
    if ($total > 0) { printf("  %-4s: %d/%d ячеек сертифицировано (%.0f%%)\n", $chem, $pass, $total, 100 * $pass / $total);
    }
}

// null: та же процедура на 20 случайных ячейках с перемешанным y
$nullPass = 0; $nullTot = 0;
foreach (array_slice(array_keys($byCell), 0, 20) as $cid) {
    $pts = $byCell[$cid];
    if (count($pts) < 12) { continue; }
    $Xc = []; $yc = [];
    foreach ($pts as $p) { $Xc[] = [$p[0]]; $yc[] = $p[1]; }
    $yp = $yc;
    mt_srand(9100 + $nullTot);
    for ($i = count($yp) - 1; $i > 0; $i--) { $j2 = mt_rand(0, $i); [$yp[$i], $yp[$j2]] = [$yp[$j2], $yp[$i]]; }
    $cvs = [];
    for ($sp = 0; $sp < 3; $sp++) {
        [, , $Xe, $ye] = split_eval($Xc, $yp, 424000 + $sp * 104729);
        $cvs[] = cert_x('(x0×x0)', $Xe, $ye, $r);
    }
    $nullTot++;
    if (count(array_filter($cvs, fn ($v) => $v !== null && $v <= 0.10)) === 3) { $nullPass++; }
}
echo "null-гейт (перемешанный y внутри ячейки): $nullPass/$nullTot сертифицировано\n";
