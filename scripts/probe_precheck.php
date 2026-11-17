<?php
declare(strict_types=1);
// PRE-CHECK (owner): how many DIFFERENT partials on the composite in ONE pass?
require_once __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Hive\RouteGenome;
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
[$Xc, $yc] = genData(fn (array $r): float => $r[0] * $r[1] + $r[3] * $r[4] * $r[4] + $r[5], 9001);
$labelsC = array_map(fn (int $i): string => "c{$i}", range(0, 5));
$db = BeeSwarm\Infra\Database::get();
$db->exec('DELETE FROM trail_marks');
function ladder(int $i): array {
    return ['id' => 'route_' . substr(sha1('prechk_' . $i), 0, 8), 'parent_id' => '', 'condition' => ['kind' => 'ALWAYS'], 'budget_fraction' => 0.30, 'max_steps' => 5,
        'actions' => [
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
        ]];
}
$allForms = []; $markForms = [];
mt_srand(4242);
for ($i = 0; $i < 8; $i++) {
    $res = RouteExecutor::run(ladder($i), $Xc, $yc, $labelsC, 12.0, 0.10, [], 1);
    $pool = [];
    if ($res['found']) { $pool[] = $res['cert_formula']; }
    if (($res['partial_form'] ?? 'none') !== 'none') { $pool[] = (string) $res['partial_form']; }
    foreach ($res['records'] as $rec) {
        if (($rec['op'] ?? '') === 'FLIGHT') {
            foreach (($rec['trail']['written'] ?? []) as $wf) { $markForms[$wf] = 1; $pool[] = $wf; }
            foreach (($rec['trail']['read_injected'] ?? []) as $rf) { $pool[] = $rf; }
            if (! empty($rec['formula'])) { $pool[] = (string) $rec['formula']; }
        }
    }
    foreach ($pool as $f) {
        if ($f === '' || $f === 'none') { continue; }
        $allForms[labels_to_x($f)] = 1;
    }
    fwrite(STDERR, 'pass ' . ($i + 1) . ' forms-cum=' . count($allForms) . ' marks-cum=' . count($markForms) . "\n");
}
$hasA = 0; $hasB = 0; $both = 0; $aF = []; $bF = [];
foreach (array_keys($allForms) as $f) {
    $a = (preg_match('/(^|[^x0-9])x0(?![0-9])\s*[×*]/', $f) || preg_match('/[×*]\s*x0(?![0-9])/', $f))
        && (preg_match('/(^|[^x0-9])x1(?![0-9])\s*[×*]/', $f) || preg_match('/[×*]\s*x1(?![0-9])/', $f));
    $b = (preg_match('/(^|[^x0-9])x3(?![0-9])\s*[×*]/', $f) || preg_match('/[×*]\s*x3(?![0-9])/', $f))
        && (str_contains($f, 'x4²') || preg_match('/x4[×*]x4/', $f));
    if ($a) { $hasA++; $aF[] = $f; }
    if ($b) { $hasB++; $bF[] = $f; }
    if ($a && $b) { $both++; }
}
fwrite(STDERR, 'FORMS total=' . count($allForms) . ' withA=' . $hasA . ' withB=' . $hasB . ' BOTH=' . $both . ' markKinds=' . count($markForms) . "\n");
foreach (array_slice($aF, 0, 3) as $f) { fwrite(STDERR, 'A-piece: ' . substr($f, 0, 60) . "\n"); }
foreach (array_slice($bF, 0, 3) as $f) { fwrite(STDERR, 'B-piece: ' . substr($f, 0, 60) . "\n"); }
file_put_contents($argv[1] ?? '/tmp/prechk.json', json_encode(['forms' => array_keys($allForms), 'counts' => ['total' => count($allForms), 'withA' => $hasA, 'withB' => $hasB, 'both' => $both], 'mark_kinds' => count($markForms)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo 'DONE pre-check: forms=' . count($allForms) . ' withA=' . $hasA . ' withB=' . $hasB . ' both=' . $both . "\n";
