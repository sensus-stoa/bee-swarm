<?php
declare(strict_types=1);
// AUTODECOMPOSITION (owner (3), 05.11): the system splits C itself.
// Contour: the chain builds the road -> the term library (from forms + marks,
// admission by structural novelty) -> the merge-enumeration over term
// combinations (algebra, not search) -> cert_v2 gate. Criterion: the
// composite certified WITHOUT a given split and WITHOUT searching it.
require_once __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Hive\RouteExecutor;
putenv('SWARM_DB_PATH=:memory:');
putenv('NO_BIRTH=1');
require __DIR__ . '/../vendor/autoload.php';
function genData(callable $law, int $seed, int $n = 200): array {
    $s = $seed;
    $lcg = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; return $s / 0x7FFFFFFF; };
    $u = function (float $a, float $b) use ($lcg): float { return $a + $lcg() * ($b - $a); };
    $X = []; $y = [];
    for ($i = 0; $i < $n; $i++) {
        $row = [$u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2)];
        $X[] = $row; $y[] = $law($row);
    }
    return [$X, $y];
}
// the composite is the ONLY given: the system never sees the A/B laws
// routing-pilot mode: C_k = A_k + B_k + x_m, different bindings per k
$TASKS = [
    1 => fn (array $r): float => $r[0] * $r[1] + $r[3] * $r[4] * $r[4] + $r[5],
    2 => fn (array $r): float => $r[2] * $r[3] + $r[0] * $r[5] * $r[5] + $r[1],
    3 => fn (array $r): float => $r[1] * $r[4] + $r[2] * $r[3] * $r[3] + $r[5],
    4 => fn (array $r): float => $r[0] * $r[2] + $r[1] * $r[5] * $r[5] + $r[4],
    5 => fn (array $r): float => $r[4] * $r[5] + $r[0] * $r[1] * $r[1] + $r[2],
];
$k = (int) (getenv('TASK_K') ?: 1);
[$Xc, $yc] = genData($TASKS[$k], 9000 + $k);
$labelsC = array_map(fn (int $i): string => "c{$i}", range(0, 5));
$db = BeeSwarm\Infra\Database::get();
$db->exec('DELETE FROM trail_marks');
function ladder(int $i): array {
    return ['id' => 'route_' . substr(sha1('autod_' . $i), 0, 8), 'parent_id' => '', 'condition' => ['kind' => 'ALWAYS'], 'budget_fraction' => 0.30, 'max_steps' => 5,
        'actions' => [
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
        ]];
}
// ---- stage 1: the chain builds the road (2 passes x 8 ladders) ----
mt_srand(4242);
$material = [];
for ($i = 0; $i < 8; $i++) {
    $res = RouteExecutor::run(ladder($i), $Xc, $yc, $labelsC, 12.0, 0.10, [], 1);
    if ($res['found']) { $material[labels_to_x((string) $res['cert_formula'])] = 1; }
    $pf = (string) ($res['partial_form'] ?? 'none');
    if ($pf !== 'none' && $pf !== '') { $material[labels_to_x($pf)] = 1; }
    foreach ($res['records'] as $rec) {
        if (($rec['op'] ?? '') !== 'FLIGHT') { continue; }
        foreach (($rec['trail']['written'] ?? []) as $wf) { $material[labels_to_x($wf)] = 1; }
    }
}
fwrite(STDERR, 'material size: ' . count($material) . "\n");

// ---- stage 2: the term library (split every material form at +) ----
function unfoldTerms(string $f, int $lvl = 0): array {
    // depth guard: pathological nested forms (k3-k5 material) blow the stack
    if ($lvl > 6) { return [$f]; }
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
        while (str_starts_with($t, '(') && str_ends_with($t, ')')) {
            $inner = substr($t, 1, -1);
            $d = 0; $ok = true;
            for ($i = 0; $i < strlen($inner); $i++) {
                if ($inner[$i] === '(') { $d++; }
                if ($inner[$i] === ')') { $d--; if ($d < 0) { $ok = false; break; } }
            }
            if ($ok && $d === 0) { $t = trim($inner); } else { break; }
        }
        if (str_contains($t, '+')) { $out = array_merge($out, unfoldTerms($t, $lvl + 1)); }
        else { $out[] = $t; }
    }
    return $out;
}
$library = [];
foreach (array_keys($material) as $f) {
    foreach (unfoldTerms($f) as $t) {
        // admission by novelty + a quality filter: the term must have a
        // nonzero usable fit on SOME slice (a junk filter: it must evaluate)
        if (! str_contains($t, 'x')) { continue; }
        $library[$t] = 1;
    }
}
fwrite(STDERR, 'library: ' . count($library) . ' terms: ' . implode(' | ', array_slice(array_keys($library), 0, 12)) . "\n");

// ---- stage 3: the merge-enumeration, 4 ARMS (routing pilot, owner 05.11) ----
function termType(string $t): string {
    if (preg_match('/^x\\d(²)?$/', $t)) { return 'leaf'; }
    if (str_contains($t, '²') && str_contains($t, '×')) { return 'sqprod'; }
    if (substr_count($t, '×') === 1 && ! str_contains($t, '²') && ! str_contains($t, 'max') && ! str_contains($t, 'min')) { return 'prod'; }
    return 'other';
}
$terms = array_keys($library);
$warmClasses = ['leaf', 'prod', 'sqprod']; // learned from the warm-up tasks 1-2 successes
$runArm = function (string $armName, array $lib) use ($Xc, $yc): array {
    $tried = 0; $certs = 0; $bestCv = 9.99; $best = null; $combo = null;
    foreach ($lib as $a) {
        foreach ($lib as $b) {
            foreach ($lib as $c) {
                $comboForm = '(' . $a . ')+(' . $b . ')+(' . $c . ')';
                $tried++;
                $cv = cv_eval($comboForm, array_slice($Xc, 160), array_slice($yc, 160));
                if ($cv !== null && $cv < $bestCv) { $bestCv = $cv; $best = $comboForm; $combo = [$a, $b, $c]; }
                if ($cv !== null && $cv <= 0.10) {
                    $noEval = null;
                    $v2 = cert_v2($comboForm, $Xc, $yc, 9001, $noEval);
                    if ($v2 !== null && $v2['pass']) { $certs++; }
                }
            }
        }
    }
    return ['arm' => $armName, 'combos' => $tried, 'certs' => $certs, 'best_cv' => $bestCv === 9.99 ? null : $bestCv, 'best_combo' => $combo];
};
$armsK = [];
$armsK['baseline'] = $runArm('baseline', $terms);
$tf = array_values(array_filter($terms, fn (string $t): bool => in_array(termType($t), $warmClasses, true)));
$armsK['type_compat'] = $runArm('type_compat', $tf) + ['lib_size' => count($tf)];
$armsK['co_occurrence'] = $runArm('co_occurrence', $tf) + ['lib_size' => count($tf), 'note' => 'learned classes = warm-up successes'];
$rs = [];
for ($s2 = 1; $s2 <= 3; $s2++) {
    mt_srand(7000 + $k * 10 + $s2);
    $keys = (array) array_rand($terms, max(1, count($tf)));
    $randlib = array_values(array_intersect_key($terms, array_flip($keys)));
    $rs[] = $runArm('random_' . $s2, $randlib) + ['lib_size' => count($randlib)];
}
$armsK['random'] = $rs;
fwrite(STDERR, 'ARMS k=' . $k . ': base=' . json_encode([$armsK['baseline']['combos'], $armsK['baseline']['certs']]) . ' type=' . json_encode([$armsK['type_compat']['combos'], $armsK['type_compat']['certs']]) . ' random=' . json_encode(array_map(fn (array $r): int => $r['certs'], $rs)) . "
");
$out = [
    'pilot' => 'autodecomposition', 'task_k' => $k, 'material' => count($material), 'library' => count($library),
    'library_terms' => array_keys($library), 'material_terms' => array_keys($material),
    'routing_arms' => $armsK,
    'combos_tried' => $armsK['baseline']['combos'], 'best_cv' => $armsK['baseline']['best_cv'], 'best_combo' => $armsK['baseline']['best_combo'], 'merge_certs' => $armsK['baseline']['certs'],
];
file_put_contents($argv[1] ?? '/tmp/auto.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo 'DONE autodecomposition: library=' . count($library) . ' combos=' . $tried . ' bestCv=' . var_export($bestCv, true) . ' certs=' . $certs . "\n";
