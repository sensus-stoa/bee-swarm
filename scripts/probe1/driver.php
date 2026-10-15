<?php

// PROBE-1: TOOL → DISCOVERY → TOOL — DEV mechanistic gate (см. CHECKPOINT_A.md v0 + owner-review).
//
// Режимы:
//   g1 <r>                     — discovery sdr-EARLY → компрессор → T₁ (процедура PROBE-0)
//   g2 <A|B|C> <r>             — G2-линия: сертификация sdrplusdiff-законов + попытка рождения T₂
//   g3 <b1|b2|b3> <r>          — G3-линия: маргинальная ценность T₂ сверх T₁
//   probe <r>                  — функциональная эквивалентность T₂/T₁ + dependency-ассерт
//
// Класс sdrplusdiff СОЗНАТЕЛЬНО композиционный (y = sdr(c0,c1) + (c0−c2)) —
// открытая документация, DEV-гейт механики, не подтверждающий эксперимент.
// Регистры: T₁/T₂ строки grammar_ops per-arm БД; прод-БД не открывается.

$mode = $argv[1] ?? '';
$AUTOLOAD = __DIR__ . '/../../vendor/autoload.php';
$BUDGET = 300.0; // покрывает bornBinary L1-проход (~9s) + L2-фазу с атомом (~280s, parse-per-call).
                 // Одинаков для всех рук; A/C не выражают закон и на нём (структурно) —
                 // бюджет снят снизу стоимостью компонента, не подобран под результат.
$T1NAME = 'BW5737967f9b4ecb628fd48d7d82502f40'; // sdr-атом PROBE-0 (детерминирован сидами G1)

// ---------- общие функции (адаптация probe0) ----------

function p1_gen_family(array $p, string $path): void
{
    $s = $p['seed'];
    $r = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7fffffff; return $s / 0x7fffffff; };
    $u = function (float $a, float $b) use ($r): float { return $a + $r() * ($b - $a); };
    $rows = [];
    for ($i = 0; $i < 80; $i++) {
        $c = [$u(1, 3), $u(4, 6), $u(3, 7), $u(0.5, 2), $u(0, 9)];
        // c1 ∈ [4,6] НЕ пересекается с c0 ∈ [1,3]: знаменатель sdr (c0−c1) не
        // проходит через ноль → без спайк-доминирования (псевдозакон-дефект 15.10)
        switch ($p['class']) {
            case 'sdr': $y = ($c[0] + $c[1]) / ($c[0] - $c[1]); break;
            case 'sdrplusdiff': $y = ($c[0] + $c[1]) / ($c[0] - $c[1]) + ($c[0] - $c[2]); break;
            default: $y = 0.0;
        }
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

function p1_parse_csv(string $path): array
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

function p1_split_eval(array $X, array $y, int $seed): array
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

function p1_labels_to_x(string $formula): string
{
    // BW-токены = 'BW' + md5 (ровно 32 hex, детерминированы компрессором):
    // точная длина НЕ съедает следующий лейбл ('...c1' после токена —
    // операнд, а не часть токена; жадный [0-9a-fW]{6,} давал отравленные
    // определения T₂ — дефект 15.10).
    $kept = [];
    $out = (string) preg_replace_callback('/BW[0-9a-f]{32}/', function ($m) use (&$kept) {
        $key = "\x00" . count($kept) . "\x00"; $kept[$key] = $m[0]; return $key;
    }, $formula);
    $pairs = [];
    foreach (range(0, 4) as $i) { $pairs[] = ['c' . $i, 'x' . $i]; }
    usort($pairs, fn ($a, $b) => strlen($b[0]) <=> strlen($a[0]));
    foreach ($pairs as [$c, $x]) { $out = str_replace($c, $x, $out); }

    return strtr($out, $kept);
}

function p1_cert_x(string $formulaX, array $Xe, array $ye): ?float
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

function p1_flight(array $cfg, string $tag, string $outDir): array
{
    $cfgFile = "$outDir/cfg_$tag.json";
    file_put_contents($cfgFile, json_encode($cfg));
    $errFile = "$outDir/err_$tag.txt";
    $pipes = [];
    $proc = proc_open('php ' . escapeshellarg(__DIR__ . '/../probe0/flight.php') . ' ' . escapeshellarg($cfgFile),
        [['pipe', 'r'], ['pipe', 'w'], ['file', $errFile, 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);
    $res = json_decode((string) $out, true);
    if (! is_array($res)) { $res = ['found' => false, 'cv' => 9.99, 'formula' => 'none', 'wall' => 0.0, 'error' => 'crash']; }
    $res['candidates'] = null;
    $keepErr = ! is_array($res);
    if (is_file($errFile)) {
        foreach (file($errFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/\[SD\] exprs=(\d+) plausible=(\d+)/', $line, $m) === 1) {
                $res['candidates'] = ['exprs' => (int) $m[1], 'plausible' => (int) $m[2]];
            }
        }
        if (! $keepErr) { @unlink($errFile); }
    }
    @unlink($cfgFile);

    return $res;
}

function p1_run_task(array $t, string $outDir, string $dbF, float $budget, string $tag, array $register): array
{
    $csv = sys_get_temp_dir() . '/probe1_' . md5($tag . $t['id']) . '.csv';
    p1_gen_family($t, $csv);
    [$Xall, $yall] = p1_parse_csv($csv);
    [$X, $y, $Xe, $ye] = p1_split_eval($Xall, $yall, $t['seed']);
    $labels = ['c0', 'c1', 'c2', 'c3', 'c4'];
    $t0 = microtime(true);
    $found = false; $formula = 'none'; $cv = 9.99; $tFound = null; $cands = 0; $nFlights = 0; $diags = [];
    $certified = false; $cvE = null;
    foreach ([1, 2, 3] as $depth) {
        $remain = $budget - (microtime(true) - $t0);
        if ($remain < 2.0) { break; }
        $cfg = ['db_path' => $dbF, 'autoload' => __DIR__ . '/../../vendor/autoload.php',
            'ops' => array_keys(BeeSwarm\Core\Grammar::BASE_OPS), 'register_ops' => $register,
            'data' => [$X, $y], 'labels' => $labels, 'depth' => $depth,
            'eps' => 0.10, 'test_ratio' => 0.2, 'budget_s' => $remain];
        $fr = p1_flight($cfg, "{$tag}_{$t['id']}_d$depth", $outDir);
        $nFlights++;
        $cands += (int) ($fr['candidates']['exprs'] ?? 0);
        $diags[] = $fr['diagnosis'] ?? ($fr['error'] ?? '?');
        if (! empty($fr['found'])) {
            $formula = (string) $fr['formula']; $cv = (float) $fr['cv'];
            // Сертификация = ДВИЖКОВЫЙ вердикт (exact → cv=0; внутри движка:
            // heldout testRatio + A19 baseline + non-constancy). Внешний naive
            // ratio-cv взрывается на zero-crossing y (урок M1-EXT) — только
            // диагностика, не гейт. Дегенераты (c0/c1, движковый cv 0.046-0.10)
            // отсекаются гейтом 0.01; эскалация продолжается (дефект 15.10).
            $cvE = p1_cert_x(p1_labels_to_x($formula), $Xe, $ye);
            if ($cv <= 0.01) {
                $found = true; $certified = true; $tFound = round(microtime(true) - $t0, 1);
                break;
            }
        }
    }
    if ($found && $cvE === null) {
        // found без внешней диагностики (не должно случаться; страховка)
        $cvE = p1_cert_x(p1_labels_to_x($formula), $Xe, $ye);
    }
    @unlink($csv);

    return ['found' => $found, 'certified' => $certified, 'cv_eval' => $cvE, 'cv' => $cv,
        'formula' => $formula, 't_first_found' => $tFound, 'flights' => $nFlights,
        'candidates' => $cands, 'diagnoses' => $diags, 'wall' => round(microtime(true) - $t0, 1),
        'n_rows' => count($X), 'n_feat' => count($X[0] ?? [])];
}

function p1_random_component(int $arity, int $targetLen, string $seedKey): string
{
    mt_srand((int) hexdec(substr(md5($seedKey), 0, 7)));
    $ops = ['+', '×', '−', '/'];
    $best = null;
    for ($try = 0; $try < 120; $try++) {
        $terms = [];
        for ($i = 0; $i < $arity; $i++) { $terms[] = "x$i"; }
        $expr = $terms[array_rand($terms)];
        $guard = 0;
        while (strlen($expr) < $targetLen * 0.85 && $guard++ < 40) {
            $op = $ops[array_rand($ops)];
            $expr = '(' . $expr . $op . $terms[array_rand($terms)] . ')';
        }
        if ($best === null || abs(strlen($expr) - $targetLen) < abs(strlen($best) - $targetLen)) { $best = $expr; }
        if (abs(strlen($expr) - $targetLen) <= 0.2 * max(1, $targetLen)) { return $expr; }
    }

    return (string) $best;
}

/** Арность определения (distinct xN). */
function p1_arity(string $def): int
{
    preg_match_all('/x(\d+)/', $def, $m);

    return count(array_unique($m[1]));
}

// ---------- режимы ----------

if ($mode === 'g1') {
    $r = (string) ($argv[2] ?? '1');
    $fam = json_decode(file_get_contents(__DIR__ . '/families/g1.json'), true);
    $outDir = $argv[3] ?? '/tmp/probe1_run';
    @mkdir($outDir, 0777, true);
    $dbF = "$outDir/db_g1_r$r.sqlite";
    @unlink($dbF);
    putenv("SWARM_DB_PATH=$dbF");
    putenv('SEARCH_NO_PREREG=1');
    require_once $AUTOLOAD;
    $db = BeeSwarm\Infra\Database::get();
    $t0 = microtime(true);
    $laws = [];
    $flightLog = [];
    foreach ($fam['replicates'][$r] as $t) {
        $u = p1_run_task($t, $outDir, $dbF, $BUDGET, "g1_r$r", []);
        $flightLog[] = ['id' => $t['id'], 'found' => $u['found'], 'certified' => $u['certified'],
            'flights' => $u['flights'], 'diagnoses' => $u['diagnoses'], 'cands' => $u['candidates'],
            'wall' => $u['wall'], 'formula' => $u['formula'], 'n_rows' => $u['n_rows'], 'n_feat' => $u['n_feat']];
        if ($u['certified']) {
            $xF = p1_labels_to_x($u['formula']);
            $db->prepare('INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
                ->execute(['law_' . substr(md5($xF . $t['id']), 0, 10), $xF, $u['cv'], $t['id']]);
            $laws[] = ['task' => $t['id'], 'formula' => $xF, 'cv' => $u['cv']];
        }
    }
    (new BeeSwarm\Hive\LawIsomorphismCompressor())->compress(null);
    $atoms = [];
    foreach ($db->query("SELECT name, definition FROM grammar_ops WHERE source='birth' AND definition IS NOT NULL AND definition != ''")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $atoms[] = ['name' => $row['name'], 'definition' => $row['definition'], 'arity' => p1_arity((string) $row['definition']), 'tokens' => strlen((string) $row['definition'])];
    }
    $out = ['phase' => 'G1', 'replicate' => $r, 'laws' => $laws, 'atoms' => $atoms,
        'flight_log' => $flightLog,
        'wall' => round(microtime(true) - $t0, 1), 'mem_peak' => memory_get_peak_usage(true)];
    file_put_contents("$outDir/g1_r$r.json", json_encode($out, JSON_PRETTY_PRINT));
    echo "[g1 r$r] laws=" . count($laws) . " atoms=" . count($atoms) . " wall={$out['wall']}s\n";
    foreach ($atoms as $a) { echo "  T1: {$a['name']} arity={$a['arity']} def={$a['definition']}\n"; }
    exit(0);
}

if ($mode === 'g2') {
    $arm = $argv[2] ?? ''; $r = (string) ($argv[3] ?? '1');
    if (! in_array($arm, ['A', 'B', 'C'], true)) { fwrite(STDERR, "arm ∈ A|B|C\n"); exit(2); }
    $outDir = $argv[4] ?? '/tmp/probe1_run';
    $g1 = json_decode(file_get_contents("$outDir/g1_r$r.json"), true);
    $t1 = $g1['atoms'][0] ?? null;
    if ($t1 === null) { echo "[g2 $arm r$r] SKIP: T1 не рождён в G1 (kill condition)\n"; exit(0); }
    $fam = json_decode(file_get_contents(__DIR__ . '/families/g2.json'), true);
    $dbF = "$outDir/db_g2_{$arm}_r$r.sqlite";
    @unlink($dbF);
    putenv("SWARM_DB_PATH=$dbF");
    putenv('SEARCH_NO_PREREG=1');
    require_once $AUTOLOAD;
    $db = BeeSwarm\Infra\Database::get();
    $t0 = microtime(true);
    $register = [];
    if ($arm === 'B') {
        $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, birth_domain) VALUES (?, 'birth', ?, 'probe1_g1')")
            ->execute([$t1['name'], $t1['definition']]);
        $register[] = $t1['name'];
    } elseif ($arm === 'C') {
        $def = p1_random_component((int) $t1['arity'], (int) $t1['tokens'], "PROBE1SHAM$r");
        $shamAssert = p1_arity($def) === (int) $t1['arity'] ? 'OK' : 'ARITY_MISMATCH';
        $name = 'BC' . substr(md5($def . $r), 0, 8);
        $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, birth_domain) VALUES (?, 'birth', ?, 'probe1_sham')")
            ->execute([$name, $def]);
        $register[] = $name;
        echo "[g2 C r$r] sham=$name arity=" . p1_arity($def) . " (target {$t1['arity']}) assert=$shamAssert def=$def\n";
    }
    $regCount = (int) $db->query("SELECT COUNT(*) FROM grammar_ops WHERE source='birth'")->fetchColumn();
    $laws = [];
    $flightLog = [];
    foreach ($fam['replicates'][$r] as $t) {
        $u = p1_run_task($t, $outDir, $dbF, $BUDGET, "g2_{$arm}_r$r", $register);
        $flightLog[] = ['id' => $t['id'], 'found' => $u['found'], 'certified' => $u['certified'],
            'flights' => $u['flights'], 'diagnoses' => $u['diagnoses'], 'cands' => $u['candidates'],
            'wall' => $u['wall'], 'formula' => $u['formula']];
        if ($u['certified']) {
            $xF = p1_labels_to_x($u['formula']);
            $db->prepare('INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
                ->execute(['law_' . substr(md5($xF . $t['id']), 0, 10), $xF, $u['cv'], $t['id']]);
            $laws[] = ['task' => $t['id'], 'formula' => $xF, 'cv' => $u['cv']];
        }
    }
    // Рождение T₂ штатным компрессором (не вставляется вручную)
    $pre = array_map(fn ($row) => $row['name'], $db->query("SELECT name FROM grammar_ops WHERE source='birth'")->fetchAll(PDO::FETCH_ASSOC));
    // Компрессор в СУБПРОЦЕССЕ (свежий процесс рождает детерминированно;
    // ин-процессный вызов после полётов молча не рожал — см. compress.php)
    $pipes = [];
    $proc = proc_open('php ' . escapeshellarg(__DIR__ . '/compress.php') . ' ' . escapeshellarg($dbF),
        [['pipe', 'r'], ['pipe', 'w'], ['file', "$outDir/compress_$arm$r.err", 'w']], $pipes);
    $bornOut = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);
    echo "[dbg g2 $arm r$r] laws_at_compress=2 birth_ops_before=" . count($pre) . " compress_born=" . trim((string) $bornOut) . PHP_EOL;
    $t2 = null;
    foreach ($db->query("SELECT name, definition FROM grammar_ops WHERE source='birth'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (in_array($row['name'], $pre, true)) { continue; } // только НОВЫЕ атомы
        $t2 = ['name' => $row['name'], 'definition' => $row['definition'], 'arity' => p1_arity((string) $row['definition']),
            'tokens' => strlen((string) $row['definition']),
            'contains_T1' => strpos((string) $row['definition'], $t1['name']) !== false];
    }
    $out = ['phase' => 'G2', 'arm' => $arm, 'replicate' => $r, 'register' => $register, 'reg_count' => $regCount,
        'laws' => $laws, 'T2' => $t2, 'flight_log' => $flightLog, 'wall' => round(microtime(true) - $t0, 1), 'mem_peak' => memory_get_peak_usage(true)];
    file_put_contents("$outDir/g2_{$arm}_r$r.json", json_encode($out, JSON_PRETTY_PRINT));
    echo "[g2 $arm r$r] laws=" . count($laws) . " T2=" . ($t2 !== null ? $t2['name'] . ' (contains_T1=' . var_export($t2['contains_T1'], true) . ' arity=' . $t2['arity'] . ')' : 'NOT_BORN') . " wall={$out['wall']}s\n";
    exit(0);
}

if ($mode === 'g3') {
    $arm3 = $argv[2] ?? ''; $r = (string) ($argv[3] ?? '1');
    if (! in_array($arm3, ['b1', 'b2', 'b3'], true)) { fwrite(STDERR, "arm3 ∈ b1|b2|b3\n"); exit(2); }
    $outDir = $argv[4] ?? '/tmp/probe1_run';
    $g1 = json_decode(file_get_contents("$outDir/g1_r$r.json"), true);
    $t1 = $g1['atoms'][0] ?? null;
    if ($t1 === null) { echo "[g3 $arm3 r$r] SKIP: no T1\n"; exit(0); }
    $g2 = json_decode(file_get_contents("$outDir/g2_B_r$r.json"), true);
    $t2 = $g2['T2'] ?? null;
    $fam = json_decode(file_get_contents(__DIR__ . '/families/g3.json'), true);
    $dbF = "$outDir/db_g3_{$arm3}_r$r.sqlite";
    @unlink($dbF);
    putenv("SWARM_DB_PATH=$dbF");
    putenv('SEARCH_NO_PREREG=1');
    require_once $AUTOLOAD;
    $db = BeeSwarm\Infra\Database::get();
    $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, birth_domain) VALUES (?, 'birth', ?, 'probe1_g1')")
        ->execute([$t1['name'], $t1['definition']]);
    $register = [$t1['name']];
    if ($arm3 === 'b2') {
        if ($t2 === null) { echo "[g3 b2 r$r] SKIP: T2 не рождён в G2\n"; exit(0); }
        $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, birth_domain) VALUES (?, 'birth', ?, 'probe1_g2')")
            ->execute([$t2['name'], $t2['definition']]);
        $register[] = $t2['name'];
    } elseif ($arm3 === 'b3') {
        // sham, сопоставимый с T₂: 3-терминальная формула той же длины
        $def = p1_random_component(3, (int) ($t2['tokens'] ?? 30), "PROBE1B3$r");
        $name = 'BC' . substr(md5($def . $r), 0, 8);
        $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, birth_domain) VALUES (?, 'birth', ?, 'probe1_sham')")
            ->execute([$name, $def]);
        $register[] = $name;
    }
    $t0 = microtime(true);
    $units = [];
    foreach ($fam['replicates'][$r] as $t) {
        $u = p1_run_task($t, $outDir, $dbF, $BUDGET, "g3_{$arm3}_r$r", $register);
        $u['id'] = $t['id']; $u['null'] = (bool) ($t['null_shuffle'] ?? false);
        $u['t2_used'] = $arm3 === 'b2' && $u['certified'] && $t2 !== null && strpos($u['formula'], $t2['name']) !== false;
        $units[] = $u;
    }
    $cert = count(array_filter($units, fn ($u) => $u['certified'] && ! $u['null']));
    $nullCert = count(array_filter($units, fn ($u) => $u['certified'] && $u['null']));
    $out = ['phase' => 'G3', 'arm3' => $arm3, 'replicate' => $r, 'register' => $register,
        'units' => $units, 'certified' => $cert, 'null_certified' => $nullCert,
        'wall' => round(microtime(true) - $t0, 1), 'mem_peak' => memory_get_peak_usage(true)];
    file_put_contents("$outDir/g3_{$arm3}_r$r.json", json_encode($out, JSON_PRETTY_PRINT));
    echo "[g3 $arm3 r$r] cert={$cert}/2 nullCert={$nullCert}/2 wall={$out['wall']}s\n";
    exit(0);
}

if ($mode === 'probe') {
    // Механ. пробы: (1) функциональная эквивалентность T₂ vs T₁; (2) dependency: T₂ без T₁-строки инертен.
    $r = (string) ($argv[2] ?? '1');
    $outDir = $argv[3] ?? '/tmp/probe1_run';
    $g1 = json_decode(file_get_contents("$outDir/g1_r$r.json"), true);
    $t1 = $g1['atoms'][0];
    $g2 = json_decode(file_get_contents("$outDir/g2_B_r$r.json"), true);
    $t2 = $g2['T2'] ?? null;
    if ($t2 === null) { echo "PROBE: T2 отсутствует — пробы неприменимы\n"; exit(0); }
    mt_srand((int) hexdec(substr(md5('PROBE1EQ' . $r), 0, 7)));
    $X = []; $rows3 = [];
    for ($i = 0; $i < 30; $i++) {
        $row = [mt_rand(10, 50) / 10, mt_rand(10, 50) / 10, mt_rand(10, 50) / 10, mt_rand(10, 50) / 10, mt_rand(10, 50) / 10];
        $X[] = $row; $rows3[] = array_slice($row, 0, 3);
    }
    // (1) эквивалентность: T₂(x0,x1,x2) vs T₁(x0,x1) на независимых точках
    // T₂ и T₁ вычисляются в БД, где есть ОБЕ строки (чистая с обоими dependencies)
    $dbBoth = "$outDir/db_probe_both_r$r.sqlite";
    @unlink($dbBoth);
    putenv("SWARM_DB_PATH=$dbBoth");
    // singleton переживает putenv — сброс обязателен (иначе пробы читают
    // предыдущую БД фазы; тот же класс, что R4-тесты)
    require_once $AUTOLOAD;
    BeeSwarm\Infra\Database::reset();
    $db = BeeSwarm\Infra\Database::get();
    $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, birth_domain) VALUES (?, 'birth', ?, 'p')")->execute([$t1['name'], $t1['definition']]);
    $db->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, birth_domain) VALUES (?, 'birth', ?, 'p')")->execute([$t2['name'], $t2['definition']]);
    $vT2 = BeeSwarm\Core\ExpressionEvaluator::evaluateFormula(p1_labels_to_x($t2['definition']), $X);
    $vT1 = BeeSwarm\Core\ExpressionEvaluator::evaluateFormula(p1_labels_to_x($t1['definition']), $X);
    $diff = 0; $tot = 0;
    if (is_array($vT2) && is_array($vT1)) {
        foreach ($vT2 as $i => $v) {
            if (! is_numeric($v) || ! is_numeric($vT1[$i])) { continue; }
            $tot++;
            if (abs((float) $v - (float) $vT1[$i]) > 1e-9 * max(1.0, abs((float) $vT1[$i]))) { $diff++; }
        }
    }
    $eq = ($tot > 0 && $diff === 0);
    echo 'NESTED_PROBE: T2 evaluable=' . var_export(is_array($vT2), true) . " points_diff=$diff/$tot → функционально эквивалентен T1: " . var_export($eq, true) . PHP_EOL;
    // (2) dependency: БД только с T₂ (без T₁) → T₂ инертен
    $dbNoT1 = "$outDir/db_probe_noT1_r$r.sqlite";
    @unlink($dbNoT1);
    putenv("SWARM_DB_PATH=$dbNoT1");
    BeeSwarm\Infra\Database::reset();
    $db2 = BeeSwarm\Infra\Database::get();
    $db2->prepare("INSERT OR REPLACE INTO grammar_ops (name, source, definition, birth_domain) VALUES (?, 'birth', ?, 'p')")->execute([$t2['name'], $t2['definition']]);
    $vNo = BeeSwarm\Core\ExpressionEvaluator::evaluateFormula(p1_labels_to_x($t2['definition']), $X);
    echo 'DEPENDENCY_PROBE: T2 без T1-строки evaluable=' . var_export(is_array($vNo), true) . " (ожидание false = зависимость подтверждена)\n";
    file_put_contents("$outDir/probe_r$r.json", json_encode(['t2_evaluable' => is_array($vT2), 'points_diff' => $diff, 'points_total' => $tot, 'equiv' => $eq, 'inert_without_T1' => ! is_array($vNo)], JSON_PRETTY_PRINT));
    exit(0);
}

fwrite(STDERR, "usage: driver.php <g1|g2|g3|probe> ...\n");
exit(2);
