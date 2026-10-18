<?php

declare(strict_types=1);

/**
 * EXP-045 D4 LOOP PROBE (prereg v2, PREREG_D4_LOOP_PROBE.md) — одноразовый
 * экспериментальный скрипт, НЕ постоянный модуль движка (решение owner 18.10:
 * «просто напиши пробу, тесты для этого не писать»).
 *
 * Вопрос: замыкает ли стигмергия петлю запись→чтение в условиях, где петля
 * достижима (сконструированные лестницы beam10, явный бюджет) и измерима
 * (событийная телеметрия Search + дамп trail_marks из :memory: до смерти процесса).
 *
 * Материализация задач — дословная копия gen_family/parse_csv из
 * scripts/exp045/driver.php @ eb97bf4 (провенанс; детерминизм по spec-seed).
 *
 * CLI: php probe_loop.php <arm:on|off> <seed> <familiesJson> <outDir>
 * Руки: on = marksMode 1 (write+read), off = marksMode 0 (негатив-контроль P1).
 */

require __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Hive\RouteExecutor;
use BeeSwarm\Infra\Database;

$arm = $argv[1] ?? '';
$seed = (int) ($argv[2] ?? 0);
$famJson = $argv[3] ?? '';
$outDir = $argv[4] ?? '/tmp/exp045_probe_out';
$taskSet = $argv[5] ?? 'families'; // 'families' (D4+DN) | 'dot' (DOT+DNN — польза)
if (! in_array($arm, ['on', 'off'], true) || ! in_array($taskSet, ['families', 'dot'], true)
    || ($taskSet === 'families' && ! is_file($famJson))) {
    fwrite(STDERR, "usage: probe_loop.php <arm:on|off> <seed> <familiesJson> <outDir> [taskSet:families|dot]\n");
    exit(2);
}
@mkdir($outDir, 0777, true);

putenv('SWARM_DB_PATH=:memory:');
// EXP-040-конфиг паритета: RAWPAIR-пул — источник продуктов в pair-пуле
// (в EXP-040 EXT 19/20 продукты приходили через SEARCH_RAWPAIR_POOL=1;
// без него пар-пул не имеет родителей для mark⊕parent).
putenv('SEARCH_RAWPAIR_POOL=1');
mt_srand($seed);

$marksMode = $arm === 'on' ? 1 : 0;
$CHANNEL_SEC = 15.0; // фиксированный канал лестницы (prereg §1: без 80/20-сплита)
$LADDERS = ['D4' => 8, 'DN1' => 2, 'DN2' => 2]; // P4-матрица

// ---- материализация (копия из driver.php @ eb97bf4 — провенанс) ----

function gen_family(array $p, string $path): void
{
    $s = $p['seed'];
    $r = function () use (&$s) {
        $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;

        return $s / 0x7FFFFFFF;
    };
    $u = function (float $a, float $b) use ($r): float {
        return $a + $r() * ($b - $a);
    };
    $rows = [];
    for ($i = 0; $i < 200; $i++) {
        $c = [$u(1, 3), $u(2, 5), $u(3, 7), $u(0.5, 2), $u(0, 9)];
        switch ($p['class']) {
            case 'flux': $y = 2.0 * ($c[2] - $c[1]) * $c[0] / $c[3];
                break;
            case 'diffprod': $y = 1.5 * ($c[1] - $c[0]) * $c[0];
                break;
            case 'ratio': $y = ($c[0] * $c[1]) / $c[3];
                break;
            case 'composition': $y = ($c[0] - $c[1]) * ($c[0] - $c[1]) + $c[2] * $c[3];
                break;
            case 'power': $y = $c[0] * $c[0] + $c[1] * $c[2];
                break;
            case 'pureprod': $y = $c[0] * $c[3];
                break;
            default: $y = 0.0;
        }
        if (! empty($p['noise'])) {
            $y *= 1 + ($r() - 0.5) * $p['noise'];
        }
        $row = [];
        foreach ($p['perm'] as $src) {
            $row[] = $c[$src];
        }
        $row[] = $y;
        $rows[] = $row;
    }
    if (! empty($p['null_shuffle'])) {
        mt_srand($p['seed']);
        $ys = array_column($rows, 5);
        for ($i = count($ys) - 1; $i > 0; $i--) {
            $j = (int) floor(mt_rand() / (mt_getrandmax() + 1) * ($i + 1));
            [$ys[$i], $ys[$j]] = [$ys[$j], $ys[$i]];
        }
        foreach ($rows as $i => $row) {
            $rows[$i][5] = $ys[$i];
        }
    }
    $f = fopen($path, 'w');
    foreach ($rows as $r2) {
        fputcsv($f, $r2);
    }
    fclose($f);
}

function parse_csv(string $path): array
{
    $X = [];
    $y = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $v = str_getcsv($line);
        if (count($v) < 6 || ! is_numeric($v[0])) {
            continue;
        }
        $X[] = array_map('floatval', array_slice($v, 0, 5));
        $y[] = (float) $v[5];
    }

    return [$X, $y];
}

// ---- DOT VALUE PROBE (prereg DOT_VALUE_PROBE.md): локальный генератор ----
// y = x0·x3 + x1·x4 + x2·x5 — depth-2 без меток НЕ собирается (EXP-040
// baseline 0/20), mark⊕parent собирает. LCG тот же стиль, детерминизм по сиду.

function dot_task(int $seed): array
{
    $s = $seed;
    $r = function () use (&$s) {
        $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;

        return $s / 0x7FFFFFFF;
    };
    $u = function (float $a, float $b) use ($r): float {
        return $a + $r() * ($b - $a);
    };
    $X = [];
    $y = [];
    for ($i = 0; $i < 200; $i++) {
        $row = [$u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2), $u(-2, 2)];
        $X[] = $row;
        $y[] = $row[0] * $row[3] + $row[1] * $row[4] + $row[2] * $row[5];
    }

    return [$X, $y];
}

function dot_null(int $seed): array
{
    [$X, $y] = dot_task($seed);
    mt_srand($seed);
    for ($i = count($y) - 1; $i > 0; $i--) {
        $j = (int) floor(mt_rand() / (mt_getrandmax() + 1) * ($i + 1));
        [$y[$i], $y[$j]] = [$y[$j], $y[$i]];
    }

    return [$X, $y];
}

// ---- лестница (prereg §1: [FLIGHT beam10, USE_MARKS, FLIGHT, USE_MARKS, FLIGHT]) ----

function ladder_genome(int $i): array
{
    $g = [
        'id' => 'route_' . substr(sha1('probe_ladder_' . $i), 0, 8),
        'parent_id' => '',
        'condition' => ['kind' => 'ALWAYS'],
        'budget_fraction' => 0.30, // валидируется схемой; probe'ом НЕ применяется (P8)
        'max_steps' => 5,
        'actions' => [
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
            ['op' => 'USE_MARKS', 'k' => 5],
            ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 2],
        ],
    ];
    $errors = RouteGenomeValidator($g);
    if ($errors !== []) {
        fwrite(STDERR, 'LADDER GENOME INVALID: ' . json_encode($errors) . PHP_EOL);
        exit(3);
    }

    return $g;
}

function RouteGenomeValidator(array $g): array
{
    return \BeeSwarm\Hive\RouteGenome::validate($g);
}

function trail_dump(): array
{
    return Database::get()
        ->query('SELECT id, formula, kind, corr, uses FROM trail_marks ORDER BY id')
        ->fetchAll(PDO::FETCH_ASSOC);
}

// ---- прогон ----

$jobs = [];
if ($taskSet === 'dot') {
    [$Xd, $yd] = dot_task($seed);
    [$Xn, $yn] = dot_null($seed + 7);
    $jobs[] = ['id' => 'DOT', 'n' => 8, 'X' => $Xd, 'y' => $yd, 'is_null' => false];
    $jobs[] = ['id' => 'DNN', 'n' => 2, 'X' => $Xn, 'y' => $yn, 'is_null' => true];
} else {
    $params = json_decode(file_get_contents($famJson), true);
    $byId = [];
    foreach ($params['tasks'] as $t) {
        $byId[$t['id']] = $t;
    }
    foreach (['D4', 'DN1', 'DN2'] as $taskId) {
        $spec = $byId[$taskId];
        $tmpCsv = tempnam(sys_get_temp_dir(), 'probe_') . '.csv';
        gen_family($spec, $tmpCsv);
        [$X, $y] = parse_csv($tmpCsv);
        unlink($tmpCsv);
        $jobs[] = ['id' => $taskId, 'n' => $LADDERS[$taskId] ?? 2, 'X' => $X, 'y' => $y,
            'is_null' => $taskId !== 'D4'];
    }
}
$labelsUnused = null; // labels вычисляются per-task по ширине X (см. цикл выше)

$selfchecks = ['off_trail_empty' => true, 'counter_db_match' => true];
$fuse = ['triggered' => false, 'where' => []];
$tasksOut = [];

foreach ($jobs as $job) {
    $taskId = $job['id'];
    $X = $job['X'];
    $y = $job['y'];
    $labels = array_map(fn (int $i): string => "c{$i}", range(0, count($X[0]) - 1));

    Database::get()->exec('DELETE FROM trail_marks');
    $nLadders = $job['n'];
    $writtenBy = [];   // formula => первый route_index, записавший её
    $loops = [];       // M3: перекрёстные звенья (r2>r1, та же формула)
    $selfLoops = 0;
    $laddersOut = [];

    for ($i = 0; $i < $nLadders; $i++) {
        $genome = ladder_genome($i);
        $res = RouteExecutor::run($genome, $X, $y, $labels, $CHANNEL_SEC, 0.10, [], $marksMode);

        // Самопроверка телеметрии: каждая формула из trail.written обязана
        // быть в БД сразу после маршрута (свежая запись uses=1 — sweep не
        // трогает; нарушение = сломанная телеметрия, не механизм).
        $dbFormulas = array_column(trail_dump(), 'formula');
        foreach ($res['records'] as $rec) {
            if (($rec['op'] ?? '') !== 'FLIGHT') {
                continue;
            }
            $tr = $rec['trail'] ?? null;
            if ($tr === null) {
                $selfchecks['counter_db_match'] = false; // телеметрия отсутствует
                continue;
            }
            foreach ($tr['written'] as $wf) {
                if (! in_array($wf, $dbFormulas, true)) {
                    $selfchecks['counter_db_match'] = false;
                }
            }
        }

        // Негатив-контроль (P1): arm=off — таблица обязана оставаться пустой
        if ($marksMode === 0 && trail_dump() !== []) {
            $selfchecks['off_trail_empty'] = false;
        }

        // События маршрута: writtenBy (первый писатель) + инъекции
        $injectedHere = [];
        foreach ($res['records'] as $rec) {
            if (($rec['op'] ?? '') !== 'FLIGHT') {
                continue;
            }
            foreach (($rec['trail']['written'] ?? []) as $wf) {
                if (! isset($writtenBy[$wf])) {
                    $writtenBy[$wf] = $i;
                }
            }
            foreach (($rec['trail']['read_injected'] ?? []) as $rf) {
                $injectedHere[] = $rf;
            }
        }
        foreach ($injectedHere as $rf) {
            if (isset($writtenBy[$rf])) {
                if ($writtenBy[$rf] < $i) {
                    $loops[] = ['formula' => $rf, 'r1' => $writtenBy[$rf], 'r2' => $i, 'dist' => $i - $writtenBy[$rf]];
                } else {
                    $selfLoops++; // прочитал собственную дорогу (не M3)
                }
            }
        }

        $laddersOut[] = [
            'route_index' => $i,
            'found' => $res['found'],
            'cert_formula' => $res['cert_formula'],
            'best_L_valid' => $res['best_L_valid'],
            'wall' => round($res['wall'], 3),
            'unspent' => round($res['unspent'], 3),
            'stop' => $res['stop'],
            'records' => $res['records'],
            'trail_dump_after' => trail_dump(),
        ];

        // FPR-предохранитель: found на null-задаче (консервативный суперсет сертификации)
        if ($job['is_null'] && $res['found']) {
            $fuse['triggered'] = true;
            $fuse['where'][] = ['task' => $taskId, 'route_index' => $i, 'seed' => $seed];
        }
    }

    $tasksOut[] = [
        'id' => $taskId,
        'n_ladders' => $nLadders,
        'ladders' => $laddersOut,
        'm3_loops' => $loops,
        'm3_self_loops' => $selfLoops,
        'n_written_formulas' => count($writtenBy),
    ];
}

$out = [
    'run_id' => 'probe_' . $arm . '_' . $seed . ($taskSet === 'dot' ? '_dot' : ''),
    'arm' => $arm,
    'marks_mode' => $marksMode,
    'seed' => $seed,
    'task_set' => $taskSet,
    'channel_sec' => $CHANNEL_SEC,
    'ladders' => $taskSet === 'dot' ? ['DOT' => 8, 'DNN' => 2] : $LADDERS,
    'selfchecks' => $selfchecks,
    'fuse' => $fuse,
    'tasks' => $tasksOut,
];
$outPath = $outDir . '/results_probe_' . $arm . '_' . $seed . ($taskSet === 'dot' ? '_dot' : '') . '.json';
file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'DONE ', $out['run_id'],
': m3_loops=', array_sum(array_map(fn ($t) => count($t['m3_loops']), $tasksOut)),
' self_loops=', array_sum(array_map(fn ($t) => $t['m3_self_loops'], $tasksOut)),
' fuse=', $fuse['triggered'] ? 'TRIGGERED' : 'no',
' selfchecks=', json_encode($selfchecks),
' -> ', $outPath, PHP_EOL;
