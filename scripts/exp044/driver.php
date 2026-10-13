<?php
// EXP-044 driver v1 (freeze candidate): 5 arms, auto-culture, shared budget, per-family logging
// Usage: php exp044_driver.php <arm> <seed> <familiesJson> <trainDir> <testDir> <outDir>
// Arms: warm | warm_noculture | cold | shuffled | fixed

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
function flights_with_budget(array $X, array $y, BeeSwarm\Core\Grammar $g, array $labels, int $depth, float $budget, int $maxFlights, bool $trail): array {
    $t0 = microtime(true); $flights = []; $found = false; $tFound = null;
    for ($f = 1; $f <= $maxFlights; $f++) {
        $remain = $budget - (microtime(true) - $t0);
        if ($remain < 5.0) break;
        if ($trail) { putenv('TRAIL_MARKS=1'); } else { putenv('TRAIL_MARKS'); }
        $r = BeeSwarm\Core\Search::find($X, $y, $g, $depth, $labels, 0.4, 0.10, min(60.0, $remain));
        $fl = ['f' => $f, 'found' => (bool) $r[0], 'cv' => is_numeric($r[1]) ? (float) $r[1] : 9.99,
               'formula' => is_string($r[2] ?? null) ? (string) $r[2] : '', 'wall_s' => round(microtime(true) - $t0, 1)];
        $flights[] = $fl;
        if ($fl['found']) { $found = true; $tFound = round(microtime(true) - $t0, 1); break; }
    }
    if ($trail) { putenv('TRAIL_MARKS'); }
    return ['flights' => $flights, 'found' => $found, 't_first_found' => $tFound,
            'cv' => $found ? end($flights)['cv'] : 9.99,
            'formula' => $found ? end($flights)['formula'] : ''];
}
function born_atoms(PDO $db): array {
    $rows = $db->query("SELECT name, definition FROM grammar_ops WHERE source = 'birth'")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) { $out[$r['name']] = $r['definition']; }
    return $out;
}
// ---- generator: family CSV from params ----
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

// ---- arms ----
$noBirth = ($arm === 'fixed');
if ($noBirth) { putenv('NO_BIRTH=1'); } else { putenv('NO_BIRTH'); }
$trail = ($arm !== 'fixed'); // FIXED: no trail (TRAIL_MARKS off); others: on

// TRAIN phase (warm, warm_noculture): D1..D8, birth ON (auto-culture)
$trainLog = [];
if ($arm === 'warm' || $arm === 'warm_noculture') {
    foreach ($params['train'] as $d) {
        $csv = "$trainDir/{$d['id']}.csv"; gen_family($d, $csv);
        [$X, $y] = parse_csv($csv);
        $res = flights_with_budget($X, $y, $g, $labels, 3, $TRAIN_BUDGET_S, 3, true);
        $bornN = count(born_atoms($db));
        if ($res['found']) {
            $def = BeeSwarm\Hive\LawIsomorphismCompressor::canonize(end($res['flights'])['formula']);
            if ($def !== null) {
                $name = 'B' . substr(md5($def), 0, 8);
                $st = $db->prepare("INSERT OR IGNORE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, 'candidate')");
                $st->execute([$name, $def]);
                BeeSwarm\Core\ExpressionEvaluator::clearDefCache(); BeeSwarm\Core\AtomRegistry::clearDefCache();
            }
        }
        $trainLog[] = ['id' => $d['id'], 'found' => $res['found'], 'wall_s' => $res['flights'][0]['wall_s'] ?? 0,
                       'born' => count(born_atoms($db))];
    }
    if ($arm === 'warm_noculture') {
        $db->exec("DELETE FROM grammar_ops WHERE source = 'birth'");
        BeeSwarm\Core\ExpressionEvaluator::clearDefCache(); BeeSwarm\Core\AtomRegistry::clearDefCache();
    }
}
// SHUFFLED: import warm culture export, permute ops deterministically
if ($arm === 'shuffled') {
    $exportFile = "$outDir/culture_warm_s$seed.json";
    if (! is_file($exportFile)) { fwrite(STDERR, "shuffled needs culture export: $exportFile\n"); exit(3); }
    $culture = json_decode(file_get_contents($exportFile), true);
    $opSwap = ['−' => 'max', 'max' => '−', '×' => '/', '/' => '×', '+' => 'min', 'min' => '+'];
    $n = 0;
    foreach ($culture as $def) {
        $permuted = str_replace(array_keys($opSwap), array_values($opSwap), $def);
        if (BeeSwarm\Hive\LawIsomorphismCompressor::canonize($permuted) === null) continue;
        $name = 'BWshuf' . substr(md5($permuted . $seed), 0, 6);
        $st = $db->prepare("INSERT OR IGNORE INTO grammar_ops (name, source, definition, status) VALUES (?, 'birth', ?, 'candidate')");
        $st->execute([$name, $permuted]); $n++;
    }
    BeeSwarm\Core\ExpressionEvaluator::clearDefCache(); BeeSwarm\Core\AtomRegistry::clearDefCache();
}
// culture provenance snapshot
$culture = born_atoms($db);
file_put_contents("$outDir/culture_{$arm}_s$seed.json", json_encode(['arm' => $arm, 'seed' => $seed, 'atoms' => $culture], JSON_PRETTY_PRINT));

// TEST phase: H families (sealed params)
$results = [];
foreach ($params['test'] as $h) {
    $csv = "$testDir/{$h['id']}.csv";
    if (! is_file($csv)) { gen_family($h, $csv); }
    [$X, $y] = parse_csv($csv);
    $depth = 3; // matched config (R1b): all arms at depth 3, beam 10
    $res = flights_with_budget($X, $y, $g, $labels, $depth, $TEST_BUDGET_S, $MAX_FLIGHTS, $trail);
    $results[] = ['id' => $h['id'], 'class' => $h['class'] ?? '', 'found' => $res['found'],
                  'cv' => $res['cv'], 'formula' => $res['formula'],
                  'flights_used' => count($res['flights']), 't_first_found' => $res['t_first_found'],
                  'wall_s' => $res['flights'][0]['wall_s'] ?? 0];
}
$certified = count(array_filter($results, function ($r) { return $r['found']; }));
$summary = ['arm' => $arm, 'seed' => $seed, 'certified_families' => $certified,
            'of' => count($results), 'train' => $trainLog, 'results' => $results,
            'culture_size' => count($culture)];
file_put_contents("$outDir/results_{$arm}_s$seed.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo json_encode(['arm' => $arm, 'seed' => $seed, 'certified' => $certified, 'of' => count($results)]), "\n";
