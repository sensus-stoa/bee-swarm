<?php

declare(strict_types=1);

// EXP-065 B2-PILOT (18.11): positive control для нишевой квоты.
// Гипотеза (forensics B2): тёмные сиды не собирают закон x0·x1·x2·x3,
// потому что недостающий донор (x1×x3)-класса не доходит до дороги
// (proxy-walk обрывает petal'ы). Если ПОДСАДИТЬ недостающий донор
// в дорогу вручную (одна ступень за вызов writeTrailMarks — канон),
// цепочка должна собрать пару (x0-донор из бима ⊕ донор из дороги)
// и сертифицировать. Это доказывает ДОСТАТОЧНОСТЬ переноса дороги
// ДО написания нишевой квоты (механическое обоснование фикса).
//
// Контроль: те же сиды без подсадки = 0 сертов (B1.1 леджер).

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/exp045/cert_utils.php';

use BeeSwarm\Core\Search;
use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Infra\Database;

putenv('SWARM_DB_PATH=:memory:');
putenv('NO_BIRTH=1');
putenv('TRAIL_BUDGET_MODE=steps');
putenv('TRAIL_BUDGET_STEPS=1500');
putenv('TRAIL_READ_K=5');
putenv('TRAIL_WRITE_DIVERSITY=1');
putenv('TRAIL_READ_DIVERSITY=1');

const WIDTH = 6;

function law(array $r): float { return $r[0] * $r[1] * $r[2] * $r[3]; }
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

function xToLabels(string $f): string {
    return (string) preg_replace_callback('/x(\d+)/', fn ($m) => 'c' . $m[1], $f);
}

/** Одна ступень дороги за вызов (канон: групповой засев cull'ится капом). */
function seedRung(string $form, array $labels, array $X, array $y): int {
    $rm = new ReflectionMethod(Search::class, 'writeTrailMarks');
    $rm->setAccessible(true);
    // лейблы раннера = x0..x5 (уже x-space) — имя передаётся КАК ЕСТЬ,
    // без xToLabels (тот маппит c→x и давал LABEL_UNRESOLVABLE → rung_written=0)
    $rm->invoke(null, $labels, [['name' => $form, 'corr' => 0.0]], INF, INF, [], [], $X, $y);
    return count(Search::$lastTrailWritten ?? []);
}

// Недостающие доноры по сидам (forensics B2: бим несёт только x0-префикс).
$missing = [503 => '(x1×x3)', 504 => '(x1×x3)', 505 => '(x1×x3)'];

$outF = $argv[1] ?? '/tmp/b2_pilot.jsonl';
foreach ([503, 504, 505] as $seed) {
    Database::reset();
    $X = genRows($seed, 200);
    $Yreal = array_map('law', $X);
    $labels = array_map(fn (int $i): string => "x{$i}", range(0, WIDTH - 1));
    mt_srand($seed);
    putenv('SEARCH_ROTATION_SEED=' . $seed);

    $n = seedRung($missing[$seed], $labels, $X, $Yreal);

    $certs = 0; $certForm = null; $certPass = null; $passRows = [];
    $t0 = microtime(true);
    for ($pass = 1; $pass <= 8; $pass++) {
        $genome = ['id' => 'b2p_' . $pass, 'parent_id' => '', 'condition' => ['kind' => 'ALWAYS'],
            'budget_fraction' => 0.30, 'max_steps' => 5,
            'actions' => [
                ['op' => 'USE_MARKS', 'k' => 5],
                ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
                ['op' => 'USE_MARKS', 'k' => 5],
                ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ]];
        $res = RouteExecutor::run($genome, $X, $Yreal, $labels, 0.001, 0.10, [], 1);
        $wd = 0; $rd = 0;
        foreach ($res['records'] as $rec) {
            if (($rec['op'] ?? '') !== 'FLIGHT') { continue; }
            foreach (($rec['trail']['written'] ?? []) as $w) { if (str_contains($w, '×')) { $wd++; } }
            $rd += count($rec['trail']['read_injected'] ?? []);
        }
        $partial = null;
        foreach ($res['records'] as $rec) {
            if (($rec['op'] ?? '') === 'FLIGHT' && ($rec['trail']['partial_form'] ?? 'none') !== 'none') {
                $partial = $rec['trail']['partial_form']; break;
            }
        }
        $passRows[] = ['pass' => $pass, 'wd' => $wd, 'rd' => $rd, 'partial' => $partial];
        if ($res['found']) {
            $fx = RouteExecutor::expandLocalAtoms(labels_to_x((string) $res['cert_formula']), $res['local_atoms']);
            $noEval = null;
            $v2 = cert_v2($fx, $X, $Yreal, $seed + 600, $noEval);
            if (is_array($v2) && $v2['pass']) {
                $certs = 1; $certForm = is_string($fx) ? $fx : (string) $res['cert_formula']; $certPass = $pass;
                break;
            }
        }
    }
    $row = ['pilot' => 'B2', 'seed' => $seed, 'rung' => $missing[$seed], 'rung_written' => $n,
        'certs' => $certs, 'cert_form' => $certForm, 'cert_pass' => $certPass,
        'pass_records' => $passRows, 'wall_ms' => (int) round((microtime(true) - $t0) * 1000)];
    file_put_contents($outF, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    echo 'PILOT seed=' . $seed . ' rung_written=' . $n . ' certs=' . $certs
        . ' pass=' . ($certPass ?? '-') . ' form=' . ($certForm ?? '-') . PHP_EOL;
}
