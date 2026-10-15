<?php

// PROBE-0: KNOWLEDGE → ENVIRONMENT — драйвер (см. PREREG.md + AMENDMENT.md v2).
//
// Режимы:
//   calibrate <devJson> <outDir>                — подбор бюджета на DEV (A-рука), H не читается
//   discover  <r> <discoveryJson> <outDir>      — открытие F/R + compress + извлечение атомов
//   test      <arm> <r> <hJson> <outDir> <budget> — тестовая фаза руки (arm ∈ A|B|C)
//
// Изоляция: каждая фаза — свой sqlite-файл; прод-БД никогда не открывается.
// Регистр компонента = строки grammar_ops тестовой БД (A: 0, B: BW-атомы, C: случайные).
// Вылеты — подпроцессы flight.php (env-изоляция + подсчёт кандидатов через [SD] exprs).

$mode = $argv[1] ?? '';
$AUTOLOAD = __DIR__ . '/../../vendor/autoload.php';

if ($mode === 'calibrate') {
    [$devJson, $outDir] = [$argv[2] ?? '', $argv[3] ?? ''];
    @mkdir($outDir, 0777, true);
    mt_srand(9001);
    require_once $AUTOLOAD;
    $dev = json_decode(file_get_contents($devJson), true);
    $chosen = null;
    $perBudget = [];
    // Двухзонная калибровка (A1 v5): EARLY-задачи — sanity (A сертифицирует
    // при достаточном бюджете), LATE-задачи — структурный headroom (A
    // отрицательна при любом бюджете — факторы вне L2-пула).
    foreach ([25.0] as $budget) {
        // Бюджет фиксирован СНИЗУ стоимостью компонента: bornBinary L1-проход
        // ≈16s при 80 строках (parse-per-call) — бюджет должен покрывать его
        // с запасом. Калибровка = ВЕРИФИКАЦИЯ двух зон при этом бюджете
        // (EARLY-sanity + LATE-headroom), не тюнинг (H не участвует).
        $dbF = "$outDir/db_calib_" . ((int) $budget) . '.sqlite';
        @unlink($dbF);
        putenv("SWARM_DB_PATH=$dbF");
        // Миграции выполняет flight.php при первом открытии своей БД.
        // Driver сам Database::get() не зовёт (singleton пережил бы смену пути).
        $certEarly = 0; $negLate = 0; $negAll = 0;
        foreach ($dev['replicates']['1'] as $t) {
            $u = probe0_run_task($t, $outDir, $dbF, $budget, 'calib');
            $isLate = (($t['zone'] ?? '') === 'late');
            if ($u['certified']) {
                if (! $isLate) { $certEarly++; }
            } else {
                $negAll++;
                if ($isLate) { $negLate++; }
            }
        }
        $perBudget[] = ['budget' => $budget, 'cert_early' => $certEarly, 'neg_late' => $negLate, 'neg_all' => $negAll];
        echo "[calibrate] budget={$budget}s certEarly={$certEarly}/4 negLate={$negLate}/4 negAll={$negAll}/8\n";
        // Sanity: A решает EARLY-задачи при этом бюджете; LATE отрицательны все (структурный headroom)
        if ($certEarly >= 3 && $negLate >= 4) { $chosen = $budget; break; }
    }
    if ($chosen === null) { $chosen = 25.0; echo "[calibrate] критерий не достигнут → 25s (риски фиксируются в отчёте)\n"; }
    file_put_contents("$outDir/calibration.json", json_encode(['budget' => $chosen, 'per_budget' => $perBudget], JSON_PRETTY_PRINT));
    echo "BUDGET_CHOSEN={$chosen}\n";
    exit(0);
}

if ($mode === 'discover') {
    $r = (string) ($argv[2] ?? ''); $famJson = $argv[3] ?? ''; $outDir = $argv[4] ?? '';
    @mkdir($outDir, 0777, true);
    mt_srand((int) hexdec(substr(md5('PROBE0DISC' . $r), 0, 7)));
    $fam = json_decode(file_get_contents($famJson), true);
    $dbF = "$outDir/db_discovery_r$r.sqlite";
    @unlink($dbF);
    putenv("SWARM_DB_PATH=$dbF");
    putenv('SEARCH_NO_PREREG=1');
    require_once $AUTOLOAD;
    $db = BeeSwarm\Infra\Database::get();
    $t0 = microtime(true);
    $laws = [];
    foreach ($fam['replicates'][$r] as $t) {
        $u = probe0_run_task($t, $outDir, $dbF, 60.0, "disc_r$r");
        if ($u['certified']) {
            // Закон — только в laws (источник компрессора); grammar_ops не засоряем.
            // Формула конвертируется в xN-имена ДО записи — компрессор группирует
            // по generic-маскам (паттерн exp044 record_law)
            $xF = probe0_labels_to_x($u['formula']);
            $db->prepare('INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
                ->execute(['law_' . substr(md5($xF . $t['id']), 0, 10), $xF, $u['cv'], $t['id']]);
            $laws[] = ['task' => $t['id'], 'formula' => $xF, 'cv' => $u['cv']];
        }
    }
    // Создание компонента: компрессор (≥2 изоморфа из ≥2 доменов → BW-атом)
    $bornBefore = (int) $db->query("SELECT COUNT(*) FROM grammar_ops WHERE source='birth'")->fetchColumn();
    (new BeeSwarm\Hive\LawIsomorphismCompressor())->compress(null);
    $rows = $db->query("SELECT name, definition FROM grammar_ops WHERE source='birth' AND definition IS NOT NULL AND definition != ''")->fetchAll(PDO::FETCH_ASSOC);
    $atoms = [];
    foreach ($rows as $row) {
        preg_match_all('/x(\d+)/', (string) $row['definition'], $mm);
        $atoms[] = ['name' => $row['name'], 'definition' => $row['definition'],
            'arity' => count(array_unique($mm[1])), 'tokens' => strlen((string) $row['definition'])];
    }
    $out = ['replicate' => $r, 'laws' => $laws, 'atoms' => $atoms,
        'born_count' => count($atoms), 'wall' => round(microtime(true) - $t0, 1),
        'mem_peak' => memory_get_peak_usage(true)];
    file_put_contents("$outDir/components_r$r.json", json_encode($out, JSON_PRETTY_PRINT));
    echo "[discover r$r] laws=" . count($laws) . " atoms=" . count($atoms) . " wall={$out['wall']}s\n";
    exit(0);
}

if ($mode === 'test') {
    [$arm, $r, $hJson, $outDir, $budget] = [$argv[2] ?? '', (string) ($argv[3] ?? ''), $argv[4] ?? '', $argv[5] ?? '', (float) ($argv[6] ?? 0)];
    if (! in_array($arm, ['A', 'B', 'C'], true) || $budget <= 0) { fwrite(STDERR, "usage: test <A|B|C> <r> <hJson> <outDir> <budget>\n"); exit(2); }
    @mkdir($outDir, 0777, true);
    mt_srand((int) hexdec(substr(md5("PROBE0TEST{$arm}{$r}"), 0, 7)));
    $comp = json_decode(file_get_contents("$outDir/components_r$r.json"), true);
    $dbF = "$outDir/db_{$arm}_r$r.sqlite";
    @unlink($dbF);
    putenv("SWARM_DB_PATH=$dbF");
    require_once $AUTOLOAD;
    $db = BeeSwarm\Infra\Database::get();
    $t0 = microtime(true);

    // Знание (все руки): законы discovery
    foreach ($comp['laws'] as $l) {
        $db->prepare('INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['law_' . substr(md5($l['formula'] . $l['task']), 0, 10), $l['formula'], $l['cv'], $l['task']]);
    }
    // Регистр компонента
    $register = [];
    if ($arm === 'B') {
        foreach ($comp['atoms'] as $a) {
            $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, birth_domain) VALUES (?, 'birth', ?, 'probe0')")
                ->execute([$a['name'], $a['definition']]);
            $register[] = $a['name'];
        }
    } elseif ($arm === 'C') {
        foreach ($comp['atoms'] as $i => $a) {
            $def = probe0_random_component((int) $a['arity'], (int) $a['tokens'], "PROBE0C{$r}{$i}");
            $name = 'BC' . substr(md5($def . $r . $i), 0, 8);
            $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, birth_domain) VALUES (?, 'birth', ?, 'probe0')")
                ->execute([$name, $def]);
            $register[] = $name;
        }
    }
    $regCount = (int) $db->query("SELECT COUNT(*) FROM grammar_ops WHERE source='birth'")->fetchColumn();

    // Тестовая фаза
    $h = json_decode(file_get_contents($hJson), true);
    $units = [];
    foreach ($h['replicates'][$r] as $t) {
        $u = probe0_run_task($t, $outDir, $dbF, $budget, "test_{$arm}_r$r", $register);
        $u['id'] = $t['id']; $u['class'] = $t['class']; $u['null'] = (bool) ($t['null_shuffle'] ?? false);
        $u['zone'] = (string) ($t['zone'] ?? '');
        $u['bw_used'] = $u['certified'] && preg_match('/B[0-9a-f]{6,}/', $u['formula']) === 1;
        $units[] = $u;
    }
    $cert = count(array_filter($units, fn ($u) => $u['certified'] && ! $u['null']));
    $nullCert = count(array_filter($units, fn ($u) => $u['certified'] && $u['null']));
    $out = ['arm' => $arm, 'replicate' => $r, 'budget' => $budget,
        'register_count' => $regCount, 'register' => $register,
        'units' => $units, 'certified' => $cert, 'null_certified' => $nullCert,
        'wall' => round(microtime(true) - $t0, 1), 'mem_peak' => memory_get_peak_usage(true)];
    file_put_contents("$outDir/results_{$arm}_r$r.json", json_encode($out, JSON_PRETTY_PRINT));
    echo "[test $arm r$r] cert={$cert}/6 nullCert={$nullCert}/2 register={$regCount} wall={$out['wall']}s\n";
    exit(0);
}

fwrite(STDERR, "usage: driver.php <calibrate|discover|test> ...\n");
exit(2);

// ---------------- общие функции ----------------

function probe0_run_task(array $t, string $outDir, string $dbF, float $budget, string $tag, array $register = []): array
{
    $csv = sys_get_temp_dir() . '/probe0_' . md5($tag . $t['id']) . '.csv';
    probe0_gen_family($t, $csv);
    [$Xall, $yall] = probe0_parse_csv($csv);
    [$X, $y, $Xe, $ye] = probe0_split_eval($Xall, $yall, $t['seed']);
    $labels = ['c0', 'c1', 'c2', 'c3', 'c4'];
    $t0 = microtime(true);
    $flights = [];
    $found = false; $formula = 'none'; $cv = 9.99; $tFound = null; $cands = 0; $candRows = 0;
    foreach ([1, 2, 3] as $depth) {
        // depth-1 ПЕРВЫЙ для всех рук (равные правила): B-форма атома живёт на L1 —
        // при depth≥2 bornBinary-применение в pair-циклах взрывается (parse-per-call
        // в ExpressionEvaluator, AST-кэша на этом пути нет) и до exact-скана дело
        // не доходит (TIMEOUT). Стоимость использования компонента = часть измерения.
        $remain = $budget - (microtime(true) - $t0);
        if ($remain < 2.0) { break; }
        $cfg = ['db_path' => $dbF, 'autoload' => __DIR__ . '/../../vendor/autoload.php',
            'ops' => array_keys(BeeSwarm\Core\Grammar::BASE_OPS), 'register_ops' => $register,
            'data' => [$X, $y], 'labels' => $labels, 'depth' => $depth,
            'eps' => 0.10, 'test_ratio' => 0.2, 'budget_s' => $remain];
        $fr = probe0_flight($cfg, "{$tag}_{$t['id']}_d$depth", $outDir);
        $flights[] = $fr;
        $cands += (int) ($fr['candidates']['exprs'] ?? 0);
        $candRows += (int) ($fr['candidates']['plausible'] ?? 0);
        if ($fr['found']) {
            $found = true; $formula = (string) $fr['formula']; $cv = (float) $fr['cv'];
            $tFound = round(microtime(true) - $t0, 1);
            break;
        }
    }
    // Строгая сертификация (неизменная): независимый withheld 40%
    $certified = false; $cvE = null;
    if ($found) {
        $cvE = probe0_cert_x(probe0_labels_to_x($formula), $Xe, $ye);
        $certified = $cvE !== null && $cvE <= 0.10;
    }
    @unlink($csv);

    return ['found' => $found, 'certified' => $certified, 'cv_eval' => $cvE, 'cv_engine' => $cv, 'cv' => $cv,
        'formula' => $formula, 't_first_found' => $tFound, 'flights' => count($flights),
        'candidates' => $cands, 'plausible_rows' => $candRows,
        'wall' => round(microtime(true) - $t0, 1)];
}

function probe0_flight(array $cfg, string $tag, string $outDir): array
{
    $cfgFile = "$outDir/cfg_$tag.json";
    file_put_contents($cfgFile, json_encode($cfg));
    $errFile = "$outDir/err_$tag.txt";
    $pipes = [];
    $proc = proc_open('php ' . escapeshellarg(__DIR__ . '/flight.php') . ' ' . escapeshellarg($cfgFile),
        [['pipe', 'r'], ['pipe', 'w'], ['file', $errFile, 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);
    $res = json_decode((string) $out, true);
    if (! is_array($res)) { $res = ['found' => false, 'cv' => 9.99, 'formula' => 'none', 'wall' => 0.0, 'error' => 'flight crash: see err file']; }
    $res['candidates'] = null;
    if (is_file($errFile)) {
        foreach (file($errFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/\[SD\] exprs=(\d+) plausible=(\d+)/', $line, $m) === 1) {
                $res['candidates'] = ['exprs' => (int) $m[1], 'plausible' => (int) $m[2]];
            }
        }
        @unlink($errFile);
    }
    @unlink($cfgFile);

    return $res;
}

function probe0_random_component(int $arity, int $targetLen, string $seedKey): string
{
    mt_srand((int) hexdec(substr(md5($seedKey), 0, 7)));
    $ops = ['+', '×', '−', '/'];
    $best = null;
    for ($try = 0; $try < 120; $try++) {
        // дерево: терминалы x0..x(arity-1), каждый ≥1 раз; операции случайны
        $terms = [];
        for ($i = 0; $i < $arity; $i++) { $terms[] = "x$i"; }
        $expr = $terms[array_rand($terms)];
        $used = [$expr => true];
        $guard = 0;
        while (strlen($expr) < $targetLen * 0.85 && $guard++ < 40) {
            $op = $ops[array_rand($ops)];
            if (count($used) < $arity && mt_rand(0, 1) === 0) {
                foreach ($terms as $term) {
                    if (! isset($used[$term])) { $expr = '(' . $expr . $op . $term . ')'; $used[$term] = true; break; }
                }
            } else {
                $expr = '(' . $expr . $op . $terms[array_rand($terms)] . ')';
            }
        }
        if ($best === null || abs(strlen($expr) - $targetLen) < abs(strlen($best) - $targetLen)) { $best = $expr; }
        if (abs(strlen($expr) - $targetLen) <= 0.2 * max(1, $targetLen)) { return $expr; }
    }

    return (string) $best;
}

function probe0_gen_family(array $p, string $path): void
{
    $s = $p['seed'];
    $r = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7fffffff; return $s / 0x7fffffff; };
    $u = function (float $a, float $b) use ($r): float { return $a + $r() * ($b - $a); };
    $rows = [];
    for ($i = 0; $i < 80; $i++) {
        $c = [$u(1, 3), $u(2, 5), $u(3, 7), $u(0.5, 2), $u(0, 9)];
        switch ($p['class']) {
            case 'flux': $y = 2.0 * ($c[2] - $c[1]) * $c[0] / $c[3]; break;
            case 'diffprod': $y = 1.5 * ($c[1] - $c[0]) * $c[0]; break;
            case 'diffsq': $y = ($c[0] - $c[1]) * ($c[0] + $c[1]); break;
            case 'sdr': $y = ($c[0] + $c[1]) / ($c[0] - $c[1]); break;
            case 'ratio': $y = ($c[0] * $c[1]) / $c[3]; break;
            case 'composition': $y = ($c[0] - $c[1]) * ($c[0] - $c[1]) + $c[2] * $c[3]; break;
            case 'pureprod': $y = $c[0] * $c[3]; break;
            default: $y = 0.0;
        }
        if (! empty($p['noise'])) { $y *= 1 + ($r() - 0.5) * $p['noise']; }
        $row = [];
        foreach ($p['perm'] as $src) { $row[] = $c[$src]; }
        $row[] = $y;
        $rows[] = $row;
    }
    if (! empty($p['null_shuffle'])) {
        mt_srand($p['seed']);
        $ys = array_column($rows, 5);
        for ($i = count($ys) - 1; $i > 0; $i--) {
            $j = (int) floor(mt_rand() / (mt_getrandmax() + 1) * ($i + 1));
            [$ys[$i], $ys[$j]] = [$ys[$j], $ys[$i]];
        }
        foreach ($rows as $i => $row) { $rows[$i][5] = $ys[$i]; }
    }
    $f = fopen($path, 'w');
    foreach ($rows as $r2) { fputcsv($f, $r2); }
    fclose($f);
}

function probe0_parse_csv(string $path): array
{
    $X = []; $y = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $v = str_getcsv($line);
        if (count($v) < 6 || ! is_numeric($v[0])) { continue; }
        $X[] = array_map('floatval', array_slice($v, 0, 5));
        $y[] = (float) $v[5];
    }

    return [$X, $y];
}

function probe0_split_eval(array $X, array $y, int $seed): array
{
    $n = count($y); $idx = range(0, $n - 1);
    mt_srand($seed);
    for ($i = $n - 1; $i > 0; $i--) {
        $j = (int) floor(mt_rand() / (mt_getrandmax() + 1) * ($i + 1));
        [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]];
    }
    $k = (int) floor($n * 0.6);
    $Xt = []; $yt = []; $Xe = []; $ye = [];
    foreach (array_slice($idx, 0, $k) as $i) { $Xt[] = $X[$i]; $yt[] = $y[$i]; }
    foreach (array_slice($idx, $k) as $i) { $Xe[] = $X[$i]; $ye[] = $y[$i]; }

    return [$Xt, $yt, $Xe, $ye];
}

function probe0_labels_to_x(string $formula): string
{
    // BW/BC-имена (hex может содержать 'c0') прячутся, метки заменяются, имена возвращаются
    $kept = [];
    $out = (string) preg_replace_callback('/B[0-9a-f]{6,}/', function ($m) use (&$kept) {
        $key = "\x00" . count($kept) . "\x00"; $kept[$key] = $m[0]; return $key;
    }, $formula);
    $pairs = [];
    foreach (range(0, 4) as $i) { $pairs[] = ['c' . $i, 'x' . $i]; }
    usort($pairs, fn ($a, $b) => strlen($b[0]) <=> strlen($a[0]));
    foreach ($pairs as [$c, $x]) { $out = str_replace($c, $x, $out); }

    return strtr($out, $kept);
}

function probe0_cert_x(string $formulaX, array $Xe, array $ye): ?float
{
    $vals = BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($formulaX, $Xe);
    if (! is_array($vals) || count($vals) !== count($ye)) { return null; }
    $ratios = [];
    foreach ($vals as $i => $p) {
        if ($ye[$i] == 0.0 || ! is_numeric($p) || ! is_finite((float) $p)) { return null; }
        $ratios[] = (float) $p / $ye[$i];
    }
    $mean = array_sum($ratios) / count($ratios);
    $var = 0.0;
    foreach ($ratios as $rv) { $var += ($rv - $mean) ** 2; }
    $std = sqrt($var / count($ratios));

    return $mean != 0.0 ? $std / abs($mean) : null;
}
