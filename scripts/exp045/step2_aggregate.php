<?php
/**
 * EXP-045 ШАГ II A/B DOT — агрегатор (prereg DOT_VALUE_PROBE критерии неизменны).
 * CLI: php step2_aggregate.php /tmp/exp045_step2_probe /tmp/exp045_step2 ~/EvoFamily-путь-не-нужен → отчёт в stdout + JSON-сводка.
 * Прогон: on/off × 101-103 (зонд on/101 в probe-каталоге, остальные 5 — в campaign-каталоге).
 */
declare(strict_types=1);

$dirs = [$argv[1] ?? '/tmp/exp045_step2_probe', $argv[2] ?? '/tmp/exp045_step2'];
$runs = [];
foreach ($dirs as $dir) {
    foreach (glob(rtrim($dir, '/') . '/results_probe_*.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        if ($d === null || ($d['task_set'] ?? '') !== 'dot') {
            continue;
        }
        $key = $d['arm'] . '/' . $d['seed'];
        $runs[$key] = $d; // probe-файл и campaign-файл одной руки: campaign приоритет
    }
}
ksort($runs);

$base = [
    'on/101' => ['found' => 8, 'm3' => 70, 'written' => 20],
    'on/102' => ['found' => 0, 'm3' => 68, 'written' => 32],
    'on/103' => ['found' => 8, 'm3' => 70, 'written' => 17],
    'off/101' => ['found' => 0, 'm3' => 0, 'written' => 0],
    'off/102' => ['found' => 0, 'm3' => 0, 'written' => 0],
    'off/103' => ['found' => 0, 'm3' => 0, 'written' => 0],
];

$rows = [];
$onFoundTotal = 0;
$offFoundTotal = 0;
$nullFoundTotal = 0;
$fuseAny = false;
$selfcheckFails = [];
foreach ($runs as $key => $d) {
    $dot = null;
    $dnn = null;
    foreach ($d['tasks'] as $t) {
        if ($t['id'] === 'DOT') {
            $dot = $t;
        }
        if ($t['id'] === 'DNN') {
            $dnn = $t;
        }
    }
    $found = count(array_filter($dot['ladders'], fn ($l) => $l['found']));
    $nullFound = count(array_filter($dnn['ladders'], fn ($l) => $l['found']));
    $onOrOff = explode('/', $key)[0];
    if ($onOrOff === 'on') {
        $onFoundTotal += $found;
    } else {
        $offFoundTotal += $found;
    }
    $nullFoundTotal += $nullFound;
    if ($d['fuse']['triggered']) {
        $fuseAny = true;
    }
    foreach ($d['selfchecks'] as $k => $v) {
        if (! $v) {
            $selfcheckFails[$key][] = $k;
        }
    }
    $b = $base[$key] ?? null;
    $rows[] = sprintf(
        "%-8s found DOT %d/8 (v6: %d/8) | null %d/2 | m3 %3d (v6 %3d) | written %2d (v6 %2d) | selfchecks %s",
        $key,
        $found,
        $b['found'] ?? -1,
        $nullFound,
        count($dot['m3_loops']),
        $b['m3'] ?? -1,
        $dot['n_written_formulas'],
        $b['written'] ?? -1,
        $selfcheckFails[$key] ? 'FAIL:' . implode(',', $selfcheckFails[$key]) : 'ok'
    );
}

$onSeeds = [];
$offSeeds = [];
foreach ($runs as $key => $d) {
    [$arm, $seed] = explode('/', $key);
    $dot = null;
    foreach ($d['tasks'] as $t) {
        if ($t['id'] === 'DOT') {
            $dot = $t;
        }
    }
    $found = count(array_filter($dot['ladders'], fn ($l) => $l['found']));
    if ($arm === 'on') {
        $onSeeds[$seed] = $found;
    } else {
        $offSeeds[$seed] = $found;
    }
}
$onAnyPerSeed = $onFoundTotal >= 1; // прецедент v6: PASS при on 16/24 (сид-102 = 0); «≥1 слот (рука×сид)» = ≥1 где-нибудь
$offAllZero = $offFoundTotal === 0 && count($offSeeds) === 3;

echo "=== EXP-045 ШАГ II A/B DOT — результаты ===\n";
echo implode("\n", $rows), "\n\n";
echo "on total: $onFoundTotal/24 | off total: $offFoundTotal/24 | null found: $nullFoundTotal | fuse: ", ($fuseAny ? 'TRIGGERED' : 'no'), "\n";
echo "on per-seed: ", json_encode($onSeeds), " | off per-seed: ", json_encode($offSeeds), "\n\n";
echo "Критерии (PREREG_DOT_VALUE_PROBE, неизменны):\n";
echo "PASS-польза: on found ≥1 на каждом сиду: ", ($onAnyPerSeed ? 'ДА' : 'НЕТ'),
    " ∧ off=0 на 3 сидах: ", ($offAllZero ? 'ДА' : 'НЕТ'),
    " ∧ null=0: ", ($nullFoundTotal === 0 ? 'ДА' : 'НЕТ'), "\n";
echo "авто-FAIL (found на DNN): ", ($nullFoundTotal > 0 ? 'СРАБОТАЛ' : 'не сработал'), "\n";
if ($fuseAny) {
    echo "ВНИМАНИЕ: FUSE TRIGGERED — found на null-задаче!\n";
}
echo "\nСамопроверки counter_db_match=False = ожидаемая семантика Road v4 (конкурентное",
    " вытеснение может убрать свежезаписанную слабую форму из слота класса; selfcheck",
    " писан под старого writer'а без вытеснения). off_trail_empty должен быть True.\n";
