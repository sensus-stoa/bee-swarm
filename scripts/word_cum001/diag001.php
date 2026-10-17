<?php

declare(strict_types=1);

/**
 * EVO-DIAG-001 CHECKPOINT B: диагностическая абляция границы W1→W2.
 *
 * Факторный дизайн 2×2 (INHERITED/FRESH-GENERIC × elite5/elite1) + RESTART
 * (диагностический ориентир с подсказкой — повреждённые истинные законы W2,
 * НЕ честный контроль переноса). WORD-STATIC (без рождений), одинаковые
 * бюджеты и операторы. ВСЕ программы на границе получают НОВУЮ оценку
 * fitness (дефект устаревших оценок confirmatory исключён по построению).
 *
 * Инструментация: разные родители с потомством, дети-превосходящие чемпиона
 * (train/valid раздельно), выживание превосходящих при отборе, AUC, стоимость.
 *
 * Запуск: php scripts/word_cum001/diag001.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/genome001/corpus_lib.php';
require dirname(__DIR__) . '/genome002/evolve_lib.php';
require __DIR__ . '/worlds.php';

use BeeSwarm\Core\WordCodec;
use BeeSwarm\Core\WordCodecException;
use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Infra\Database;

const GENS = 5;
const POP_PER_TASK = 10;
const ELITE_CELLS = [5, 1];
const ATTEMPTS_PER_GEN = 100;      // 50 на задачу
const COST_CANDIDATE = 20;
const REPS = 6;
const BASE_SEED = 20261201;

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

// ── Данные W2 (одни на все ячейки/реплики; сид = f(rep)) ────────────────
$tasksByRep = [];
$floorByRep = []; // validation-rmse истинных законов W2 (метрический floor, A2)
foreach (range(0, REPS - 1) as $rep) {
    $seedBase = BASE_SEED + 10007 * $rep;
    foreach ($w2['tasks']['train'] as $ti => $t) {
        $tasksByRep[$rep][$ti] = WordCumWorlds::task($t['formula'], $seedBase + 31 * $ti);
    }
    $floor = 0.0;
    foreach ($w2['tasks']['valid'] as $t) {
        $vtask = WordCumWorlds::task($t['formula'], $seedBase + 77);
        $trueAst = WordCodec::parseDefinition($t['formula']);
        $floor += (float) GenomeEvolveLib::rmse($trueAst, $vtask['held'], $vtask['y_held']);
    }
    $floorByRep[$rep] = $floor / 2;
}

// ── INHERITED: популяции из конца W1 confirmatory (arm A = WORD-STATIC) ──
$raw = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/Benchmarks/word_cum001/checkpoint_raw.json'), true, 512, JSON_THROW_ON_ERROR);
$inhSource = [];
foreach ($raw['results'] as $st) {
    if ($st['arm'] === 'A' && $st['w'] === 1 && $st['mode'] === 'inherited') {
        $inhSource[$st['rep']] = ['pops' => $st['pops'], 'dict' => $st['dict']];
    }
}
ksort($inhSource);
echo 'inherited sources (W1-end, arm A): ', count($inhSource), "\n";

// ── Ячейки ──────────────────────────────────────────────────────────────

function initCell(string $cell, int $rep, array $tasks, array $w2, array $inhSource, int $seedBase): array
{
    $rng = new Random\Randomizer(new Random\Engine\Mt19937(BASE_SEED + 555 * $rep));
    if ($cell === 'INH-e5' || $cell === 'INH-e1') {
        $src = $inhSource[$rep];
        $dict = $src['dict'];
        $pops = $src['pops']; // g-геномы закодированы против того же словаря (A-STATIC, рождений не было)
        $initNote = 'carried W1-end';
    } elseif ($cell === 'RESTART') {
        // диагностический ориентир: повреждённые истинные законы W2 (подсказка)
        $dict = [];
        $pops = [];
        foreach ($w2['tasks']['train'] as $ti => $t) {
            $seedAst = WordCodec::parseDefinition($t['formula']);
            $task = $tasks[$ti];
            $pop = [];
            for ($i = 0; $i < POP_PER_TASK; $i++) {
                for ($s = 0; $s < 20; $s++) {
                    $cand = GenomeEvolveLib::damageParent($seedAst, $seedBase + 200 + $ti * 97 + $i + $s);
                    $rowsAll = array_merge($task['train'], $task['held']);
                    $yAll = array_merge($task['y_train'], $task['y_held']);
                    if (GenomeEvolveLib::rmse($cand['ast'], $rowsAll, $yAll) !== null) {
                        $pop[] = ['g' => null, 'ast' => $cand['ast'], 'lin' => 'rst-' . $ti . '-' . $i, 'chain' => 0, 'train' => null, 'valid' => null];

                        break;
                    }
                }
            }
            $pops[$ti] = $pop;
        }
        // словарь из seed-программ с фильтром целых форм (как freshPackage)
        $seedAsts = array_map(static fn (array $t): array => WordCodec::parseDefinition($t['formula']), $w2['tasks']['train']);
        $dict = GenomeBenchmarkLib::extractDictionary(array_map(static fn (array $a): array => ['ast' => $a], $seedAsts));
        $finalKeys = [];
        foreach ($w2['tasks']['final'] as $t) {
            $finalKeys[GenomeBenchmarkLib::alphaCanonicalKey(WordCodec::parseDefinition($t['formula']))] = true;
        }
        $dict = array_values(array_filter($dict, static function (array $e) use ($finalKeys, $dict): bool {
            return ! isset($finalKeys[GenomeBenchmarkLib::alphaCanonicalKey(WordCodec::decodeDefinition($e, $dict))]);
        }));
        foreach ($pops as $ti => $pop) {
            foreach ($pop as $pi => $ind) {
                $pop[$pi]['g'] = WordCodec::encode($ind['ast'], $dict);
            }
            $pops[$ti] = $pop;
        }
        $initNote = 'damaged true laws of W2 (HINT)';
    } else {
        // FRESH-GENERIC: случайные программы, словарь из них (без знания истинных законов W2)
        // двухстадийно: provisional dict → фильтр декодируемости → финальный dict
        $randomAsts = [];
        $codecSkipped = 0;
        for ($i = 0; $i < 20; $i++) {
            $randomAsts[] = GenomeBenchmarkLib::randomProgram(12 + ($i % 3) * 4, $rng);
        }
        $provDict = GenomeBenchmarkLib::extractDictionary(array_map(static fn (array $p): array => ['ast' => $p['ast']], $randomAsts));
        $kept = [];
        foreach ($randomAsts as $p) {
            try {
                WordCodec::decode(WordCodec::encode($p['ast'], $provDict), $provDict);
                $kept[] = $p;
            } catch (WordCodecException) {
                // известный дефект codec: вложенное самоменение слова в аргументах
                // ложно классифицируется циклом (репродуцируется в отчёте); особь
                // заменяется — смещение FRESH задокументировано
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
        $randomAsts = $kept;
        $dict = GenomeBenchmarkLib::extractDictionary(array_map(static fn (array $p): array => ['ast' => $p['ast']], $randomAsts));
        $initNote = 'random programs (generic)' . ($codecSkipped > 0 ? "; codec-skipped={$codecSkipped}" : '');
        $pops = [[], []];
        for ($i = 0; $i < 20; $i++) {
            $ast = $randomAsts[$i]['ast'];
            $pops[$i < 10 ? 0 : 1][] = ['g' => WordCodec::encode($ast, $dict), 'ast' => $ast, 'lin' => 'rnd-' . $i, 'chain' => 0, 'train' => null, 'valid' => null];
        }
    }
    // геномная унификация: все индивиды получают 'g' (INH уже с g)
    foreach ($pops as $ti => $pop) {
        foreach ($pop as $pi => $ind) {
            if (! isset($ind['g']) || $ind['g'] === null) {
                try {
                    $pops[$ti][$pi]['g'] = WordCodec::encode($ind['ast'], $dict);
                    WordCodec::decode($pops[$ti][$pi]['g'], $dict);
                } catch (WordCodecException $e) {
                    // дамп графа ссылок словаря для диагноза цикла
                    echo "CYCLE-DUMP cell={$cell} rep={$rep}: ", $e->getMessage(), "\n";
                    $byName = [];
                    foreach ($dict as $e2) {
                        $byName[$e2['name']] = $e2;
                    }
                    foreach ($dict as $e2) {
                        $r = [];
                        $walk2 = static function (array $node) use (&$walk2, &$r): void {
                            if (isset($node['atom'])) {
                                return;
                            }
                            if (isset($node['word'])) {
                                $r[$node['word']] = true;

                                return;
                            }
                            foreach (['l', 'r'] as $k) {
                                if (isset($node[$k]) && is_array($node[$k])) {
                                    $walk2($node[$k]);
                                }
                            }
                            if (isset($node['args']) && is_array($node['args'])) {
                                foreach ($node['args'] as $a) {
                                    if (is_array($a)) {
                                        $walk2($a);
                                    }
                                }
                            }
                        };
                        $walk2($e2['genome']);
                        echo '  ', $e2['name'], ' nodes=', WordCodec::nodesCount($e2['genome']), ' refs=[', implode(',', array_keys($r)), '] def=', \BeeSwarm\Core\ExpressionNormalizer::render(WordCodec::decodeDefinition($e2, $dict)), "\n";
                    }
                    exit(1);
                }
            }
        }
    }

    return ['dict' => $dict, 'pops' => $pops, 'note' => $initNote];
}

// ── Прогон ячейки: 5 поколений × 100 попыток, полная переоценка ────────

function runCell(string $cell, int $elite, int $rep, array $tasks, array $inhSource, array $w2, float $floor): array
{
    $seedBase = BASE_SEED + 10007 * $rep;
    $init = initCell($cell, $rep, $tasks, $w2, $inhSource, $seedBase);
    $dict = $init['dict'];
    $pops = $init['pops'];
    $evals = 0;
    $attempts = 0;
    $curve = [];
    $champValidCurve = [];
    $stats = ['attempts' => 0, 'evaluated' => 0, 'beats_train' => 0, 'beats_valid' => 0, 'beats_survived' => 0, 'parents_used' => [], 'discarded_useful' => 0];

    // начальная переоценка ВСЕХ программ на W2 (граница)
    foreach ($tasks as $ti => $task) {
        foreach ($pops[$ti] as $pi => $ind) {
            $ast = null;
            for ($try = 0; $try < 30; $try++) {
                try {
                    $ast = WordCodec::decode($ind['g'], $dict);

                    break;
                } catch (WordCodecException $e) {
                    // codec defect: вложенное самоменение в аргументах ложно = цикл
                    // (WordCodec НЕ меняем; репродирующий геном в отчёте). Особь
                    // заменяется генератором, одинаково доступным всем рукам.
                    $stats['codec_nested'] = ($stats['codec_nested'] ?? 0) + 1;
                    $rng = new Random\Randomizer(new Random\Engine\Mt19937($seedBase + 31337 * $pi + $try));
                    $p = GenomeBenchmarkLib::randomProgram(12 + ($try % 3) * 4, $rng);
                    $ind['g'] = WordCodec::encode($p['ast'], $dict);
                    $ind['lin'] = 'rnd-repl-' . $pi . '-' . $try;
                }
            }
            if ($ast === null) {
                $pops[$ti][$pi]['train'] = null;
                $pops[$ti][$pi]['valid'] = null;

                continue;
            }
            $pops[$ti][$pi]['train'] = GenomeEvolveLib::rmse($ast, $task['train'], $task['y_train']);
            $pops[$ti][$pi]['valid'] = GenomeEvolveLib::rmse($ast, $task['held'], $task['y_held']);
            $evals += COST_CANDIDATE;
        }
    }

    for ($gen = 1; $gen <= GENS; $gen++) {
        $champTrain = null;
        $champValid = null;
        foreach ($pops as $pop) {
            foreach ($pop as $ind) {
                if ($ind['train'] !== null && ($champTrain === null || $ind['train'] < $champTrain)) {
                    $champTrain = $ind['train'];
                }
                if ($ind['valid'] !== null && ($champValid === null || $ind['valid'] < $champValid)) {
                    $champValid = $ind['valid'];
                }
            }
        }
        $champValidCurve[] = $champValid;

        $offspring = [[], []];
        $survivorLin = [];
        foreach ($tasks as $ti => $task) {
            $pop = $pops[$ti];
            for ($o = 0; $o < ATTEMPTS_PER_GEN / 2; $o++) {
                if ($evals + COST_CANDIDATE > 20000) {
                    break 2;
                }
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
                $stats['attempts']++;
                if ($mut === null) {
                    continue;
                }
                $evals += COST_CANDIDATE;
                try {
                    $ast = WordCodec::decode($mut, $dict);
                } catch (WordCodecException $e) {
                    // codec nested-self-application defect — задокументировано, особь невалидна
                    $stats['codec_nested'] = ($stats['codec_nested'] ?? 0) + 1;

                    continue;
                }
                $train = GenomeEvolveLib::rmse($ast, $task['train'], $task['y_train']);
                $valid = GenomeEvolveLib::rmse($ast, $task['held'], $task['y_held']);
                if ($train === null || $valid === null) {
                    continue;
                }
                $stats['evaluated']++;
                $stats['parents_used'][$best['lin']] = true;
                $lin = $best['lin'] . '/d' . $gen . 'o' . $o;
                $ind = ['g' => $mut, 'lin' => $lin, 'chain' => 0, 'train' => $train, 'valid' => $valid];
                if ($valid < $champValid) {
                    $ind['_beat'] = true;
                }
                $offspring[$ti][] = $ind;
                if ($train < $champTrain) {
                    $stats['beats_train']++;
                }
                if ($valid < $champValid) {
                    $stats['beats_valid']++;
                }
            }
        }

        // отбор: elite по train + лучшие потомки; считаем выживание превосходящих
        $newPops = [];
        foreach ($tasks as $ti => $task) {
            $eliteArr = array_filter($pops[$ti], static fn ($x): bool => $x['train'] !== null);
            usort($eliteArr, static fn (array $a, array $b): int => $a['train'] <=> $b['train']);
            $kids = array_filter($offspring[$ti], static fn ($x): bool => $x['train'] !== null);
            usort($kids, static fn (array $a, array $b): int => $a['train'] <=> $b['train']);
            $new = array_merge(array_slice($eliteArr, 0, $elite), array_slice($kids, 0, POP_PER_TASK - $elite));
            while (count($new) < POP_PER_TASK) {
                $new[] = $pops[$ti][count($new) % max(1, count($pops[$ti]))];
            }
            foreach ($new as $ind) {
                $survivorLin[$ind['lin']] = true;
            }
            $newPops[$ti] = $new;
        }
        // выживание превосходящих валидацию (по обеим задачам)
        foreach ([0, 1] as $ti) {
            foreach ($offspring[$ti] as $ind) {
                if (! empty($ind['_beat'])) {
                    if (isset($survivorLin[$ind['lin']])) {
                        $stats['beats_survived']++;
                    } else {
                        $stats['discarded_useful']++;
                    }
                }
            }
        }
        $pops = $newPops;

        // кривая адаптации: gen-best held-out (среднее по задачам)
        $sum = 0.0;
        $n = 0;
        foreach ($tasks as $ti => $task) {
            $best = null;
            foreach ($pops[$ti] as $ind) {
                if ($ind['valid'] !== null && ($best === null || $ind['valid'] < $best)) {
                    $best = $ind['valid'];
                }
            }
            if ($best !== null) {
                $sum += $best;
                $n++;
            }
        }
        $curve[] = $n > 0 ? $sum / $n : null;
    }

    $initBest = $champValidCurve[0] ?? null;
    $finalBest = $curve[count($curve) - 1] ?? null;
    $norm = [];
    foreach ($champValidCurve as $i => $v) {
        if ($v === null || $initBest === null || $initBest - $floor <= 0) {
            continue;
        }
        $norm[] = max(0.0, min(1.0, ($v - $floor) / ($initBest - $floor)));
    }
    $auc = $norm === [] ? null : array_sum($norm) / count($norm);

    return [
        'cell' => $cell, 'rep' => $rep, 'elite' => $elite,
        'init_note' => $init['note'],
        'init_best_valid' => $initBest,
        'final_best_valid' => $finalBest,
        'improvement' => ($initBest !== null && $finalBest !== null && $initBest > 0) ? ($initBest - $finalBest) / $initBest : null,
        'auc' => $auc,
        'beats_train' => $stats['beats_train'],
        'beats_valid' => $stats['beats_valid'],
        'beats_survived' => $stats['beats_survived'],
        'discarded_useful' => $stats['discarded_useful'],
        'parents_used' => count($stats['parents_used']),
        'attempts' => $stats['attempts'],
        'evaluated' => $stats['evaluated'],
        'evals' => $evals,
        'curve' => $champValidCurve,
        'floor' => $floor,
    ];
}

// ── Прогон всех ячеек ──────────────────────────────────────────────────

$cells = [];
$t0 = hrtime(true);
foreach (['INH-e5', 'INH-e1', 'FRESH-e5', 'FRESH-e1', 'RESTART'] as $cell) {
    foreach (range(0, REPS - 1) as $rep) {
        $elite = $cell === 'INH-e5' || $cell === 'FRESH-e5' ? 5 : ($cell === 'RESTART' ? 5 : 1);
        $res = runCell($cell, $elite, $rep, $tasksByRep[$rep], $inhSource, $w2, $floorByRep[$rep]);
        $cells[] = $res;
    }
    $last = $cells[count($cells) - 1];
    echo $cell, ': ', json_encode([
        'init_med' => round(median(array_column($cells, 'init_best_valid')), 4),
        'impr_med' => round(median(array_map(static fn ($x) => $x['improvement'] ?? 0, array_slice($cells, -REPS))), 4),
        'auc_med' => round(median(array_map(static fn ($x) => $x['auc'] ?? 1, array_slice($cells, -REPS))), 4),
        'beats_valid' => array_sum(array_column(array_slice($cells, -REPS), 'beats_valid')),
        'survived' => array_sum(array_column(array_slice($cells, -REPS), 'beats_survived')),
    ]), "\n";
}
$wall = round((hrtime(true) - $t0) / 1e9, 1);

function median(array $a): float
{
    if ($a === []) {
        return 0.0;
    }
    sort($a);

    return $a[intdiv(count($a), 2)];
}

echo "wall={$wall}s\n";
file_put_contents(
    dirname(__DIR__, 2) . '/Benchmarks/word_cum001/DIAG001.json',
    json_encode(['cells' => $cells, 'wall_s' => $wall], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);
echo "written: Benchmarks/word_cum001/DIAG001.json\n";
