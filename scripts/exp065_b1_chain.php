<?php
declare(strict_types=1);
// EXP-065 / B1 rev.2: mark-chain positive control. Prereg FROZEN sha256:0b8d0f88,
// амендмент rev.2 (инстанса pure4, процедурный отбор сидов конверт-пробом).
// Один процесс = один сид x одна рука.
// Инстанса: y = x0·x1·x2·x3, фичи x0..x5, n=200, noise=0 (LCG канон exp063).
// 8 проходов над одной инстансой; дорога живёт в процессе
// (SWARM_DB_PATH=:memory:). Руки: A starved (флаги off) | B honest (W+R on)
// | C null (W+R on + shuffle(y)).
// CLI: php exp065_b1_chain.php run SEED ARM OUT
// Arms (B1.1): A0 chain flags-off | A1 chain W+R on | L lottery (1 pass,
// 12s, salt=attempt) | T long-flight (1 pass, 96s). ATTEMPT arg varies the
// lottery salt; fresh process per attempt = clean road (канон изоляции).
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/exp045/cert_utils.php';
use BeeSwarm\Core\Search;
use BeeSwarm\Hive\RouteExecutor;

putenv('SWARM_DB_PATH=:memory:');
putenv('TRAIL_READ_K=5');
putenv('NO_BIRTH=1');
// TRAIL_MARKS управляет RouteExecutor (write-on); руками не трогаем.

const WIDTH = 6;
const DONORS = ['(x0×x1)', '(x0×x2)', '(x0×x3)', '(x1×x2)', '(x1×x3)', '(x2×x3)'];

function genRows(int $seed, int $n): array {
    $s = $seed + 555;
    $lcg = function () use (&$s) { $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF; return $s / 0x7FFFFFFF; };
    $u = fn (float $a, float $b): float => $a + $lcg() * ($b - $a);
    $X = [];
    for ($i = 0; $i < $n; $i++) {
        $r = [];
        for ($c = 0; $c < WIDTH; $c++) { $r[] = $u(-2, 2); }
        $X[] = $r;
    }
    return $X;
}
function law(array $r): float { return $r[0] * $r[1] * $r[2] * $r[3]; }
function ladder(int $pass, string $salt): array {
    return ['id' => 'route_' . substr(sha1($salt . '_' . $pass), 0, 8), 'parent_id' => '',
        'condition' => ['kind' => 'ALWAYS'], 'budget_fraction' => 0.30, 'max_steps' => 5,
        'actions' => [
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
        ]];
}
function donorHits(array $forms): int {
    $n = 0;
    foreach ($forms as $f) {
        foreach (DONORS as $d) { if (str_contains((string) $f, $d)) { $n++; break; } }
    }
    return $n;
}

$mode = (string) ($argv[1] ?? '');
if ($mode !== 'run' || count($argv) < 5 || count($argv) > 6) { fwrite(STDERR, "usage: run SEED ARM OUT [ATTEMPT]\n"); exit(4); }
$seed = (int) $argv[2];
$arm = (string) $argv[3];
$outF = (string) $argv[4];
$attempt = (int) ($argv[5] ?? 0); // номер лотерейной попытки (варьирует соль)
if (! in_array($arm, ['A0', 'A1', 'L', 'T'], true)) { fwrite(STDERR, "ARM: A0|A1|L|T only\n"); exit(4); }
$t0 = microtime(true);

if ($arm === 'A0') {
    putenv('TRAIL_WRITE_DIVERSITY');
    putenv('TRAIL_READ_DIVERSITY');
} else {
    putenv('TRAIL_WRITE_DIVERSITY=1');
    putenv('TRAIL_READ_DIVERSITY=1');
}
$passes = ($arm === 'A0' || $arm === 'A1') ? 8 : 1;
$budget = ($arm === 'T') ? 96.0 : 12.0;

$Xr = genRows($seed, 200);
$Yreal = array_map('law', $Xr);
$labels = array_map(fn (int $i): string => "x{$i}", range(0, WIDTH - 1));

$certs = 0; $certForm = null; $certPass = null;
$passRows = [];
for ($p = 1; $p <= $passes; $p++) {
    $salt = 'b1' . $seed . (($arm === 'L') ? '_t' . $attempt : '');
    $res = RouteExecutor::run(ladder($p, $salt), $Xr, $Yreal, $labels, $budget, 0.10, [], 1);
    $writtenDonor = 0; $readDonor = 0;
    foreach (($res['records'] ?? []) as $r) {
        $writtenDonor += donorHits($r['trail']['written'] ?? ($r['written'] ?? []));
        $readDonor += donorHits($r['read_injected'] ?? ($r['trail']['read_injected'] ?? []));
    }
    $cv = $res['best_cv_seen'] ?? null;
    $partial = (string) ($res['partial_form'] ?? 'none');
    $found = false; $fx = null;
    if (! empty($res['found'])) {
        $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
        $noEval = null;
        $v2 = cert_v2($fx, $Xr, $Yreal, $seed + 600, $noEval);
        $found = ($v2 !== null && $v2['pass']);
        if ($found) { $certs = 1; $certForm = is_string($fx) ? $fx : (string) $res['cert_formula']; $certPass = $p; break; }
    }
    $passRows[] = ['pass' => $p, 'found_cert' => $found ? 1 : 0,
        'partial' => $partial, 'cv' => is_numeric($cv) ? round((float) $cv, 4) : null,
        'written_donor' => $writtenDonor, 'read_donor' => $readDonor];
}

$row = ['mode' => 'run', 'block' => 'B1.1', 'task' => 'pureprod4',
    'arm' => $arm, 'seed' => $seed, 'attempt' => $attempt, 'passes' => $passes,
    'budget_s' => $budget,
    'write_div' => $arm === 'A0' ? 'off' : 'on',
    'read_div' => $arm === 'A0' ? 'off' : 'on',
    'certs' => $certs, 'cert_form' => $certForm, 'cert_pass' => $certPass,
    'pass_records' => $passRows,
    'wall_ms' => (int) round((microtime(true) - $t0) * 1000)];
file_put_contents($outF, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
echo 'RUN_DONE arm=' . $arm . ' seed=' . $seed . ' certs=' . $certs
    . ' cert_pass=' . ($certPass ?? 'none') . ' form=' . ($certForm ?? 'NONE') . "\n";
