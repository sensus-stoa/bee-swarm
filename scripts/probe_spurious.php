<?php
declare(strict_types=1);
// SPURIOUS-FORMS PILOT (owner 05.11): false certifications under noise.
// Train n=200 (noise sigma), held-out n=100 with an INDEPENDENT noise seed.
// Arms: direct (evolution on the composite) vs E48-contour (chain->library->
// type-filter->merge). false_cert = passed the train-gate, failed the
// held-out gate. Controls: shuffled y, sigma=0.
require_once __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Hive\RouteGenome;
use BeeSwarm\Hive\RouteMutator;
putenv('SWARM_DB_PATH=:memory:');
putenv('NO_BIRTH=1');
require __DIR__ . '/../vendor/autoload.php';
$outPath = $argv[1] ?? '/tmp/spurious.json';
$db = BeeSwarm\Infra\Database::get();

function genNoisy(callable $law, int $seed, int $n, float $sigma, int $noiseSeed): array {
    $s = $seed;
    $lcg = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; return $s / 0x7FFFFFFF; };
    $u = function (float $a, float $b) use ($lcg): float { return $a + $lcg() * ($b - $a); };
    $ns = $noiseSeed + 777;
    $nrand = function () use (&$ns): float { $ns = ($ns * 1103515245 + 12345) & 0x7FFFFFFF; return $ns / 0x7FFFFFFF; };
    $X = []; $y = [];
    for ($i = 0; $i < $n; $i++) {
        $row = [$u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2)];
        $X[] = $row;
        // FIX (07.11, crash autopsy): noise DETERMINISTIC per noiseSeed
        // (the old code left $nr = null and drew from the ambient mt_rand
        // state before any mt_srand — held-out independence was accidental,
        // reruns non-reproducible, the same-seed control impossible).
        $noise = $sigma > 0 ? $sigma * sqrt(-2 * log(max(1e-12, $nrand()))) * cos(2 * M_PI * $nrand()) : 0.0;
        $y[] = $law($row) + $noise;
    }
    return [$X, $y];
}
function ladder(int $i, string $salt): array {
    return ['id' => 'route_' . substr(sha1($salt . '_' . $i), 0, 8), 'parent_id' => '', 'condition' => ['kind' => 'ALWAYS'], 'budget_fraction' => 0.30, 'max_steps' => 5,
        'actions' => [
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
        ]];
}
function heldoutCv(string $form, array $Xh, array $yh): ?float {
    return cv_eval($form, $Xh, $yh);
}
// arms runner
$composite = fn (array $r): float => $r[0] * $r[1] + $r[3] * $r[4] * $r[4] + $r[5];
function directArm(array $X, array $y, int $seed, array $Xh, array $yh): array {
    mt_srand($seed);
    $ledger = new BeeSwarm\Hive\RouteLedger();
    $labels = array_map(fn (int $i): string => "c{$i}", range(0, 5));
    $ledger->register(ladder(0, 'dir'));
    for ($k = 0; $k < 7; $k++) {
        $g = RouteMutator::randomGenome();
        $g['budget_fraction'] = 0.30; $g['max_steps'] = count($g['actions']);
        if (RouteGenome::validate($g) === []) { $ledger->register($g); }
    }
    $certs = 0; $falseCerts = 0; $heldPass = 0; $certLog = [];
    for ($gen = 0; $gen < 5; $gen++) {
        $wall = 0.0;
        foreach ($ledger->active() as $g) {
            if ($wall >= 30.0) { break; }
            $res = RouteExecutor::run($g, $X, $y, $labels, min(12.0, 30.0 - $wall), 0.10, [], 1);
            $wall += $res['wall'];
            if ($res['found']) {
                $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
                $noEval = null;
                $v2 = cert_v2($fx, $X, $y, $seed, $noEval);
                if ($v2 !== null && $v2['pass']) {
                    $certs++;
                    $hcv = heldoutCv($fx, $Xh, $yh);
                    $spurious = ($hcv === null || $hcv > 0.10);
                    if ($spurious) { $falseCerts++; } else { $heldPass++; }
                    // per-cert record (prereg metric 3: mae/sqrt2-check post-hoc)
                    $certLog[] = ['form' => $fx, 'train_cvs' => $v2['cvs'], 'held_cv' => $hcv, 'spurious' => $spurious];
                }
            }
        }
        foreach ($ledger->topForReproduction(3) as $p) {
            if (count($ledger->active()) >= 12) { break; }
            $child = RouteMutator::mutate($p, mt_rand(0, 8));
            if ($child !== null && RouteGenome::validate($child) === []) { $ledger->register($child); }
        }
    }
    return ['certs_train' => $certs, 'held_pass' => $heldPass, 'false_certs' => $falseCerts,
        'false_rate' => $certs > 0 ? round($falseCerts / $certs, 3) : null, 'certs' => $certLog];
}
function contourArm(array $X, array $y, int $seed, array $Xh, array $yh): array {
    mt_srand($seed + 500);
    $labels = array_map(fn (int $i): string => "c{$i}", range(0, 5));
    $material = [];
    for ($i = 0; $i < 8; $i++) {
        $res = RouteExecutor::run(ladder($i, 'cnt'), $X, $y, $labels, 12.0, 0.10, [], 1);
        if ($res['found']) { $material[labels_to_x((string) $res['cert_formula'])] = 1; }
        $pf = (string) ($res['partial_form'] ?? 'none');
        if ($pf !== 'none' && $pf !== '') { $material[labels_to_x($pf)] = 1; }
        foreach ($res['records'] as $rec) {
            if (($rec['op'] ?? '') !== 'FLIGHT') { continue; }
            foreach (($rec['trail']['written'] ?? []) as $wf) { $material[labels_to_x($wf)] = 1; }
        }
    }
    // unfold into terms
    $unfold = function (string $f) use (&$unfold): array {
        $terms = []; $depth = 0; $cur = '';
        for ($i = 0; $i < strlen($f); $i++) {
            $ch = $f[$i];
            if ($ch === '(') { $depth++; }
            if ($ch === ')') { $depth--; }
            if ($ch === '+' && $depth === 0) { $terms[] = trim($cur); $cur = ''; continue; }
            $cur .= $ch;
        }
        $terms[] = trim($cur);
        $out = [];
        foreach ($terms as $t) {
            $orig = $t;
            while (str_starts_with($t, '(') && str_ends_with($t, ')')) {
                $inner = substr($t, 1, -1);
                $d = 0; $ok = true;
                for ($i = 0; $i < strlen($inner); $i++) {
                    if ($inner[$i] === '(') { $d++; }
                    if ($inner[$i] === ')') { $d--; if ($d < 0) { $ok = false; break; } }
                }
                if ($ok && $d === 0) { $t = trim($inner); } else { break; }
            }
            // FIX (07.11, crash autopsy): Xdebug abort at 256 frames — infinite
            // recursion. A term like x0×(x1+x2) keeps its '+', paren-stripping
            // makes NO progress, and $unfold($t) was called with the SAME
            // string forever. Recurse only when stripping actually advanced.
            if ($t !== $orig && str_contains($t, '+')) { $out = array_merge($out, $unfold($t)); }
            else { $out[] = $t; }
        }
        return $out;
    };
    $library = [];
    foreach (array_keys($material) as $f) {
        foreach ($unfold($f) as $t) {
            if (str_contains($t, 'x')) { $library[$t] = 1; }
        }
    }
    $terms = array_keys($library);
    $typeOk = fn (string $t): bool => (bool) preg_match('/^x\d(²)?$/', $t)
        || (substr_count($t, '×') === 1 && ! str_contains($t, 'max') && ! str_contains($t, 'min'));
    $tf = array_values(array_filter($terms, $typeOk));
    fwrite(STDERR, '  contour lib=' . count($tf) . ' terms' . "\n");
    $certs = 0; $falseCerts = 0; $heldPass = 0; $certLog = [];
    foreach ($tf as $a) {
        foreach ($tf as $b) {
            foreach ($tf as $c) {
                $comboForm = '(' . $a . ')+(' . $b . ')+(' . $c . ')';
                $cv = cv_eval($comboForm, array_slice($X, 160), array_slice($y, 160));
                if ($cv !== null && $cv <= 0.10) {
                    $noEval = null;
                    $v2 = cert_v2($comboForm, $X, $y, $seed + 500, $noEval);
                    if ($v2 !== null && $v2['pass']) {
                        $certs++;
                        $hcv = heldoutCv($comboForm, $Xh, $yh);
                        $spurious = ($hcv === null || $hcv > 0.10);
                        if ($spurious) { $falseCerts++; } else { $heldPass++; }
                        $certLog[] = ['form' => $comboForm, 'train_cvs' => $v2['cvs'], 'held_cv' => $hcv, 'spurious' => $spurious];
                    }
                }
            }
        }
    }
    return ['certs_train' => $certs, 'held_pass' => $heldPass, 'false_certs' => $falseCerts,
        'false_rate' => $certs > 0 ? round($falseCerts / $certs, 3) : null, 'lib' => count($tf), 'certs' => $certLog];
}
$SIGMAS = [0.05, 0.10];
$results = [];
foreach ($SIGMAS as $sigma) {
    foreach ([401, 402] as $seed) {
        [$Xt, $yt] = genNoisy($composite, 9001 + $seed, 200, $sigma, $seed);
        [$Xh, $yh] = genNoisy($composite, 9001 + $seed, 100, $sigma, $seed + 1000); // independent noise
        $d = directArm($Xt, $yt, $seed, $Xh, $yh);
        $c = contourArm($Xt, $yt, $seed, $Xh, $yh);
        $results[] = ['sigma' => $sigma, 'seed' => $seed, 'direct' => $d, 'contour' => $c];
        fwrite(STDERR, 'sigma=' . $sigma . ' seed=' . $seed . ' direct=' . json_encode($d) . ' contour=' . json_encode($c) . "\n");
    }
}
// ---- CONTROLS (prereg owner 05.11, обязательные) — direct arm, gate is common ----
$controls = [];
// (1) shuffled y @ sigma=0.10 seed=401: pure noise -> gate must give 0 certs
// (if >0 the gate is broken and the whole pilot is invalid — stop-condition)
$seed = 401; $sigma = 0.10;
[$Xt, $yt] = genNoisy($composite, 9001 + $seed, 200, $sigma, $seed);
[$Xh, $yh] = genNoisy($composite, 9001 + $seed, 100, $sigma, $seed + 1000);
mt_srand($seed + 777);
$ys = $yt; shuffle($ys);
$ctl = directArm($Xt, $ys, $seed, $Xh, $yh);
$controls[] = ['control' => 'shuffled_y', 'sigma' => $sigma, 'seed' => $seed, 'direct' => $ctl];
fwrite(STDERR, 'control shuffled_y: ' . json_encode($ctl) . "\n");
// (2) sigma=0 seed=401: no noise -> false_cert must be 0 (trivial but required)
[$Xt0, $yt0] = genNoisy($composite, 9001 + $seed, 200, 0.0, $seed);
[$Xh0, $yh0] = genNoisy($composite, 9001 + $seed, 100, 0.0, $seed + 1000);
$ctl = directArm($Xt0, $yt0, $seed, $Xh0, $yh0);
$controls[] = ['control' => 'sigma0', 'sigma' => 0.0, 'seed' => $seed, 'direct' => $ctl];
fwrite(STDERR, 'control sigma0: ' . json_encode($ctl) . "\n");
// (3) same-seed held-out @ sigma=0.05 seed=401: held-out noise = train noise ->
// a noise-fitting (spurious) form ALSO fits held-out -> false_rate must drop
[$Xt, $yt] = genNoisy($composite, 9001 + $seed, 200, 0.05, $seed);
[$XhS, $yhS] = genNoisy($composite, 9001 + $seed, 100, 0.05, $seed); // SAME noise seed as train
$ctl = directArm($Xt, $yt, $seed, $XhS, $yhS);
$controls[] = ['control' => 'same_seed_heldout', 'sigma' => 0.05, 'seed' => $seed, 'direct' => $ctl];
fwrite(STDERR, 'control same_seed_heldout: ' . json_encode($ctl) . "\n");
$results[] = ['controls' => $controls];
file_put_contents($outPath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo 'DONE spurious pilot: ' . (count($results) - 1) . " runs + " . count($controls) . " controls\n";
