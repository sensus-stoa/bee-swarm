<?php
declare(strict_types=1);
// EXP-056: progress-reward stigmergy (owner spec 08.11, prereg exp056_prereg.md).
// progress(r) = max(0, L_before - L(r)); L = best_L_valid of ONE warm-chain
// ladder with ONLY rung r on the road (isolated attribution; L is RMSE-
// normalized via computeL — immune to the E51 ratio-tail explosion).
// Pheromone = (sum(progress) + 0.01) / (N + 2) — spec section 2.
// 12-core parallel: one php process = one probe / one arm-run per invocation.
//
// Modes:
//   probe seed shape out   — measure L(seed, shape|EMPTY-road baseline when shape="") for C_deep
//   ledbuild probes out    — merge probe rows -> frozen ledger {shape,N,prog}
//   run seed arm ledger out — phase-2 arm on frozen ledger (top-5 by pheromone)
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Core\Search;
use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Core\ExpressionEvaluator;
putenv('SWARM_DB_PATH=:memory:');
putenv('TRAIL_READ_K=5');
putenv('NO_BIRTH=1');
require_once __DIR__ . '/exp045/cert_utils.php';

const WIDTH = 5;
const CHAIN_LADDERS = 4;      // implementation fix: chain depth for phase-2 (prereg deviation recorded)
const PROBE_LADDER_BUDGET = 8.0;

function genDeep(int $seed, int $n): array {
    $s = $seed + 7777;
    $lcg = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; return $s / 0x7FFFFFFF; };
    $u = fn (float $a, float $b): float => $a + $lcg() * ($b - $a);
    $X = []; $y = [];
    for ($i = 0; $i < $n; $i++) {
        $r = [$u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2)];
        $y[] = ($r[0] * $r[1]) * ($r[2] * $r[3]) + $r[4];
        $X[] = $r;
    }
    return [$X, $y];
}

function ladder(int $i, string $salt): array {
    return ['id' => 'route_' . substr(sha1($salt . '_' . $i), 0, 8), 'parent_id' => '', 'condition' => ['kind' => 'ALWAYS'], 'budget_fraction' => 0.30, 'max_steps' => 4,
        'actions' => [
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
        ]];
}

/** ALL Level-B instantiations of the L1 template set over the task width. */
function templateCandidates(int $width): array {
    $cands = [];
    foreach (['(x0×x1)', '(x3×x4²)'] as $src) {
        foreach (match (trim($src, '()')) {
            'x0×x1' => ['(x0×_)', '(_×x1)', '(_×_)'],
            'x3×x4²' => ['(x3×_²)', '(_×x4²)', '(_×_²)', '(_²)'],
            default => [],
        } as $tpl) {
            preg_match_all('/x(\d+)/', $tpl, $mm);
            $occ = array_map('intval', $mm[1]);
            $free = array_values(array_diff(range(0, $width - 1), $occ));
            if (substr_count($tpl, '_') === 1) {
                foreach ($free as $a) { $cands[] = str_replace('_', 'x' . $a, $tpl); }
            } elseif (substr_count($tpl, '_') === 2) {
                foreach ($free as $a) {
                    foreach ($free as $b) {
                        if ($a === $b) continue;
                        $cands[] = str_replace('_', 'x' . $b, preg_replace('/_/', 'x' . $a, $tpl, 1));
                    }
                }
            }
        }
    }
    return $cands;
}

function xToLabels(string $f): string {
    return (string) preg_replace_callback('/x(\d+)/', fn ($m) => 'c' . $m[1], $f);
}

function writeReflectionRungs(array $labelForms, array $labels, array $X, array $y): int {
    if ($labelForms === []) return 0;
    $plaus = array_map(fn (string $f): array => ['name' => $f, 'corr' => 0.0], $labelForms);
    $rm = new ReflectionMethod(Search::class, 'writeTrailMarks');
    $rm->setAccessible(true);
    foreach (array_chunk($plaus, 5) as $chunk) {
        $rm->invoke(null, $labels, $chunk, INF, INF, [], [], $X, $y);
    }
    return count(Search::$lastTrailWritten ?? []);
}

$mode = (string) ($argv[1] ?? '');
$seed = (int) ($argv[2] ?? 0);
$shape = (string) ($argv[3] ?? '');
$outPath = (string) ($argv[4] ?? '');
$t0 = microtime(true);

[$Xdeep, $ydeep] = genDeep($seed, 200);
$labels = array_map(fn (int $i): string => "c{$i}", range(0, WIDTH - 1));

// -- road write: either just $shape (probe) or nothing (baseline) --
if ($mode === 'probe' && $shape !== '') {
    $w = writeReflectionRungs([$shape], $labels, $Xdeep, $ydeep);
} else {
    $w = 0;
}

// -- one chain on the (possibly rung-seeded) road --
$flights = 0; $bestL = INF;
for ($i = 0; $i < ($mode === 'probe' ? 1 : 1); $i++) {
    $res = RouteExecutor::run(ladder($i, 'p' . $mode . $seed), $Xdeep, $ydeep, $labels, PROBE_LADDER_BUDGET, 0.10, [], 1);
    $flights++;
    if ($res['best_L_valid'] !== null && $res['best_L_valid'] < $bestL) { $bestL = $res['best_L_valid']; }
}
$L = $bestL === INF ? null : $bestL;
echo 'PROBE seed=' . $seed . ' shape=' . ($shape === '' ? 'BASELINE' : $shape)
    . ' L=' . ($L === null ? 'null' : round($L, 6)) . ' road_w=' . $w
    . ' BCV=' . json_encode($res['best_cv_seen'] ?? null)
    . ' found=' . json_encode($res['found'] ?? null)
    . ' wall_ms=' . (int) round((microtime(true) - $t0) * 1000) . "\n";
