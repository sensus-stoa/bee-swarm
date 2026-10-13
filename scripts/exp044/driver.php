<?php
// EXP-044 driver v3 (final corrections per owner gate 13.10):
// v2->v3: (1) warm_noculture strips laws+trail_marks too, with COLD-equivalence assert;
//         (2) SHUFFLED carries trail_marks state from WARM + asserts (volume match, defs differ);
//         (3) record_law/compress ONLY after cv_eval pass on the independent slice;
//         (4) frozen null-controls (2 per H family, permuted y, seeds from manifest) + FPR;
//         (5) manifest v3 at final HEAD (v2 preserved as MANIFEST_v2.md).
// Usage: php exp044_driver.php <arm> <seed> <familiesJson> <trainDir> <testDir> <outDir>

$arm = $argv[1] ?? ''; $seed = (int) ($argv[2] ?? 0);
$famJson = $argv[3] ?? ''; $trainDir = $argv[4] ?? ''; $testDir = $argv[5] ?? ''; $outDir = $argv[6] ?? '/tmp/exp044_out';
if (! in_array($arm, ['warm','warm_noculture','cold','shuffled','fixed'], true) || ! is_file($famJson)) {
    fwrite(STDERR, "usage: exp044_driver.php <arm> <seed> <familiesJson> <trainDir> <testDir> <outDir>\n"); exit(2);
}
@mkdir($outDir, 0777, true);
$params = json_decode(file_get_contents($famJson), true);
$TEST_BUDGET_S = 90.0; $TRAIN_BUDGET_S = 60.0; $MAX_FLIGHTS = 3;

putenv('SWARM_DB_PATH=:memory:');
mt_srand($seed);
require '/home/ninjacat/.bee_swarm/vendor/autoload.php';
$db = BeeSwarm\Infra\Database::get();
$g = new BeeSwarm\Core\Grammar();
$g->restrictTo(['+','×','−','/','sq','cube','sqrt','max','min']);
$labels = ['c0','c1','c2','c3','c4','c5'];

function parse_csv(string $path): array {
    $X = []; $y = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $v = str_getcsv($line);
        if (count($v) < 6 || ! is_numeric($v[0])) continue;
        $X[] = array_map('floatval', array_slice($v, 0, 5));
        $y[] = (float) $v[5];
    }
    return [$X, $y];
}
function split_eval(array $X, array $y, int $seed): array {
    // deterministic 60/40: engine sees train rows; eval rows withheld until final certification
    $n = count($y); $idx = range(0, $n - 1);
    mt_srand($seed);
    for ($i = $n - 1; $i > 0; $i--) { $j = (int) floor(mt_rand() / (mt_getrandmax() + 1) * ($i + 1)); [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]]; }
    $k = (int) floor($n * 0.6);
    $tr = array_slice($idx, 0, $k); $ev = array_slice($idx, $k);
    $Xt = []; $yt = []; $Xe = []; $ye = [];
    foreach ($tr as $i) { $Xt[] = $X[$i]; $yt[] = $y[$i]; }
    foreach ($ev as $i) { $Xe[] = $X[$i]; $ye[] = $y[$i]; }
    return [$Xt, $yt, $Xe, $ye];
}
function flights_with_budget(array $X, array $y, BeeSwarm\Core\Grammar $g, array $labels, int $depth, float $budget, int $maxFlights, bool $trail): array {
    $t0 = microtime(true); $flights = []; $found = false; $tFound = null; $formula = '';
    for ($f = 1; $f <= $maxFlights; $f++) {
        $remain = $budget - (microtime(true) - $t0);
        if ($remain < 5.0) break;
        if ($trail) { putenv('TRAIL_MARKS=1'); } else { putenv('TRAIL_MARKS'); }
        $r = BeeSwarm\Core\Search::find($X, $y, $g, $depth, $labels, 0.4, 0.10, min(60.0, $remain));
        $flights[] = ['f' => $f, 'found' => (bool) $r[0], 'cv' => is_numeric($r[1]) ? (float) $r[1] : 9.99,
                      'formula' => is_string($r[2] ?? null) ? (string) $r[2] : '', 'wall_s' => round(microtime(true) - $t0, 1)];
        if ($r[0]) { $found = true; $tFound = round(microtime(true) - $t0, 1); $formula = (string) $r[2]; break; }
    }
    if ($trail) { putenv('TRAIL_MARKS'); }
    return ['flights' => $flights, 'found' => $found, 't_first_found' => $tFound, 'formula' => $formula,
            'wall_total' => round(microtime(true) - $t0, 1)];
}
function labels_to_x(string $formula): string {
    return str_replace(['c0','c1','c2','c3','c4','c5'], ['x0','x1','x2','x3','x4','x5'], $formula);
}
function cv_eval(string $formulaC, array $Xe, array $ye): ?float {
    // independent certification on the withheld 40%: CV of formula/target on eval rows
    $f = labels_to_x($formulaC);
    $vals = BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($f, $Xe);
    if (! is_array($vals) || count($vals) !== count($ye)) return null;
    $ratios = [];
    foreach ($vals as $i => $p) {
        if ($ye[$i] == 0.0 || ! is_numeric($p)) return null;
        $ratios[] = (float) $p / $ye[$i];
    }
    $mean = array_sum($ratios) / count($ratios);
    $var = 0.0; foreach ($ratios as $rv) { $var += ($rv - $mean) ** 2; }
    $std = sqrt($var / count($ratios));
    return $mean != 0.0 ? $std / abs($mean) : null;
}
function record_law(PDO $db, string $formula, float $cv, string $domain): void {
    $st = $db->prepare('INSERT INTO laws (name, formula, cv, domain) VALUES (?, ?, ?, ?)');
    $st->execute(['law_' . substr(md5($formula . $domain . microtime(true)), 0, 10), $formula, $cv, $domain]);
}
function born_atoms(PDO $db): array {
    $rows = $db->query("SELECT name, definition FROM grammar_ops WHERE source = 'birth'")->fetchAll(PDO::FETCH_ASSOC);
    $out = []; foreach ($rows as $r) { $out[$r['name']] = $r['definition']; } return $out;
}
function state_counts(PDO $db): array {
    // carried-state fingerprint for COLD-equivalence asserts (v3 fix #1)
    $q = function (string $sql) use ($db): int {
        try { return (int) $db->query($sql)->fetchColumn(); }
        catch (Throwable $e) { return -1; }
    };
    return ['laws' => $q('SELECT COUNT(*) FROM laws'),
            'grammar_ops_birth' => $q("SELECT COUNT(*) FROM grammar_ops WHERE source='birth'"),
            'trail_marks' => $q('SELECT COUNT(*) FROM trail_marks')];
}
function gen_family(array $p, string $path): void {
    $s = $p['seed']; $r = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7fffffff; return $s / 0x7fffffff; };
    $u = function (float $a, float $b) use ($r): float { return $a + $r() * ($b - $a); };
    $rows = []; $perm = $p['perm'] ?? [0,1,2,3,4]; $scale = $p['scale'] ?? [1,1,1,1,1];
    for ($i = 0; $i < 200; $i++) {
        $c = [$u(1,3), $u(2,5), $u(3,7), $u(0.5,2), $u(0,9)];
        $y = 0.0;
        switch ($p['class']) {
            case 'flux':        $y = 2.0 * ($c[2]-$c[1]) * $c[0] / $c[3]; break;
            case 'diffprod':    $y = 1.5 * ($c[1]-$c[0]) * $c[0]; break;
            case 'ratio':       $y = ($c[0]*$c[1]) / $c[3]; break;
            case 'composition': $y = ($c[0]-$c[1])*($c[0]-$c[1]) + $c[2]*$c[3]; break;
            case 'power':       $y = $c[0]*$c[0] + $c[1]*$c[2]; break;
            case 'pureprod':    $y = $c[0]*$c[3]; break;
            case 'purediff':    $y = $c[1]-$c[0]; break;
        }
        if (! empty($p['noise'])) { $y *= 1 + ($r()-0.5) * $p['noise']; }
        $row = [];
        foreach ($perm as $src) { $row[] = $c[$src] * $scale[$src]; }
        $row[] = $y;
        $rows[] = $row;
    }
    if (! empty($p['null_shuffle'])) {
        // frozen null-control: permute the target column (destroys any invariant)
        mt_srand($p['seed']);
        $ys = array_column($rows, 5);
        for ($i = count($ys) - 1; $i > 0; $i--) { $j = (int) floor(mt_rand() / (mt_getrandmax() + 1) * ($i + 1)); [$ys[$i], $ys[$j]] = [$ys[$j], $ys[$i]]; }
        foreach ($rows as $i => $row) { $rows[$i][5] = $ys[$i]; }
    }
    $f = fopen($path, 'w'); foreach ($rows as $r2) { fputcsv($f, $r2); } fclose($f);
}
function run_task(array $X, array $y, array $Xe, array $ye, BeeSwarm\Core\Grammar $g, array $labels, string $domain, PDO $db, bool $birth, float $budget): array {
    $res = flights_with_budget($X, $y, $g, $labels, 3, $budget, 3, true);
    $cvE = null;
    if ($res['found']) { $cvE = cv_eval($res['formula'], $Xe, $ye); }
    $certified = ($res['found'] && $cvE !== null && $cvE <= 0.10);
    // v3 fix #3: record_law + compress ONLY after cv_eval pass on the independent slice
    if ($certified && $birth) {
        record_law($db, labels_to_x($res['formula']), (float) end($res['flights'])['cv'], $domain);
        (new BeeSwarm\Hive\LawIsomorphismCompressor())->compress(null);
    }
    return ['found' => $res['found'], 'cv_engine' => end($res['flights'])['cv'] ?? 9.99, 'cv_eval' => $cvE,
            'certified' => $certified, 'formula' => $res['formula'],
            'flights_used' => count($res['flights']), 't_first_found' => $res['t_first_found'],
            'wall_total' => $res['wall_total']];
}

// ---- arms ----
$noBirth = ($arm === 'fixed');
if ($noBirth) { putenv('NO_BIRTH=1'); } else { putenv('NO_BIRTH'); }
$trail = ($arm !== 'fixed');

// TRAIN (warm, warm_noculture)
$trainLog = [];
if ($arm === 'warm' || $arm === 'warm_noculture') {
    foreach ($params['train'] as $d) {
        $csv = "$trainDir/{$d['id']}.csv"; gen_family($d, $csv);
        [$X, $y] = parse_csv($csv);
        // v3: TRAIN birth via compressor over recorded laws (v2 semantics; faithful to LawCompressor)
        $res = flights_with_budget($X, $y, $g, $labels, 3, $TRAIN_BUDGET_S, 3, true);
        if ($res['found']) {
            record_law($db, labels_to_x($res['formula']), (float) end($res['flights'])['cv'], $d['id']);
            (new BeeSwarm\Hive\LawIsomorphismCompressor())->compress(null);
        }
        $trainLog[] = ['id' => $d['id'], 'found' => $res['found'], 'wall_total' => $res['wall_total'], 'born' => count(born_atoms($db))];
    }
    if ($arm === 'warm_noculture') {
        // v3 fix #1: FULL strip - vocabulary, marks AND laws (COLD-equivalence before first hidden task)
        $db->exec("DELETE FROM grammar_ops WHERE source = 'birth'");
        $db->exec('DELETE FROM trail_marks');
        $db->exec('DELETE FROM laws');
        BeeSwarm\Core\ExpressionEvaluator::clearDefCache(); BeeSwarm\Core\AtomRegistry::clearDefCache();
    }
}
// SHUFFLED (v3 fix #2): import atoms AND trail marks from WARM export; asserts
if ($arm === 'shuffled') {
    $exportFile = "$outDir/culture_warm_s$seed.json";
    if (! is_file($exportFile)) { fwrite(STDERR, "shuffled needs culture export: $exportFile\n"); exit(3); }
    $exp = json_decode(file_get_contents($exportFile), true);
    $opSwap = ['−' => "\x01", 'max' => "\x02", '×' => "\x03", '/' => "\x04", '+' => "\x05", 'min' => "\x06"];
    $opNew = ["\x01" => 'max', "\x02" => '−', "\x03" => '/', "\x04" => '×', "\x05" => 'min', "\x06" => '+'];
    $nAtoms = 0; $differs = false;
    foreach (($exp['atoms'] ?? []) as $origName => $def) {
        $permuted = str_replace(array_keys($opSwap), array_values($opSwap), $def);
        $permuted = str_replace(array_keys($opNew), array_values($opNew), $permuted);
        if (BeeSwarm\Hive\LawIsomorphismCompressor::canonize($permuted) === null) continue;
        if ($permuted !== $def) { $differs = true; }
        $name = 'BWshuf' . substr(md5($permuted . $seed), 0, 6);
        $st = $db->prepare("INSERT OR IGNORE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, 'candidate')");
        $st->execute([$name, $permuted]); $nAtoms++;
    }
    $stM = $db->prepare('INSERT INTO trail_marks (fingerprint, formula, kind, corr, uses, mark_tick) VALUES (?, ?, ?, ?, ?, ?)');
    $nMarks = 0;
    foreach (($exp['trail_marks'] ?? []) as $m) {
        $stM->execute([$m['fingerprint'], $m['formula'], $m['kind'], $m['corr'], $m['uses'], $m['mark_tick']]); $nMarks++;
    }
    BeeSwarm\Core\ExpressionEvaluator::clearDefCache(); BeeSwarm\Core\AtomRegistry::clearDefCache();
    // ASSERTS: cultural state volume matches WARM; definitions actually differ
    $asserts = [
        'atoms_count_matches_warm' => (count(born_atoms($db)) === count($exp['atoms'] ?? [])),
        'trail_marks_match_warm' => ((count($exp['trail_marks'] ?? [])) === $nMarks),
        'definitions_differ' => $differs,
    ];
    if (in_array(false, $asserts, true)) { fwrite(STDERR, 'SHUFFLED ASSERT FAIL: ' . json_encode($asserts) . "\n"); exit(4); }
}
$culture = born_atoms($db);
$stCounts = state_counts($db);
file_put_contents("$outDir/culture_{$arm}_s$seed.json", json_encode(['arm' => $arm, 'seed' => $seed, 'atoms' => $culture, 'state' => $stCounts], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// ---- task runner: TEST families + frozen null-controls ----
$results = []; $nullResults = [];
foreach ($params['test'] as $h) {
    $csv = "$testDir/{$h['id']}.csv";
    if (! is_file($csv)) { gen_family($h, $csv); }
    [$X, $y] = parse_csv($csv);
    [$Xt, $yt, $Xe, $ye] = split_eval($X, $y, $h['seed']);
    $r = run_task($Xt, $yt, $Xe, $ye, $g, $labels, $h['id'], $db, ! $noBirth, $TEST_BUDGET_S);
    $r = array_merge(['id' => $h['id'], 'class' => $h['class'] ?? '', 'kind' => 'hidden'], $r);
    $results[] = $r;
}
foreach (($params['nulls'] ?? []) as $n) {
    // v3 fix #4: frozen null-controls (permuted y, seeds from manifest) -> FPR
    $csv = "$testDir/{$n['id']}.csv";
    if (! is_file($csv)) { gen_family($n, $csv); }
    [$X, $y] = parse_csv($csv);
    [$Xt, $yt, $Xe, $ye] = split_eval($X, $y, $n['seed']);
    $r = run_task($Xt, $yt, $Xe, $ye, $g, $labels, $n['id'], $db, ! $noBirth, $TEST_BUDGET_S);
    $r = array_merge(['id' => $n['id'], 'kind' => 'null'], $r);
    $nullResults[] = $r;
}
$nullCert = count(array_filter($nullResults, function ($r) { return $r['certified']; }));
$nullTotal = count($nullResults);
$certified = count(array_filter($results, function ($r) { return $r['certified']; }));
$summary = ['arm' => $arm, 'seed' => $seed,
            'certified_families' => $certified, 'of' => count($results),
            'fpr_certified_nulls' => $nullCert, 'fpr_denominator' => $nullTotal,
            'fpr' => $nullTotal > 0 ? round($nullCert / $nullTotal, 4) : null,
            'train' => $trainLog, 'results' => $results, 'null_results' => $nullResults,
            'culture_size' => count($culture), 'state' => $stCounts];
file_put_contents("$outDir/results_{$arm}_s$seed.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode(['arm' => $arm, 'seed' => $seed, 'certified' => $certified, 'of' => count($results),
                  'fpr' => $summary['fpr']]), "\n";
