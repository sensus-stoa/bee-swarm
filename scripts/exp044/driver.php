<?php
// EXP-044 driver v2 (post-review repair): 5 arms with faithful semantics
// Fixes vs v1 (review 13.10): (1) SHUFFLED reads culture.atoms + placeholder op-swap (no cascade);
// (2) birth via LawIsomorphismCompressor (>=2 laws >=2 domains) in TRAIN and TEST - COLD grows culture on hidden;
// (3) H scoped to training classes (prereg amendment v2.3: within-class claim only);
// (4) warm_noculture strips trail_marks too; (5) wall_total per task (not first flight);
// (6) single manifest at final HEAD; (7) independent eval: engine sees 60% rows, certification = cv_eval <= 0.10
// on the withheld 40% (engine's internal test slice is selection only).
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
    $f = fopen($path, 'w'); foreach ($rows as $r2) { fputcsv($f, $r2); } fclose($f);
}
function discover_with_culture(array $X, array $y, BeeSwarm\Core\Grammar $g, array $labels, string $domain, PDO $db, bool $birth, float $budget, int $maxFlights): array {
    // multi-flight task with shared wall budget; on found: record law + compressor birth (if allowed)
    $res = flights_with_budget($X, $y, $g, $labels, 3, $budget, $maxFlights, true);
    if ($res['found'] && $birth) {
        record_law($db, labels_to_x($res['formula']), (float) end($res['flights'])['cv'], $domain);
        (new BeeSwarm\Hive\LawIsomorphismCompressor())->compress(null);
    }
    return $res;
}

// ---- arms ----
$noBirth = ($arm === 'fixed');
if ($noBirth) { putenv('NO_BIRTH=1'); } else { putenv('NO_BIRTH'); }
$trail = ($arm !== 'fixed');

// TRAIN (warm, warm_noculture): D1..D8, birth via compressor over recorded laws
$trainLog = [];
if ($arm === 'warm' || $arm === 'warm_noculture') {
    foreach ($params['train'] as $d) {
        $csv = "$trainDir/{$d['id']}.csv"; gen_family($d, $csv);
        [$X, $y] = parse_csv($csv);
        $res = discover_with_culture($X, $y, $g, $labels, $d['id'], $db, true, $TRAIN_BUDGET_S, 3);
        $trainLog[] = ['id' => $d['id'], 'found' => $res['found'], 'wall_total' => $res['wall_total'], 'born' => count(born_atoms($db))];
    }
    if ($arm === 'warm_noculture') {
        // FULL strip: vocabulary AND accumulated marks (review fix #4)
        $db->exec("DELETE FROM grammar_ops WHERE source = 'birth'");
        $db->exec('DELETE FROM trail_marks');
        BeeSwarm\Core\ExpressionEvaluator::clearDefCache(); BeeSwarm\Core\AtomRegistry::clearDefCache();
    }
}
// SHUFFLED: import warm culture export (fix #1: read culture.atoms), permute ops with placeholder swap (no cascade)
if ($arm === 'shuffled') {
    $exportFile = "$outDir/culture_warm_s$seed.json";
    if (! is_file($exportFile)) { fwrite(STDERR, "shuffled needs culture export: $exportFile\n"); exit(3); }
    $exp = json_decode(file_get_contents($exportFile), true);
    $opSwap = ['−' => "\x01", 'max' => "\x02", '×' => "\x03", '/' => "\x04", '+' => "\x05", 'min' => "\x06"];
    $opNew = ["\x01" => 'max', "\x02" => '−', "\x03" => '/', "\x04" => '×', "\x05" => 'min', "\x06" => '+'];
    $n = 0;
    foreach (($exp['atoms'] ?? []) as $def) {
        $permuted = str_replace(array_keys($opSwap), array_values($opSwap), $def);
        $permuted = str_replace(array_keys($opNew), array_values($opNew), $permuted);
        if (BeeSwarm\Hive\LawIsomorphismCompressor::canonize($permuted) === null) continue;
        $name = 'BWshuf' . substr(md5($permuted . $seed), 0, 6);
        $st = $db->prepare("INSERT OR IGNORE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, 'candidate')");
        $st->execute([$name, $permuted]); $n++;
    }
    BeeSwarm\Core\ExpressionEvaluator::clearDefCache(); BeeSwarm\Core\AtomRegistry::clearDefCache();
}
$culture = born_atoms($db);
file_put_contents("$outDir/culture_{$arm}_s$seed.json", json_encode(['arm' => $arm, 'seed' => $seed, 'atoms' => $culture], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// TEST: H families (sealed); birth ON for all arms except fixed (fix #2: COLD grows culture on hidden)
$results = [];
$hDomains = [];
foreach ($params['test'] as $h) {
    $csv = "$testDir/{$h['id']}.csv";
    if (! is_file($csv)) { gen_family($h, $csv); }
    [$X, $y] = parse_csv($csv);
    [$Xt, $yt, $Xe, $ye] = split_eval($X, $y, $h['seed']);
    $res = flights_with_budget($Xt, $yt, $g, $labels, 3, $TEST_BUDGET_S, $MAX_FLIGHTS, $trail);
    $cvE = null;
    if ($res['found']) {
        $cvE = cv_eval($res['formula'], $Xe, $ye);
        if (! $noBirth) {
            // fix #2: record + compressor birth on hidden discoveries (COLD grows culture on H)
            record_law($db, labels_to_x($res['formula']), (float) end($res['flights'])['cv'], $h['id']);
            $hDomains[] = $h['id'];
            (new BeeSwarm\Hive\LawIsomorphismCompressor())->compress(null);
        }
    }
    $results[] = ['id' => $h['id'], 'class' => $h['class'] ?? '', 'found' => $res['found'],
                  'cv_engine' => end($res['flights'])['cv'] ?? 9.99, 'cv_eval' => $cvE,
                  'certified' => ($res['found'] && $cvE !== null && $cvE <= 0.10),
                  'formula' => $res['formula'], 'flights_used' => count($res['flights']),
                  't_first_found' => $res['t_first_found'], 'wall_total' => $res['wall_total']];
}
$certified = count(array_filter($results, function ($r) { return $r['certified']; }));
$summary = ['arm' => $arm, 'seed' => $seed, 'certified_families' => $certified, 'of' => count($results),
            'train' => $trainLog, 'results' => $results, 'culture_size' => count($culture)];
file_put_contents("$outDir/results_{$arm}_s$seed.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode(['arm' => $arm, 'seed' => $seed, 'certified' => $certified, 'of' => count($results)]), "\n";
