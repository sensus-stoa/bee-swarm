<?php

declare(strict_types=1);

/**
 * EVO-FIX-001 Часть B: проверка качества отбора после исправлений.
 *
 * Граница W1→W2, 6 парных сидов, 5 поколений, популяция 20, 100 попыток/ген.
 * Ячейки 2×2: {INHERITED (FIX-1 переоценка), FRESH-GENERIC} × {TRAIN-ONLY,
 * ROBUST (= 0.5·train_rmse + 0.5·valid_rmse selection-validation, заморожено)}.
 * Рождения отключены (WORD-STATIC); операторы мутаций и WordCodec исходные.
 * RESTART исключён из причинного сравнения (подсказка, EVO-DIAG-001).
 *
 * Final test W2: измеряется КАЖДОЕ поколение для динамики (диагностика),
 * НЕ используется в отборе/рождении/параметрах (A3).
 *
 * Запуск: php scripts/word_cum001/diag002.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/genome001/corpus_lib.php';
require dirname(__DIR__) . '/genome002/evolve_lib.php';
require __DIR__ . '/worlds.php';
require __DIR__ . '/transition.php';

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\WordCodec;
use BeeSwarm\Core\WordCodecException;
use BeeSwarm\Infra\Database;

const GENS = 5;
const POP_PER_TASK = 10;
const ATTEMPTS_PER_GEN = 100;      // 50 на задачу
const COST_CANDIDATE = 20;
const REPS = 6;
const BASE_SEED = 20261201;
const ROBUST_VALID_WEIGHT = 0.5;   // заморожено: score = (1-w)·train + w·valid

putenv('SWARM_DB_PATH=:memory:');
Database::reset();
ExpressionEvaluator::resetCaches();

$worlds = WordCumWorlds::worlds();
$w2 = $worlds[2];
$lawKeys = [];
foreach (['train', 'valid'] as $role) {
    foreach ($w2['tasks'][$role] as $t) {
        $lawKeys[GenomeBenchmarkLib::alphaCanonicalKey(WordCodec::parseDefinition($t['formula']))] = true;
    }
}
$finalTask = WordCumWorlds::task($w2['tasks']['final'][0]['formula'], BASE_SEED + 424242);
$finalKeys = [];
foreach ($w2['tasks']['final'] as $t) {
    $finalKeys[GenomeBenchmarkLib::alphaCanonicalKey(WordCodec::parseDefinition($t['formula']))] = true;
}

// данные W2 по репликам
$tasksByRep = [];
$floorByRep = [];
foreach (range(0, REPS - 1) as $rep) {
    $seedBase = BASE_SEED + 10007 * $rep;
    foreach ($w2['tasks']['train'] as $ti => $t) {
        $tasksByRep[$rep][$ti] = WordCumWorlds::task($t['formula'], $seedBase + 31 * $ti);
    }
    $floor = 0.0;
    foreach ($w2['tasks']['valid'] as $t) {
        $vtask = WordCumWorlds::task($t['formula'], $seedBase + 77);
        $floor += (float) GenomeEvolveLib::rmse(WordCodec::parseDefinition($t['formula']), $vtask['held'], $vtask['y_held']);
    }
    $floorByRep[$rep] = $floor / 2;
}

// INHERITED источник: конец W1 (arm A = WORD-STATIC) из confirmatory
$raw = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/Benchmarks/word_cum001/checkpoint_raw.json'), true, 512, JSON_THROW_ON_ERROR);
$inhSource = [];
foreach ($raw['results'] as $st) {
    if ($st['arm'] === 'A' && $st['w'] === 1 && $st['mode'] === 'inherited') {
        $inhSource[$st['rep']] = [
            'pops' => $st['pops'],
            'dict' => $st['dict'],
        ];
    }
}
ksort($inhSource);

function initCell(string $init, int $rep, array $tasks, array $w2, array $inhSource): array
{
    $seedBase = BASE_SEED + 10007 * $rep;
    if ($init === 'INHERITED') {
        $src = $inhSource[$rep];
        // FIX-1: переоценка всех переносимых особей на W2 (дебет внутри)
        $evals = 0;
        $pops = WordCumTransition::reevaluate($src['pops'], $tasks, 2, WordCumTransition::fitnessHash(2, $w2), 'A', $src['dict'], $evals, 10 ** 9);

        return [
            'dict' => $src['dict'],
            'pops' => $pops,
            'evals' => $evals,
            'note' => 'carried W1-end, re-evaluated (FIX-1)',
        ];
    }
    // FRESH-GENERIC: случайные программы, словарь из них (без истинных формул W2)
    $rng = new Random\Randomizer(new Random\Engine\Mt19937(BASE_SEED + 555 * $rep));
    $randomAsts = [];
    $codecSkipped = 0;
    for ($i = 0; $i < 20; $i++) {
        $randomAsts[] = GenomeBenchmarkLib::randomProgram(12 + ($i % 3) * 4, $rng);
    }
    $provDict = GenomeBenchmarkLib::extractDictionary(array_map(static fn (array $p): array => [
        'ast' => $p['ast'],
    ], $randomAsts));
    $kept = [];
    foreach ($randomAsts as $p) {
        try {
            WordCodec::decode(WordCodec::encode($p['ast'], $provDict), $provDict);
            $kept[] = $p;
        } catch (WordCodecException) {
            // codec nested-self-application defect (EVO-DIAG-001 Q5) — особь заменяется
            $codecSkipped++;
            for ($try = 0; $try < 30; $try++) {
                $p2 = GenomeBenchmarkLib::randomProgram(12 + ($i % 3) * 4, $rng);
                try {
                    WordCodec::decode(WordCodec::encode($p2['ast'], $provDict), $provDict);
                    $kept[] = $p2;

                    break;
                } catch (WordCodecException) {
                    $codecSkipped++;
                }
            }
        }
    }
    $dict = GenomeBenchmarkLib::extractDictionary(array_map(static fn (array $p): array => [
        'ast' => $p['ast'],
    ], $kept));
    $pops = [[], []];
    for ($i = 0; $i < 20; $i++) {
        $ast = $kept[$i]['ast'];
        $pops[$i < 10 ? 0 : 1][] = [
            'g' => WordCodec::encode($ast, $dict),
            'lin' => 'rnd-' . $i,
            'chain' => 0,
            'train' => null,
            'valid' => null,
        ];
    }

    return [
        'dict' => $dict,
        'pops' => $pops,
        'evals' => 0,
        'note' => 'random programs (generic)' . ($codecSkipped > 0 ? "; skipped={$codecSkipped}" : ''),
    ];
}

/**
 * Оценка кандидата на train+valid W2 (20 строк).
 */
function evalCandidate(array $g, array $dict, array $task): ?array
{
    try {
        $ast = WordCodec::decode($g, $dict);
    } catch (WordCodecException) {
        return null; // codec nested-self-application defect — невалидный кандидат
    }
    $train = GenomeEvolveLib::rmse($ast, $task['train'], $task['y_train']);
    $valid = GenomeEvolveLib::rmse($ast, $task['held'], $task['y_held']);
    if ($train === null || $valid === null) {
        return null;
    }

    return [
        'g' => $g,
        'lin' => '',
        'chain' => 0,
        'train' => $train,
        'valid' => $valid,
        'ast' => $ast,
    ];
}

/**
 * Отбор: TRAIN-ONLY или ROBUST (0.5·train + 0.5·valid, заморожено).
 */
function select(array $pops, string $selection, int $elite): array
{
    $newPops = [];
    foreach ($pops as $ti => $pop) {
        $score = static function (array $x) use ($selection): float {
            if ($selection === 'ROBUST') {
                return (1 - ROBUST_VALID_WEIGHT) * (float) $x['train'] + ROBUST_VALID_WEIGHT * (float) $x['valid'];
            }

            return (float) $x['train'];
        };
        $eliteArr = array_filter($pop, static fn ($x): bool => $x['train'] !== null);
        usort($eliteArr, static fn (array $a, array $b): int => $score($a) <=> $score($b));
        $newPops[$ti]['elite'] = array_slice($eliteArr, 0, $elite);
        $newPops[$ti]['score'] = $score;
    }

    return $newPops;
}

/**
 * Прогон ячейки: 5 поколений, полная инструментация + final-test динамика.
 */
function runCell(string $init, string $selection, int $rep, array $tasks, array $inhSource, array $w2, array $finalTask, float $thetaFloor): array
{
    $seedBase = BASE_SEED + 10007 * $rep;
    $initData = initCell($init, $rep, $tasks, $w2, $inhSource);
    $dict = $initData['dict'];
    $pops = $initData['pops'];
    $evals = $initData['evals'];
    $attempts = 0;
    $elite = $selection === 'TRAIN-ONLY' ? 5 : 5; // элита 5 в обоих вариантах (варьируется только сигнал)
    $theta = 0.0; // на задачу ниже
    $stats = [
        'offspring' => 0,
        'final_improved' => 0,
        'final_improved_selected' => 0,
        'chain_nodes' => [],
    ];
    $finalCurve = [];
    $prevFinalBest = null;
    $prevSelectedLin = [];
    $t0 = hrtime(true);

    for ($gen = 1; $gen <= GENS; $gen++) {
        $offspring = [[], []];
        foreach ($tasks as $ti => $task) {
            $pop = $pops[$ti];
            for ($o = 0; $o < ATTEMPTS_PER_GEN / 2; $o++) {
                $best = $pop[array_rand($pop)];
                for ($t = 0; $t < 2; $t++) {
                    $c = $pop[array_rand($pop)];
                    if ($c['train'] !== null && ($best['train'] === null || $c['train'] < $best['train'])) {
                        $best = $c;
                    }
                }
                $rng = new Random\Randomizer(new Random\Engine\Mt19937($seedBase + 7007 * $gen + 31 * $ti + $o));
                $mut = null;
                try {
                    $r = GenomeEvolveLib::mutateGenome($best['g'], $rng, $dict);
                    $mut = $r['noop'] ? null : $r['genome'];
                } catch (Throwable) {
                    $mut = null;
                }
                $attempts++;
                if ($mut === null) {
                    continue;
                }
                $cand = evalCandidate($mut, $dict, $task);
                if ($cand === null) {
                    continue;
                }
                $evals += COST_CANDIDATE;
                // цепь: значимое validation-улучшение родителя (θ = 3σ шума held-out)
                $meanY = array_sum(array_map('abs', $task['y_held'])) / count($task['y_held']);
                $thetaTask = 3 * 0.02 / sqrt(3) * $meanY;
                $improved = $cand['valid'] < (float) $best['valid'] - $thetaTask;
                $cand['lin'] = $best['lin'] . '/g' . $gen . 'o' . $o;
                $cand['parent_lin'] = $best['lin'];
                $cand['chain'] = $improved ? $best['chain'] + 1 : 1;
                if ($cand['chain'] >= 2) {
                    $stats['chain_nodes'][] = [
                        'lin' => $cand['lin'],
                        'root' => strtok($cand['lin'], '/'),
                        'chain' => $cand['chain'],
                        'gen' => $gen,
                    ];
                }
                $offspring[$ti][] = $cand;
            }
        }

        // отбор (train-only или robust) + инструментация выживания
        $sel = select($pops, $selection, $elite);
        $newPops = [];
        foreach ($tasks as $ti => $task) {
            $score = $sel[$ti]['score'];
            $el = $sel[$ti]['elite'];
            $kids = array_filter($offspring[$ti], static fn ($x): bool => $x['train'] !== null);
            usort($kids, static fn (array $a, array $b): int => $score($a) <=> $score($b));
            $new = array_merge($el, array_slice($kids, 0, POP_PER_TASK - count($el)));
            while (count($new) < POP_PER_TASK) {
                $new[] = $pops[$ti][count($new) % max(1, count($pops[$ti]))];
            }
            $newPops[$ti] = $new;
        }
        $pops = $newPops;

        // final test: ДИАГНОСТИКА (не дебетуется, не входит в решения)
        $bestFinal = null;
        $bestFinalLin = null;
        foreach ($tasks as $ti => $task) {
            foreach ($pops[$ti] as $ind) {
                if ($ind['train'] === null) {
                    continue;
                }
                $ast = WordCodec::decode($ind['g'], $dict);
                $v = GenomeEvolveLib::rmse($ast, $finalTask['train'], $finalTask['y_train']);
                if ($v !== null && ($bestFinal === null || $v < $bestFinal)) {
                    $bestFinal = $v;
                    $bestFinalLin = $ind['lin'];
                }
            }
        }
        if ($bestFinal !== null) {
            if (isset($prevFinalBest) && $bestFinal < $prevFinalBest) {
                $stats['final_improved']++;
                if (isset($prevSelectedLin[$bestFinalLin])) {
                    $stats['final_improved_selected']++;
                }
            }
            $prevFinalBest = $prevFinalBest === null ? $bestFinal : min($prevFinalBest, $bestFinal);
        }
        $prevSelectedLin = [];
        foreach ($pops as $pop) {
            foreach ($pop as $ind) {
                $prevSelectedLin[$ind['lin']] = true;
            }
        }
        $finalCurve[] = $bestFinal;
    }

    // цепи: максимальные по корням (перенос chain через популяцию упрощён — см. raw)
    $wall = (hrtime(true) - $t0) / 1e6;

    return [
        'init' => $init,
        'selection' => $selection,
        'rep' => $rep,
        'init_note' => $initData['note'],
        'final_curve' => $finalCurve,
        'final_best' => $prevFinalBest ?? null,
        'final_improved_events' => $stats['final_improved'],
        'max_chain' => $stats['chain_nodes'] === [] ? 0 : max(array_column($stats['chain_nodes'], 'chain')),
        'chains' => count(array_count_values(array_map(static fn ($n): string => (string) $n['root'], array_filter($stats['chain_nodes'], static fn ($n): bool => $n['chain'] >= 3)))),
        'attempts' => $attempts,
        'evals' => $evals,
        'wall_ms' => round($wall, 1),
        'init_note_full' => $stats,
    ];
}

// ── Прогон 2×2 ─────────────────────────────────────────────────────────

$cells = [];
$t0 = hrtime(true);
foreach (['INHERITED', 'FRESH-GENERIC'] as $init) {
    foreach (['TRAIN-ONLY', 'ROBUST'] as $selection) {
        for ($rep = 0; $rep < REPS; $rep++) {
            $cells[] = runCell($init, $selection, $rep, $tasksByRep[$rep], $inhSource, $w2, $finalTask, 0.0);
        }
        $slice = array_slice($cells, -REPS);
        $fb = array_map(static fn ($x) => $x['final_best'], $slice);
        sort($fb);
        echo sprintf(
            "%s | %s: final_best_med=%s evals=%d wall=%dms\n",
            $init,
            $selection,
            $fb[0] === null ? 'null' : round($fb[intdiv(count($fb), 2)], 4),
            array_sum(array_column($slice, 'evals')),
            (int) array_sum(array_column($slice, 'wall_ms'))
        );
    }
}
$wall = round((hrtime(true) - $t0) / 1e9, 1);
echo "wall={$wall}s\n";

file_put_contents(
    dirname(__DIR__, 2) . '/Benchmarks/word_cum001/DIAG002.json',
    json_encode([
        'conf' => [
            'gens' => GENS,
            'attempts' => ATTEMPTS_PER_GEN,
            'reps' => REPS,
            'robust_weight' => ROBUST_VALID_WEIGHT,
        ],
        'wall_s' => $wall,
        'cells' => $cells,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);
echo "written: Benchmarks/word_cum001/DIAG002.json\n";
