<?php

declare(strict_types=1);

/**
 * EXP-067 B4: пробник необходимости (spec: Benchmarks/feynman20/B4_PROBE_SPEC.md).
 *
 * Классификация задачи/ячейки БЕЗ знания целевого закона: где в конвейере
 * кончился сигнал. Три состояния формы: born → survived → certified;
 * min-cv по слоям глубины (L1 пары → L2 pair-loop → L3 potential-дети).
 *
 * Классы: engine-ok | potential-bridge | climbing-candidate | borderline | grammar-miss
 *
 * Usage:
 *   php scripts/exp067_probe.php <data.csv> [output.jsonl] [--steps 5000] [--gen dot42]
 * CSV: заголовок, последний столбец = y. --gen dot42 — синтетика dot-задачи.
 */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Hive\RouteExecutor;

// ---------- данные ----------

$taskId = null;
$outFile = null;
$steps = 5000;
$gen = null;
$pos = [];
$argvv = array_slice($argv, 1);
for ($i = 0; $i < count($argvv); $i++) {
    $a = $argvv[$i];
    if ($a === '--steps') { $steps = (int) ($argvv[$i + 1] ?? 5000); $i++; }
    elseif ($a === '--rows') { $rowsLimit = (int) ($argvv[$i + 1] ?? 200); $i++; }
    elseif ($a === '--gen') { $gen = $argvv[$i + 1] ?? ''; $i++; }
    elseif ($a[0] !== '-') { $pos[] = $a; }
}
$taskId = $gen ?: ($pos[0] ? basename((string) $pos[0], '.csv') : 'unknown');
$csv = $pos[0] ?? null;
$outFile = $pos[1] ?? null;

if ($gen === 'dot42') {
    // dot-задача (канон RouteExecutorWriteOnTest::dotTask): 5 фич, 3 пары
    mt_srand(42);
    $X = []; $y = []; $labels = ['x0', 'x1', 'x2', 'x3', 'x4'];
    for ($i = 0; $i < 200; $i++) {
        $row = [];
        for ($j = 0; $j < 5; $j++) { $row[] = mt_rand() / mt_getrandmax() * 4.0 - 2.0; }
        $X[] = $row;
        $y[] = $row[0] * $row[3] + $row[1] * $row[4] + $row[2] * $row[4];
    }
} else {
    if ($csv === null || ! is_file($csv)) { fwrite(STDERR, "нет данных: $csv\n"); exit(1); }
    $lines = file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $header = str_getcsv(trim((string) array_shift($lines)));
    $lim = ($rowsLimit ?? 200) > 0 ? ($rowsLimit ?? 200) : count($lines);
    foreach (array_slice($lines, 0, $lim) as $l) {
        $v = str_getcsv(trim((string) $l));
        if (count($v) < count($header)) { continue; }
        $r = [];
        for ($i = 0; $i < count($header) - 1; $i++) { $r[] = (float) $v[$i]; }
        $X[] = $r; $y[] = (float) $v[count($header) - 1];
    }
    $labels = array_slice($header, 0, -1);
}
// большие n: движок+телескоп на стратифицированном сэмпле (~1500 строк,
// каждая k-я — детерминизм), финальная перепроверка exact — на ПОЛНЫХ данных
$Xfull = $X; $yfull = $y; $nFull = count($y);
if (count($y) > 1500) {
    $stp = (int) ceil(count($y) / 1500);
    $X2 = []; $y2 = [];
    for ($i = 0; $i < count($y); $i += $stp) { $X2[] = $X[$i]; $y2[] = $y[$i]; }
    $X = $X2; $y = $y2;
    fwrite(STDERR, '[MEM] сэмпл: ' . count($y) . " из $nFull (шаг $stp)\n");
}
$n = count($y);
$k = count($labels);
$t0 = microtime(true);
fwrite(STDERR, '[MEM] после загрузки: ' . round(memory_get_usage(true) / 1e6) . "MB\n");

// ---------- движок (A0-профиль: дорога off, полный бюджет) ----------

putenv('TRAIL_MARKS=0');
putenv('TRAIL_BUDGET_MODE=steps');
putenv('TRAIL_BUDGET_STEPS=' . $steps);
putenv('SEARCH_L2_LOOKAHEAD_CAP=800'); // потенциальная стадия движка на полную

$genome = ['id' => 'probe_' . $taskId, 'parent_id' => '', 'condition' => ['kind' => 'ALWAYS'],
    'budget_fraction' => 0.30, 'max_steps' => 5,
    'actions' => [['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 3]]];
$eng = RouteExecutor::run($genome, $X, $y, $labels, 0.001, 0.10, [], 1);
$engineCert = ! empty($eng['found']);
$engineForm = (string) ($eng['cert_formula'] ?? ($eng['formula'] ?? ''));
$engineWall = (int) ((microtime(true) - $t0) * 1000);
fwrite(STDERR, '[MEM] после движка: ' . round(memory_get_usage(true) / 1e6) . "MB peak="
    . round(memory_get_peak_usage(true) / 1e6) . "MB\n");

// ---------- телескоп: cv-профиль по слоям (реплика B3.2) ----------

function cvf(array $a, array $b, ?int $cap = 30): float
{
    $m = min(count($a), count($b), $cap ?? count($a));
    $a = array_slice($a, 0, $m); $b = array_slice($b, 0, $m);
    $ma = array_sum($a) / $m; $mb = array_sum($b) / $m;
    $num = 0.0; $da = 0.0; $db = 0.0;
    for ($i = 0; $i < $m; $i++) { $num += ($a[$i] - $ma) * ($b[$i] - $mb); $da += ($a[$i] - $ma) ** 2; $db += ($b[$i] - $mb) ** 2; }
    return 1.0 - $num / sqrt($da * $db + 1e-30);
}

function applyOp(array $va, array $vb, string $op, int $m): array
{
    $v = [];
    for ($i = 0; $i < $m; $i++) {
        $p = $va[$i]; $q = $vb[$i];
        $v[] = $op === '+' ? $p + $q : ($op === '×' ? $p * $q : ($op === '−' ? $p - $q
            : ($op === '/' ? ($q != 0.0 ? $p / $q : 0.0) : ($op === 'min' ? min($p, $q) : max($p, $q)))));
    }
    return $v;
}

$m30 = min($n, 30);
$y30 = array_slice($y, 0, $m30);
$xs = [];
for ($c = 0; $c < $k; $c++) { $col = []; foreach ($X as $r) { $col[] = (float) $r[$c]; } $xs[$labels[$c]] = $col; }

// L1: сырые + пары (4 ops) + sq — ПОЛНЫЕ векторы (перепроверка exact
// требует все n строк; срез 30 строк делаем при подсчёте cv)
$l1 = [];
foreach ($xs as $nm => $col) { $l1[$nm] = $col; }
$keys = array_keys($l1);
for ($a = 0; $a < count($keys); $a++) {
    for ($b = $a + 1; $b < count($keys); $b++) {
        foreach (['+', '×', '−', '/'] as $op) {
            $l1 = array_merge($l1, ["({$keys[$a]}$op{$keys[$b]})" => applyOp($l1[$keys[$a]], $l1[$keys[$b]], $op, $n)]);
        }
    }
}
foreach ($keys as $nm) { $l1["sq($nm)"] = applyOp($l1[$nm], $l1[$nm], '×', $n); }

$l1cv = [];
foreach ($l1 as $nm => $v) { $l1cv[$nm] = cvf(array_slice($v, 0, $m30), $y30); }
asort($l1cv);
$l1Min = reset($l1cv);

// L2: пул = топ-40 L1 по cv (beam ascending) ∪ ВСЕ сырые колонки ∪ sq
// (движковый pool = l1Keys ∪ featKeys: сырые всегда в пуле — без них
// потенциальная стадия не видит партнёра (x0+x2) — урок валидации I_18_4)
$ops = ['+', '×', '−', '/', 'min', 'max'];
$pool = array_slice(array_keys($l1cv), 0, 40);
$pool = array_values(array_unique(array_merge($pool, $keys, array_map(fn ($nm) => "sq($nm)", $keys))));
// сигнальная квота движка — НЕ реплицируем точно (слишком много каналов):
// пробник намеренно ЩЕДРЕЕ движка — верхняя оценка достижимости перебора.
// Партнёры потенциальной стадии = все l1 с |corr|>=0.5 (плюс сырые и sq):
// если даже щедрый офлайн-перебор не находит точного ребёнка — перебор
// точно бессилен; |corr| считаем прямо по данным (через cv знак теряется)
function corrf(array $a, array $b): float
{
    $m = min(count($a), count($b), 30);
    $a = array_slice($a, 0, $m); $b = array_slice($b, 0, $m);
    $ma = array_sum($a) / $m; $mb = array_sum($b) / $m;
    $num = 0.0; $da = 0.0; $db = 0.0;
    for ($i = 0; $i < $m; $i++) { $num += ($a[$i] - $ma) * ($b[$i] - $mb); $da += ($a[$i] - $ma) ** 2; $db += ($b[$i] - $mb) ** 2; }
    return ($da > 1e-12 && $db > 1e-12) ? $num / sqrt($da * $db) : 0.0;
}
$extra = [];
foreach ($l1cv as $nm => $c) {
    if (abs(corrf($l1[$nm], $y30)) >= 0.5) { $extra[] = $nm; }
}
$pool = array_values(array_unique(array_merge($pool, $keys, array_map(fn ($nm) => "sq($nm)", $keys), $extra)));
fwrite(STDERR, '[PROBE-DBG] pool=' . count($pool) . ' (из них |corr|>=0.5: ' . count($extra) . ")\n");
$born2 = [];   // имя → [родительA, родительB, op] — векторы НЕ храним (n=6433 × 7350 форм = OOM)
$born2cv = [];
$pa = count($pool);
for ($a = 0; $a < $pa; $a++) {
    for ($b = $a + 1; $b < $pa; $b++) {
        foreach ($ops as $op) {
            $nm = "({$pool[$a]}$op{$pool[$b]})";
            $born2[$nm] = [$pool[$a], $pool[$b], $op];
            $born2cv[$nm] = cvf(array_slice(applyOp($l1[$pool[$a]], $l1[$pool[$b]], $op, $m30), 0, $m30), $y30);
        }
    }
}
asort($born2cv);
fwrite(STDERR, '[MEM] после born2cv: ' . round(memory_get_usage(true) / 1e6) . "MB peak="
    . round(memory_get_peak_usage(true) / 1e6) . "MB\n");
// полные векторы форм пула (для перепроверки exact на ВСЕХ nFull строках)
$l1full = [];
foreach ($pool as $nm) {
    if (isset($xs[$nm])) { $l1full[$nm] = []; foreach ($Xfull as $r) { $l1full[$nm][] = $r[array_search($nm, $labels, true)]; } }
    elseif (str_starts_with($nm, 'sq(')) {
        $base = substr($nm, 3, -1);
        $l1full[$nm] = applyOp($l1full[$base] ?? array_map(fn ($r) => $r[array_search($base, $labels, true)], $Xfull), $l1full[$base] ?? array_map(fn ($r) => $r[array_search($base, $labels, true)], $Xfull), '×', $nFull);
    } else {
        // плоская пара "(a op b)" из сырых
        $inner = substr($nm, 1, -1);
        foreach (['+', '×', '−', '/'] as $o) {
            if (str_contains($inner, $o)) { [$a2, $b2] = explode($o, $inner, 2); $a2 = trim($a2); $b2 = trim($b2); break; }
        }
        if (! isset($a2, $b2)) { continue; }
        $va = []; $vb = [];
        $ia = array_search($a2, $labels, true); $ib = array_search($b2, $labels, true);
        foreach ($Xfull as $r) { $va[] = $r[$ia]; $vb[] = $r[$ib]; }
        $l1full[$nm] = applyOp($va, $vb, $o, $nFull);
    }
}
$l2Min = $born2cv ? reset($born2cv) : 9.99;
$l2Thresh = $born2cv ? array_values($born2cv)[min(39, count($born2cv) - 1)] : 9.99;

// L3: potential-дети (top-800 по cv, достройка из пула, break на exact).
// EXP: 30-строчный срез льстит вырожденным (урок beam) — exact-вердикт
// ОБЯЗАН перепроверяться полным cv на всех n строках.
$l3Min = 9.99; $exactChildren = 0; $bestChild = '';
// DEBUG-пробника: факты о кирпиче I_18_4-класса
foreach (['(x0×x1)', '(x2×x3)'] as $dp) {
    if (isset($l1[$dp])) {
        fwrite(STDERR, "[PROBE-DBG] $dp in_pool=" . (in_array($dp, $pool, true) ? 'YES' : 'NO')
            . ' cv=' . round($l1cv[$dp], 4) . ' |corr|=' . round(abs(corrf($l1[$dp], $y30)), 4) . "\n");
    }
}
$brick = '((x0×x1)+(x2×x3))';
$brickAlt = '((x2×x3)+(x0×x1))';
$brickBorn = isset($born2[$brick]) || isset($born2[$brickAlt]);
$brickInTop = isset($scored2[$brick]) || isset($scored2[$brickAlt]);
fwrite(STDERR, "[PROBE-DBG] brick born=" . ($brickBorn ? 'YES' : 'NO') . " in_top800=" . ($brickInTop ? 'YES' : 'NO') . "\n");
$scored2 = $born2cv; // EXP: без топ-800 — кирпич ранга >800 выпадал (урок I_18_4);
                     // полный перебор born2, 30-строчный быстрый cv + полная перепроверка
foreach ($scored2 as $nm2 => $cv2) {
    [$ka, $kb, $op1] = $born2[$nm2]; // векторы лениво: born2 хранит только родителей
    $best = 9.99;
    // EXP: перебор ПОЛНЫЙ — break на первом cv30<0.02 прекращал скан
    // до настоящего точного партнёра (вырожденных почти-точных много)
    foreach ($pool as $pn) {
        foreach ($ops as $op) {
            $v30 = applyOp(applyOp($l1[$ka], $l1[$kb], $op1, $m30), $l1[$pn], $op, $m30);
            $c = cvf($v30, $y30);
            if ($c < $best) { $best = $c; $bestChild = "($nm2$op$pn)"; }
            if ($c < 0.02) {
                // перепроверка на ПОЛНОМ векторе (nFull строк): полная
                // точность = машинный cv (1e-6); cv<0.02 — не exact
                // (урок валидации I_27_6: «почти-точные» движок не сертит)
                $vFull = applyOp(applyOp($l1full[$ka], $l1full[$kb], $op1, $nFull), $l1full[$pn], $op, $nFull);
                $cvFull = cvf($vFull, $yfull, null);
                if (abs($cvFull) < 1e-6) { $exactChildren++; }
            }
        }
    }
    $l3Min = min($l3Min, $best);
}

// ---------- вердикт ----------

if ($engineCert) { $class = 'engine-ok'; }
elseif ($exactChildren > 0) { $class = 'potential-bridge'; }
elseif ($l3Min < $l2Min * 0.6) { $class = 'climbing-candidate'; }
elseif (min($l1Min, $l2Min, $l3Min) < 0.15) { $class = 'borderline'; }
else { $class = 'grammar-miss'; }

$row = [
    'task' => $taskId, 'class' => $class, 'n' => $n, 'k' => $k,
    'engine_cert' => $engineCert ? 1 : 0, 'engine_form' => $engineForm, 'engine_wall_ms' => $engineWall,
    'steps' => $steps,
    'l1_min_cv' => round($l1Min, 4), 'l2_min_cv' => round($l2Min, 4), 'l2_beam_thresh' => round($l2Thresh, 4),
    'l3_min_cv' => round($l3Min, 4), 'exact_children' => $exactChildren, 'best_child' => $bestChild,
    'probe_wall_ms' => (int) ((microtime(true) - $t0) * 1000),
];
$line = json_encode($row, JSON_UNESCAPED_UNICODE);
echo $line, "\n";
if ($outFile) { file_put_contents($outFile, $line . "\n", FILE_APPEND); }
