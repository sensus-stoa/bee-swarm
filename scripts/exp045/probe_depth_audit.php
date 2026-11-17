<?php

declare(strict_types=1);

/**
 * EXP-045 ШАГ IV AUDIT (owner: «depth=3 доходит до Search?» — гейт программы
 * genome transfer). Одноразовая проба: сравнение depth-2 / depth-3 полётов
 * на DOT-данных, TRAIL_FIX=pool.
 *
 * Сигнатуры:
 *  - [F d2]        → written max+ ≤ 1 (depth-2 без меток не пишет 3-term)
 *  - [F d3]        → written max+ = 2 (L2L1 = L1⊕Фича) — СИГНАТУРА depth-3
 *  - [F d3,M,F d3] → read_injected > 0 (инъекции на depth-3) + fixAppended > 0
 *
 * CLI: php probe_depth_audit.php <outDir>
 */

require __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Infra\Database;

$outDir = $argv[1] ?? '/tmp/exp045_depth_audit';
@mkdir($outDir, 0777, true);
putenv('SWARM_DB_PATH=:memory:');
putenv('SEARCH_RAWPAIR_POOL=1');
putenv('TRAIL_FIX=pool');
putenv('TRAIL_MARKS=1');
putenv('TRAIL_READ_K=5');

// --- DOT-данные (копия генератора probe_loop, сид 101) ---
$s = 101;
$r = function () use (&$s): float {
    $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;
    return $s / 0x7FFFFFFF;
};
$X = [];
$y = [];
for ($i = 0; $i < 200; $i++) {
    $row = [];
    for ($j = 0; $j < 6; $j++) {
        $row[] = round($r(), 6);
    }
    $X[] = $row;
    $y[] = $row[0] * $row[3] + $row[1] * $row[4] + $row[2] * $row[5];
}
$labels = ['c0', 'c1', 'c2', 'c3', 'c4', 'c5'];

$f2 = ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2];
$f3 = ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 3];
$f1 = ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 1];
$m5 = ['op' => 'USE_MARKS', 'k' => 5];

$variants = [
    'd1' => [RouteGenomeV([[$f1]])],
    'd1chain' => [RouteGenomeV([[$f1, $m5, $f1]])],
    // Division-of-labor проба (owner flag): d1-производитель кормит дорогу,
    // M читает, d2-потребитель композит. Вопрос: injected>0 (d2 ЧИТАЕТ
    // L1-материал d1)?
    'mixed' => [RouteGenomeV([[$f1, $m5, $f2]])],
    'd2' => [RouteGenomeV([[$f2]])],
    'd3' => [RouteGenomeV([[$f3]])],
    'd3chain' => [RouteGenomeV([[$f3, $m5, $f3]])],
];

function RouteGenomeV(array $actionSets): array
{
    static $n = 0;
    $n++;
    $actions = [];
    $maxSteps = 0;
    foreach ($actionSets[0] as $a) {
        $actions[] = $a;
        $maxSteps++;
    }
    $g = [
        'id' => 'route_' . substr(sha1('depth_audit_' . $n), 0, 8),
        'parent_id' => '',
        'condition' => ['kind' => 'ALWAYS'],
        'budget_fraction' => 0.30,
        'max_steps' => $maxSteps,
        'actions' => $actions,
    ];
    $errors = \BeeSwarm\Hive\RouteGenome::validate($g);
    if ($errors !== []) {
        fwrite(STDERR, 'GENOME INVALID: ' . json_encode($errors) . PHP_EOL);
        exit(3);
    }

    return $g;
}

$results = [];
foreach ($variants as $name => $genomes) {
    Database::get()->exec('DELETE FROM trail_marks');
    Database::get()->exec('DELETE FROM trail_ghosts');
    putenv('TRAIL_MARKS=1');
    $genome = $genomes[0];
    $marksMode = 1;
    $res = RouteExecutor::run($genome, $X, $y, $labels, 15.0, 0.10, [], $marksMode);
    $maxPlus = 0;
    $writtenTotal = 0;
    $injected = 0;
    $perFlight = [];
    foreach ($res['records'] as $rec) {
        if (($rec['op'] ?? '') !== 'FLIGHT') {
            continue;
        }
        // read_injected живёт на FLIGHT-записях (контракт probe_loop):
        // полёт репортит, что ОН инъектировал из дороги.
        $injected += count($rec['trail']['read_injected'] ?? []);
        $wf = $rec['trail']['written'] ?? [];
        $writtenTotal += count($wf);
        $mp = 0;
        foreach ($wf as $f) {
            $mp = max($mp, substr_count($f, '+'));
        }
        $maxPlus = max($maxPlus, $mp);
        // 0h-флаг owner: ПРИРОДА d1-материала — какие именно формы пишет
        // d1 (тривиальные x0/x1 или L1-композиции)? Определяет реалистичность
        // исходов (б)/(в) division of labor в Stage A.
        $perFlight[] = ['wall' => round($rec['wall_s'] ?? 0, 2), 'written' => count($wf), 'maxplus' => $mp, 'injected' => count($rec['trail']['read_injected'] ?? []), 'written_sample' => array_slice($wf, 0, 8)];
    }
    $dbAfter = Database::get()->query('SELECT COUNT(*) c FROM trail_marks')->fetch(PDO::FETCH_ASSOC)['c'];
    $ghosts = Database::get()->query('SELECT COUNT(*) c FROM trail_ghosts')->fetch(PDO::FETCH_ASSOC)['c'];
    $results[$name] = [
        'found' => $res['found'],
        'stop' => $res['stop'],
        'written_total' => $writtenTotal,
        'max_plus' => $maxPlus,
        'read_injected' => $injected,
        'fix_appended_last' => count(\BeeSwarm\Core\Search::$lastPoolFixAppended),
        'db_marks' => (int) $dbAfter,
        'db_ghosts' => (int) $ghosts,
        'per_flight' => $perFlight,
    ];
    echo $name, ': found=', var_export($res['found'], true),
    ' written=', $writtenTotal, ' max+=', $maxPlus,
    ' injected=', $injected,
    ' fixAppended_last=', count(\BeeSwarm\Core\Search::$lastPoolFixAppended),
    ' marks=', $dbAfter, ' ghosts=', $ghosts, PHP_EOL;
}

file_put_contents($outDir . '/depth_audit.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo '-> ', $outDir, '/depth_audit.json', PHP_EOL;
