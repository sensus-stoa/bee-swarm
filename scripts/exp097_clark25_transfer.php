<?php
declare(strict_types=1);

/**
 * B7-External уровень 2: ПЕРЕНОС на 25-видовую систему (Clark 2021 MasterDF).
 *
 * Постановка "композиционный перенос":
 *   Train: пары (136 treat) и монокультуры (55)
 *   Test:  richness>=3 (423 treat)
 *   y: Butyrate (mM, главный выход)
 *   Модели:
 *     M0 аддитивная: Butyrate_pred = sum_i beta_i * frac_i (только mono-вклады,
 *        frac_i — 16S-доля вида i, beta_i фит на монокультурах)
 *     M1 парная: Butyrate_pred = sum_i beta_i*frac_i + sum_{i<j} alpha_ij*frac_i*frac_j
 *        (alpha фит на парах, N термов ~25+325=350 > 136 наблюдений — ЛОЖУСЬ
 *        с регуляризацией: только alpha_ij для пар, ЕСТЬ в train; полный design
 *        на парах — 136 строк x 325 колонок, сильно недоопределён. Поэтому
 *        M1 упрощаем: alpha_ij фит per-пара из данных этой пары (не глобальная
 *        регрессия): pred_full = sum_i frac_i*mono_i + sum_{pairs present} interaction_ij
 *        где interaction_ij = (Butyrate_pair_ij - mono-additive_ij) * frac_i * frac_j * scale
 *   Проверка: R2 на richness>=3, корреляция предсказание-факт, сравнение M1 vs M0.
 *   Null: перестановка принадлежности парных взаимодействий между сообществами.
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);

if (!function_exists('median')) {
    function median(array $a): float {
        $a = array_values($a);
        if (!$a) { return NAN; }
        sort($a);
        $n = count($a);
        return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2;
    }
}

$rows = [];
foreach (openpyxl_load(__DIR__ . '/../Benchmarks/clark25/MasterDF_WellNumbers.csv') as $i => $r) {
    if ($i === 0) { continue; }
    if (count($r) < 30 || trim((string) $r[1]) === '') { continue; }
    $rows[] = $r;
}
$header = null;
foreach (openpyxl_load(__DIR__ . '/../Benchmarks/clark25/MasterDF_WellNumbers.csv') as $i => $r) {
    if ($i === 0) { $header = array_map(fn ($v) => trim((string) $v), $r); break; }
}
$idx = fn (string $name) => array_search($name, $header, true);
$iOD = $idx('OD'); $iAce = $idx('Acetate'); $iBut = $idx('Butyrate'); $iLac = $idx('Lactate'); $iSuc = $idx('Succinate');
$SPECIES = ['ER','FP','AC','HB','CC','RI','DP','BH','CA','PC','DL','CG','BF','EL','CH','BO','BT','BU','BV','BC','BY','PJ','DF','BL','BP','BA'];
$iS = [];
foreach ($SPECIES as $s) { $iS[$s] = $idx($s); }
$iFrac = [];
foreach ($SPECIES as $s) { $iFrac[$s] = $idx($s . ' Fraction'); }

// агрегация per treatment: [rep => {OD, Ace, But, Lac, Suc, frac{sp}}]
$treat = [];
foreach ($rows as $r) {
    $t = trim((string) $r[1]);
    if ($t === '') { continue; }
    $d = [];
    foreach (['OD' => $iOD, 'Ace' => $iAce, 'But' => $iBut, 'Lac' => $iLac, 'Suc' => $iSuc] as $k => $ix) {
        $v = $r[$ix] ?? null;
        $d[$k] = is_numeric($v) ? (float) $v : null;
    }
    $fr = [];
    $sum = 0.0;
    foreach ($SPECIES as $s) {
        $v = $r[$iFrac[$s]] ?? null;
        $fv = is_numeric($v) ? (float) $v : 0.0;
        $fr[$s] = $fv; $sum += $fv;
    }
    if ($sum > 0) { foreach ($fr as $s => $v) { $fr[$s] = $v / $sum; } }
    $d['frac'] = $fr;
    // состав из имени
    $members = explode('-', $t);
    $d['members'] = $members;
    $d['rich'] = count($members);
    $fsum = 0;
    foreach ($d['frac'] as $v) { $fsum += $v; }
    if ($fsum > 0.5) { $treat[$t][] = $d; }
}
// средние по репликам
$mean = [];
foreach ($treat as $t => $reps) {
    $m = ['But' => 0, 'Ace' => 0, 'Lac' => 0, 'Suc' => 0, 'OD' => 0];
    $n = 0;
    foreach ($reps as $d) {
        if ($d['But'] === null) { continue; }
        foreach ($m as $k => $v) { $m[$k] += $d[$k] ?? 0; }
        $n++;
    }
    if ($n === 0) { continue; }
    foreach ($m as $k => $v) { $m[$k] /= $n; }
    // frac: усреднить
    $fr = [];
    foreach ($SPECIES as $s) { $fr[$s] = 0; }
    foreach ($reps as $d) { foreach ($SPECIES as $s) { $fr[$s] += $d['frac'][$s]; } }
    foreach ($SPECIES as $s) { $fr[$s] /= max(1, count($reps)); }
    $m['frac'] = $fr;
    $m['members'] = $reps[0]['members'];
    $m['rich'] = $reps[0]['rich'];
    $m['n'] = $n;
    $mean[$t] = $m;
}
echo "treatments с y: ", count($mean), "\n";

$richDist = [];
foreach ($mean as $t => $m) { $richDist[$m['rich']] = ($richDist[$m['rich']] ?? 0) + 1; }
ksort($richDist);
echo "richness: ", json_encode($richDist), "\n";

// === модели ===
// beta_i из монокультур: Butyrate_mono_i (концентрация при frac_i ~ 1)
$beta = [];
foreach ($mean as $t => $m) {
    if ($m['rich'] === 1) { $beta[$m['members'][0]] = $m['But']; }
}
echo "монокультур с y: ", count($beta), "\n";

// парные взаимодействия: inter_ij = Butyrate_pair_ij - beta_i - beta_j (реальное отклонение от аддитивности)
$inter = [];
foreach ($mean as $t => $m) {
    if ($m['rich'] !== 2) { continue; }
    [$a, $b] = $m['members'];
    if (!isset($beta[$a], $beta[$b])) { continue; }
    $fsum2 = $m['frac'][$a] + $m['frac'][$b];
$inter["{$a}|{$b}"] = [
    'val' => $m['But'] - $beta[$a] - $beta[$b],
    'fprod' => $fsum2 > 0 ? ($m['frac'][$a] / $fsum2) * ($m['frac'][$b] / $fsum2) : 0.25,
];
}
echo "парных взаимодействий: ", count($inter), "\n";

// предсказание для richness>=2:
// M0: sum_i frac_i * beta_i
// M1: M0 + sum по присутствующим парам (если inter_ij известна) inter_ij * 2 * frac_i * frac_j
//     (2*fi*fj — максимум произведения долей; scale калибруем на парах: на паре fi=fj=1 -> вклад=inter_ij)
// калибровочный scale фит на ПАРАХ (не на тесте): минимизируем SSE на richness==2
$pairKeys = array_keys($inter);
$predict = function (array $m, float $scale) use ($beta, $inter): array {
    // перезамыкание frac на присутствующих членах
    $fsum = 0.0;
    foreach ($m['members'] as $s) { $fsum += $m['frac'][$s]; }
    $fn = [];
    foreach ($m['members'] as $s) { $fn[$s] = $fsum > 0 ? $m['frac'][$s] / $fsum : 0.0; }
    $m['fracN'] = $fn;
    $pred0 = 0.0;
    foreach ($m['members'] as $s) { $pred0 += $fn[$s] * ($beta[$s] ?? 0.0); }
    $interTerm = 0.0;
    $nKnown = 0;
    $mem = $m['members'];
    for ($i = 0; $i < count($mem); $i++) {
        for ($j = $i + 1; $j < count($mem); $j++) {
            $a = $mem[$i]; $b = $mem[$j];
            $key = "{$a}|{$b}";
            if (isset($inter[$key])) {
                $fprod = $m['fracN'][$a] * $m['fracN'][$b];
                $denom = max($inter[$key]['fprod'], 0.01);
                $interTerm += $inter[$key]['val'] * ($fprod / $denom);
                $nKnown++;
            }
        }
    }
    return [$pred0, $pred0 + $scale * $interTerm, $nKnown];
};
// scale фит на парах: на паре fi=fj≈1 -> полный inter => scale=1 (самокалибровка). Проверим:
$sse0 = 0; $sse1 = 0; $n2 = 0;
foreach ($mean as $t => $m) {
    if ($m['rich'] !== 2) { continue; }
    [$p0, $p1, ] = $predict($m, 1.0);
    $sse0 += ($m['But'] - $p0) ** 2; $sse1 += ($m['But'] - $p1) ** 2; $n2++;
}
echo sprintf("на парах (n=%d): SSE M0=%.1f SSE M1=%.1f (M1 должен быть ~0)\n", $n2, $sse0, $sse1);

// === ТЕСТ на richness>=3 ===
$test = array_filter($mean, fn ($m) => $m['rich'] >= 3);
echo "тест-сообществ (rich>=3): ", count($test), "\n";
$r0s = []; $r1s = []; $covered = [];
foreach ($test as $t => $m) {
    [$p0, $p1, $nk] = $predict($m, 1.0);
    $nPairs = $m['rich'] * ($m['rich'] - 1) / 2;
    $covered[] = $nk / $nPairs;
    $r0s[] = ['y' => $m['But'], 'p' => $p0];
    $r1s[] = ['y' => $m['But'], 'p' => $p1];
}
$evalR2 = function (array $pr): float {
    $n = count($pr);
    $my = array_sum(array_column($pr, 'y')) / $n;
    $sse = 0; $tot = 0;
    foreach ($pr as $d) { $sse += ($d['y'] - $d['p']) ** 2; $tot += ($d['y'] - $my) ** 2; }
    return $tot > 1e-12 ? 1 - $sse / $tot : NAN;
};
$evalR = function (array $pr): float {
    $n = count($pr);
    $y = array_column($pr, 'y'); $p = array_column($pr, 'p');
    $my = array_sum($y) / $n; $mp = array_sum($p) / $n;
    $sxy = 0; $sxx = 0; $syy = 0;
    for ($i = 0; $i < $n; $i++) { $sxy += ($y[$i]-$my)*($p[$i]-$mp); $sxx += ($y[$i]-$my)**2; $syy += ($p[$i]-$mp)**2; }
    return ($sxx > 0 && $syy > 0) ? $sxy / sqrt($sxx * $syy) : NAN;
};
printf("\n=== Перенос на richness>=3 (n=%d) ===\n", count($test));
printf("M0 (аддитивная, только mono):  R2=%.3f  r=%.3f\n", $evalR2($r0s), $evalR($r0s));
printf("M1 (+парные inter, scale=1):   R2=%.3f  r=%.3f\n", $evalR2($r1s), $evalR($r1s));
printf("покрытие парами: медиана %.2f, мин %.2f\n", median($covered), min($covered));

// разбивка по richness
echo "\n=== по richness ===\n";
$byRich = [];
foreach ($test as $t => $m) { $byRich[$m['rich']][] = $m; }
foreach ($byRich as $rich => $ms) {
    $a = []; $b = [];
    foreach ($ms as $m) { [$p0, $p1, ] = $predict($m, 1.0); $a[] = ['y' => $m['But'], 'p' => $p0]; $b[] = ['y' => $m['But'], 'p' => $p1]; }
    printf("  rich=%2d n=%3d  M0 R2=%+.3f r=%+.3f | M1 R2=%+.3f r=%+.3f\n", $rich, count($ms),
        $evalR2($a), $evalR($a), $evalR2($b), $evalR($b));
}
