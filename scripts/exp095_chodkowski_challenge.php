<?php
declare(strict_types=1);

/**
 * B7-External, этап 1: сборка challenge-таблицы из Chodkowski & Shade 2024.
 *
 * Из MSData/*Features_readyForMetaboanalyst.csv (формат: фичи в строках RT/mz,
 * сэмплы в колонках, 4 био-реплики) собираем:
 *   - per-фича значения mono(Bt, Cv, Ps), pair(BtCv, BtPs, CvPs), full
 *     для каждой временной точки (12.5, 25, 30, 35, 40, 45 h);
 *   - аддитивный null авторов: nullAdditive = mean(BtCv) + mean(BtPs) + mean(CvPs)
 *     (в их коде — sum ПОСЛЕ фильтра, но по смыслу это сумма 3 парных средних);
 *   - Delta = Full - nullAdditive (на нормализованных значениях).
 *
 * Плюс пуриновая разметка фич: m/z < 5 mDa к целевым массам (salvage-оси),
 * класс: purine | pyrimidine | BGC | other.
 */

require __DIR__ . '/exp070_shared.php';
error_reporting(E_ALL & ~E_WARNING);

$DIR = __DIR__ . '/../Benchmarks/chodkowski/MSData';
$OUT = __DIR__ . '/../Benchmarks/chodkowski';

// пуриновые/пиримидиновые цели (neutral monoisotopic)
$TARGETS = [
    // purine salvage
    'hypoxanthine' => [135.0303, 'purine'],
    'inosine'      => [267.0754, 'purine'],
    'adenosine'    => [266.0867, 'purine'],
    'adenine'      => [134.0358, 'purine'],
    'guanine'      => [150.0303, 'purine'],
    'guanosine'    => [282.0837, 'purine'],
    'xanthine'     => [151.0143, 'purine'],
    'xanthosine'   => [297.0708, 'purine'],
    'uric acid'    => [167.0203, 'purine'],
    'allantoin'    => [157.0457, 'purine'],
    // pyrimidine
    'uridine'      => [243.0601, 'pyrimidine'],
    'cytidine'     => [242.0752, 'pyrimidine'],
    'thymidine'    => [241.0905, 'pyrimidine'],
    'orotic acid'  => [155.0331, 'pyrimidine'],
];

$MODES = ['PolarPos', 'PolarNeg', 'NonPolarPos', 'NonPolarNeg'];
$REPS = ['1', '2', '3', '4'];
$TIMES = ['12pt5', '25', '30', '35', '40', '45'];
$MEMBERS = ['Bt' => 0, 'Cv' => 1, 'Ps' => 2];
$PAIRS = ['BtCv' => ['Bt', 'Cv'], 'BtPs' => ['Bt', 'Ps'], 'CvPs' => ['Cv', 'Ps']];

// загрузка режима: [feat => [cond => [rep => val]]]
$loadMode = function (string $mode) use ($DIR): array {
    $path = $DIR . '/' . $mode . 'Features_readyForMetaboanalyst.csv';
    $f = fopen($path, 'r');
    $header = fgetcsv($f);
    $pheno = fgetcsv($f);
    $data = [];
    while (($row = fgetcsv($f)) !== false) {
        if (count($row) < 3 || trim($row[0]) === '') { continue; }
        $feat = trim($row[0]);
        for ($c = 1; $c < count($header); $c++) {
            $sample = trim($header[$c]);
            $label = trim($pheno[$c] ?? '');
            if ($sample === '' || $label === '') { continue; }
            $val = is_numeric($row[$c]) ? (float) $row[$c] : null;
            if ($val === null) { continue; }
            $data[$feat][$label][] = $val;
        }
    }
    fclose($f);
    return $data;
};

// разметка фичи по m/z
$mzOf = function (string $feat): ?float {
    if (preg_match('#^[\d.]+/([\d.]+)$#', $feat, $m)) { return (float) $m[1]; }
    return null;
};

$challenge = []; // feat => [meta, cond -> mean, nullAdd, delta]
foreach ($MODES as $mode) {
    $data = $loadMode($mode);
    echo "$mode: фич ", count($data), "\n";
    foreach ($data as $feat => $byLabel) {
        $mz = $mzOf($feat);
        if ($mz === null) { continue; }
        // разметка
        $class = 'other'; $ident = '';
        foreach ($TARGETS as $name => [$neutral, $cl]) {
            foreach ([['+H', 1.00794], ['-H', -1.00794], ['M', 0.0]] as [$lab, $off]) {
                if (abs($mz - ($neutral + $off)) < 0.005) {
                    $class = $cl; $ident = "$name [$lab] $mode"; break 2;
                }
            }
        }
        // средние по условию: mean по репликам каждого Label (Label = Cond_rep)
        // но в этой таблице Label уже Cond_rep, значит byLabel[key][repVals]
        // пример: 'Bt_1' => [v]. Собираем cond -> [rep -> val]
        $condVals = [];
        foreach ($byLabel as $label => $vals) {
            // label вида Bt_1, BtCv_2, Full_3... — разделитель '_'
            $pos = strrpos($label, '_');
            if ($pos === false) { continue; }
            $cond = substr($label, 0, $pos);
            $condVals[$cond] = array_merge($condVals[$cond] ?? [], $vals);
        }
        // собираем времена отдельно из имени сэмпла? В этой таблице Label НЕ содержит время!
        // Проверим: Label=Bt_1..Bt_6 — это 6 повторов, а время скрыто в другом файле.
        // => для challenge берем только финальную точку? Нет: Label=Bt_1..Bt_6 это
        //    6 разных "Time-replicate" комбинаций. Уточним по сырым сэмплам.
        $challenge[$feat] = ['mode' => $mode, 'mz' => $mz, 'class' => $class, 'ident' => $ident, 'condVals' => $condVals];
    }
}
// В readyForMetaboanalyst Label = Bt_1..Bt_6 (6 повторов = 6 t x 4 rep? нет, 24 сэмпла Bt?)
// Уточняем: считаем число сэмплов Bt
$test = array_filter($challenge, fn ($c) => count($c['condVals']['Bt'] ?? []) > 0);
$first = reset($test);
echo "\nBt сэмплов: ", count($first['condVals']['Bt'] ?? []), " (ожидаем 24 = 6t x 4rep или 6)\n";

// АНАЛИЗ: время в этой таблице НЕ разделено. => берем IndSamples-файлы (там время в именах сэмплов)
echo "Переключаюсь на IndSamples файлы (там время в имени сэмпла)\n";
$challenge2 = [];
foreach ($MODES as $mode) {
    $path = $DIR . '/' . $mode . '_IndSamples_MetaboanalystNormalization.csv';
    if (!file_exists($path)) { continue; }
    $f = fopen($path, 'r');
    $header = fgetcsv($f);
    $samples = array_slice($header, 1);
    // структура: Sample имена вида 1mem_Bt_12pt5hr-1 => cond='1mem_Bt_12pt5hr', rep=1
    $feats = [];
    while (($row = fgetcsv($f)) !== false) {
        if (count($row) < 3 || trim($row[0]) === '') { continue; }
        $feat = trim($row[0]);
        $vals = [];
        for ($c = 1; $c < count($header); $c++) {
            $s = trim($header[$c]);
            if (!isset($row[$c]) || !is_numeric($row[$c])) { continue; }
            $vals[$s] = (float) $row[$c];
        }
        $feats[$feat] = $vals;
    }
    fclose($f);
    echo "$mode (IndSamples): фич ", count($feats), ", сэмплов ", count($samples), "\n";
    foreach ($feats as $feat => $vals) {
        preg_match('#^[\d.]+/([\d.]+)$#', $feat, $m);
        $mz = isset($m[1]) ? (float) $m[1] : null;
        if ($mz === null) { continue; }
        $class = 'other'; $ident = '';
        foreach ($TARGETS as $name => [$neutral, $cl]) {
            foreach ([['+H', 1.00794], ['-H', -1.00794], ['M', 0.0]] as [$lab, $off]) {
                if (abs($mz - ($neutral + $off)) < 0.005) {
                    $class = $cl; $ident = "$name [$lab]"; break 2;
                }
            }
        }
        // cond = всё до последнего '-', rep = число после '-'
        $byCond = [];
        foreach ($vals as $s => $v) {
            $pos = strrpos($s, '-');
            if ($pos === false) { continue; }
            $cond = substr($s, 0, $pos);
            $byCond[$cond][] = $v;
        }
        // для каждой пары (mono/pair/full) и времени: среднее
        // условия: 1mem_{Bt,Cv,Ps}_{T}, 2mem_{BtCv,BtPs,CvPs}_{T}, 3mem_Full_{T}
        foreach (['12pt5', '25', '30', '35', '40', '45'] as $t) {
            $mBt = $byCond["1mem_Bt_{$t}hr"] ?? [];
            $mCv = $byCond["1mem_Cv_{$t}hr"] ?? [];
            $mPs = $byCond["1mem_Ps_{$t}hr"] ?? [];
            $mBtCv = $byCond["2mem_BtCv_{$t}hr"] ?? [];
            $mBtPs = $byCond["2mem_BtPs_{$t}hr"] ?? [];
            $mCvPs = $byCond["2mem_CvPs_{$t}hr"] ?? [];
            $mFull = $byCond["3mem_Full_{$t}hr"] ?? [];
            if (count($mBt) < 2 || count($mCv) < 2 || count($mPs) < 2 ||
                count($mBtCv) < 2 || count($mBtPs) < 2 || count($mCvPs) < 2 || count($mFull) < 2) {
                continue;
            }
            $mean = fn (array $a) => array_sum($a) / count($a);
            $nullAdd = $mean($mBtCv) + $mean($mBtPs) + $mean($mCvPs) - $mean($mBt) - $mean($mCv) - $mean($mPs);
            // Замечание: аддитивный null авторов = СУММА трёх парных средних.
            // Для "концентрация-типа" величин корректный аддитивный прогноз парных
            // эффектов: mono_i + mono_j + (pair_ij - mono_i - mono_j) для каждой пары,
            // усреднённое: Full_pred = sum_mono + sum(pair - sum_mono) = sum(pair)/1... —
            // в коде авторов nullAdditive = sum(mean(pair_i)) БЕЗ вычитания mono. Мы
            // повторяем ИХ определение (sum of pair means) как главный вариант и
            // фиксируем альтернативу (interactions-only): sum(pair) - sum(mono).
            $delta = $mean($mFull) - $nullAdd;
            $challenge2[$feat][] = ['mode' => $mode, 'mz' => $mz, 'class' => $class, 'ident' => $ident,
                't' => $t, 'mono' => ['Bt' => $mean($mBt), 'Cv' => $mean($mCv), 'Ps' => $mean($mPs)],
                'pair' => ['BtCv' => $mean($mBtCv), 'BtPs' => $mean($mBtPs), 'CvPs' => $mean($mCvPs)],
                'full' => $mean($mFull), 'nullAdd' => $nullAdd, 'delta' => $delta,
                'nFull' => count($mFull)];
        }
    }
}
// статистика
$nPur = 0; $nPyr = 0; $nOther = 0; $nBgc = 0;
foreach ($challenge2 as $feat => $rows) {
    $cl = $rows[0]['class'];
    if ($cl === 'purine') { $nPur++; }
    elseif ($cl === 'pyrimidine') { $nPyr++; }
    else { $nOther++; }
}
echo "\n=== РАЗМЕТКА (challenge2) ===\n";
echo "purine-фич: $nPur | pyrimidine-фич: $nPyr | other: $nOther\n";
foreach ($challenge2 as $feat => $rows) {
    if ($rows[0]['class'] !== 'other') {
        printf("  %-28.28s %-12.12s %s  mz=%.4f\n", substr($feat, 0, 28), $rows[0]['class'], $rows[0]['ident'], $rows[0]['mz']);
    }
}
// сохраняем challenge в JSON
$payload = [];
foreach ($challenge2 as $feat => $rows) {
    foreach ($rows as $r) { $payload[] = $r + ['feat' => $feat]; }
}
file_put_contents($OUT . '/challenge_table.json', json_encode($payload, JSON_PRETTY_PRINT));
echo "\nсохранено: Benchmarks/chodkowski/challenge_table.json (", count($payload), " строк feat x t)\n";
