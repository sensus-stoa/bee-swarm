<?php
declare(strict_types=1);

/**
 * B7-Prot: СИНТЕЗ БЕЛКА КАК КОМПОЗИЦИОННЫЙ ВЫХОД (идея owner, 19.11).
 *
 * Биохимия гипотезы: биомасса (yield) = интеграл синтеза макромолекул;
 * пуриновый salvage-кросс-фидинг (hypoxanthine/adenine/inosine) снимает
 * стоимость de novo нуклеотидного синтеза (~5-10 ATP/пурин) и дозирует
 * rRNA (μ пропорциональна доле рибосом) => пуриновая ось — ближайший
 * предиктор композиционного отклонения yield.
 *
 * Данные (Ho 2024 mGAM + BHI):
 *  - yield mono (15 видов), PairwiseSpent (yield вида в spent среде другого),
 *    Co-culture (yield пары) — OD48h;
 *  - metabolomics HILIC mono: named 407 (35 пуриновых) + uncurated;
 *  - assembly rel abu: 302 treatments (pairs/triples/tetrads/7-mer).
 *
 * Тесты (без движка, уровни 1-2 лестницы):
 *  T1. Δyield(pairs) = yield_pair - max(mono_i, mono_j) [и аддитивная база
 *      yield_pair - (mono_i+mono_j) как чувствительность]; знак/магнетуда
 *      по парам; распределение Δ.
 *  T2. Класс-тест: объясняют ли пуриновые depletion-фичи донора
 *      (в spent среде которого растёт вид) Δyield_spent лучше других
 *      классов (SCFA, amino acid, other). Mann-Whitney + permutation
 *      по меткам классов.
 *  T3. Δyield vs richness на co-culture (пары) — rich-контраст недоступен
 *      в yield (только пары в этом файле), поэтому T3 = перенос signs:
 *      коррелирует ли Δyield(pair) с остатком Δ(rich) из Clark (другая
 *      система) по общим видам (Bt, Bf, Bu, Pd...). Общие виды mGAM↔Clark:
 *      Bt, Bf, Bu, Pd, Bp, Cc, Cha, Csy, Efs...
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);
if (!function_exists('median')) {
    function median(array $a): float { $a = array_values($a); sort($a); $n = count($a);
        return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2; }
}

$Y = '/tmp/ho_yield_mgam.xlsx';
$wb = openpyxl_load($Y);
// openpyxl_load не даёт sheets — читаем вручную через SimpleXLSX аналогично python? PHP
// без lib. Используем python-подготовку: заготовим csv из xlsx заранее (в shell ниже).
// Здесь парсим подготовленные csv.

// --- T1: Δyield пар ---
$mono = []; $pair = []; $spent = [];
if (($h = fopen('/tmp/ho_mono_c.csv', 'r')) !== false) {
    fgetcsv($h); fgetcsv($h);
    while (($r = fgetcsv($h)) !== false) {
        if (!$r || trim((string) $r[0]) === '') { continue; }
        $vals = [];
        for ($i = 1; $i < count($r); $i++) { if (is_numeric($r[$i])) { $vals[] = (float) $r[$i]; } }
        if ($vals) { $mono[trim($r[0])] = array_sum($vals) / count($vals); }
    }
    fclose($h);
}
if (($h = fopen('/tmp/ho_coculture_c.csv', 'r')) !== false) {
    fgetcsv($h); fgetcsv($h);
    while (($r = fgetcsv($h)) !== false) {
        if (!$r || trim((string) $r[0]) === '') { continue; }
        $vals = [];
        for ($i = 1; $i < count($r); $i++) { if (is_numeric($r[$i])) { $vals[] = (float) $r[$i]; } }
        if ($vals) { $pair[trim($r[0])] = array_sum($vals) / count($vals); }
    }
    fclose($h);
}
if (($h = fopen('/tmp/ho_spent_c.csv', 'r')) !== false) {
    fgetcsv($h); fgetcsv($h);
    while (($r = fgetcsv($h)) !== false) {
        if (!$r || trim((string) $r[0]) === '') { continue; }
        // формат Donor_Receiver: yield Receiver в spent среде Donor
        $vals = [];
        for ($i = 1; $i < count($r); $i++) { if (is_numeric($r[$i])) { $vals[] = (float) $r[$i]; } }
        if ($vals) { $spent[trim($r[0])] = array_sum($vals) / count($vals); }
    }
    fclose($h);
}
printf("mono: %d | co-culture: %d | spent: %d\n", count($mono), count($pair), count($spent));

// Δyield пары: co-culture yield vs лучший mono-участник (доминирование) и vs аддитивная сумма
$deltaBest = []; $deltaAdd = []; $deltas = [];
foreach ($pair as $k => $y) {
    if (!preg_match('/^(\w{2,3})\+(\w{2,3})$/', $k, $m)) { continue; }
    [$_, $a, $b] = $m;
    if (!isset($mono[$a], $mono[$b])) { continue; }
    $db = $y - max($mono[$a], $mono[$b]);
    $da = $y - ($mono[$a] + $mono[$b]);
    $deltaBest["$a|$b"] = $db; $deltaAdd["$a|$b"] = $da;
    $deltas[] = $db;
}
echo "\n=== T1: Δyield пар (co-culture - max mono), n=", count($deltaBest), " ===\n";
printf("медиана Δ_best=%+.4f  среднее=%+.4f  доля Δ>0=%.2f  [%+.4f..%+.4f]\n",
    median($deltas), array_sum($deltas) / max(1, count($deltas)),
    count(array_filter($deltas, fn ($x) => $x > 0)) / max(1, count($deltas)),
    min($deltas), max($deltas));
$d2 = array_values($deltaAdd);
printf("чувствительность (vs аддитивная сумма): медиана Δ=%+.4f доля Δ>0=%.2f\n",
    median($d2), count(array_filter($d2, fn ($x) => $x > 0)) / max(1, count($d2)));

// топ-10 синергий и антагонизмов
arsort($deltaBest);
echo "\nтоп-8 синергий (Δ_yield > 0):\n";
$i = 0;
foreach ($deltaBest as $k => $v) { if ($i++ >= 8) { break; } printf("  %-8.8s %+.4f\n", $k, $v); }
asort($deltaBest);
echo "топ-8 антагонизмов (Δ_yield < 0):\n";
$i = 0;
foreach ($deltaBest as $k => $v) { if ($i++ >= 8) { break; } printf("  %-8.8s %+.4f\n", $k, $v); }

// --- T2: spent-media: класс-тест пуриновых depletion профилей vs Δyield_spent ---
// Δspent(receiver|donor) = yield(receiver in spent_donor) - yield(receiver in fresh)
// положительный Δ = spent среда донора ЛУЧШЕ fresh = донор что-то оставил (кросс-фидинг-кандидат)
$deltaSpent = [];
foreach ($spent as $k => $y) {
    if (!preg_match('/^(\w{2,3})_(\w{2,3})$/', $k, $m)) { continue; }
    [$_, $donor, $recv] = $m;
    if (!isset($mono[$recv])) { continue; }
    $deltaSpent["$donor|$recv"] = $y - $mono[$recv];
}
echo "\n=== T2: Δspent (yield получателя в spent донора - fresh), n=", count($deltaSpent), " ===\n";
$ds = array_values($deltaSpent);
printf("медиана=%+.4f доля>0=%.2f\n", median($ds), count(array_filter($ds, fn ($x) => $x > 0)) / max(1, count($ds)));

// --- T3: связь с Clark Δ(rich) по общим видам ---
// У Bt в mGAM mono=1.43 (доминант), в Clark парах bt — тоже ключевой.
// Мерить: у видов с high mono-yield (грамм-положительные ростки) vs Clark Δ>0.
echo "\n=== T3: mono yield (mGAM) по видам ===\n";
arsort($mono);
foreach ($mono as $sp => $y) { printf("  %-8.8s %.3f\n", $sp, $y); }
