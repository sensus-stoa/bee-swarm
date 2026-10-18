<?php

declare(strict_types=1);

/**
 * WORD-CUM-001 CHECKPOINT B: минимальный многопоколенный цикл (AMENDMENT v2).
 *
 * Population → Mutation → Evaluation(train) → Selection → Inheritance → Next.
 * Руки: A=WORD-STATIC, B=WORD-LEARN, C=WORD-SHUFFLE, D=AST-SPLICE; RESTART
 * инстансы на границах миров (A5). Инстансы ресумабельны по окнам — C требует
 * window-lockstep между 6 репликами (перестановка слов между линиями, A4).
 *
 * Режимы: --dev (калибровка θ/ΔAUC, проверка механики) | --confirm (полный прогон).
 *
 * Search/applyN/грамматика не затрагиваются; WordCodec не меняется.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/genome001/corpus_lib.php';
require dirname(__DIR__) . '/genome002/evolve_lib.php';
require __DIR__ . '/worlds.php';
require __DIR__ . '/transition.php';

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\WordCodec;
use BeeSwarm\Infra\Database;

const GENS = 15;
const GENS_PER_WINDOW = 2;
const POP_PER_TASK = 10;
const ELITE = 5;
const OFFSPRING_PER_TASK = 25;
const COST_CANDIDATE = 20;
const EVAL_POOL = 16000;
const BIRTH_WINDOW_EVERY = 2;
const SUBTREE_MIN = 4;
const SUBTREE_MAX = 12;
const DICT_CAP = 8;
const REPS = 6;
const BASE_SEED = 20261201;

$isDev = in_array('--dev', $argv, true);
$devWorld = 0;
foreach ($argv ?? [] as $a) {
    if (str_starts_with((string) $a, '--world=')) {
        $devWorld = (int) substr((string) $a, 8);
    }
}
$CONF = [
    'gens' => $isDev ? 4 : GENS,
    'reps' => $isDev ? 2 : REPS,
    'worlds' => $isDev ? [$devWorld] : [0, 1, 2],
    'theta_rel' => 0.02,           // доля rmse родителя (A6)
    'theta_floor_frac' => 0.035,   // 3σ шума валидации (0.02/√3×3), калибруется на DEV
    'dauc' => 0.05,                // минимальный AUC-эффект (A2), калибруется на DEV
];

putenv('SWARM_DB_PATH=:memory:');
Database::reset();
ExpressionEvaluator::resetCaches();

// ── Мир: ключи и пакеты ────────────────────────────────────────────────

/**
 * Alpha-ключи задач мира. $withFinal=false для birth-gate (A3).
 */
function worldLawKeys(array $world, bool $withFinal): array
{
    $keys = [];
    foreach (['train', 'valid'] as $role) {
        foreach ($world['tasks'][$role] as $t) {
            $keys[GenomeBenchmarkLib::alphaCanonicalKey(WordCodec::parseDefinition($t['formula']))] = true;
        }
    }
    if ($withFinal) {
        foreach ($world['tasks']['final'] as $t) {
            $keys[GenomeBenchmarkLib::alphaCanonicalKey(WordCodec::parseDefinition($t['formula']))] = true;
        }
    }

    return $keys;
}

/**
 * Стартовый пакет: seed-AST (train-законы + binding-варианты), словарь, пул.
 */
function freshPackage(array $world, int $rep, int $w): array
{
    $seedAsts = [];
    foreach ($world['tasks']['train'] as $ti => $t) {
        $ast = WordCodec::parseDefinition($t['formula']);
        $seedAsts[] = $ast;
        $seedAsts[] = GenomeEvolveLib::instantiateShape($ast, new Random\Randomizer(new Random\Engine\Mt19937(BASE_SEED + 100 * $w + 17 * $ti + $rep)));
    }
    $dict = GenomeBenchmarkLib::extractDictionary(array_map(static fn (array $a): array => [
        'ast' => $a,
    ], $seedAsts));
    // чистота стартового пакета (A6, owner §6): слова != целые формы FINAL-задач
    // (train-законы в словаре = легитимное prior-знание, симметричное всем рукам)
    $finalKeys = [];
    foreach ($world['tasks']['final'] as $t) {
        $finalKeys[GenomeBenchmarkLib::alphaCanonicalKey(WordCodec::parseDefinition($t['formula']))] = true;
    }
    $dict = array_values(array_filter($dict, static function (array $e) use ($finalKeys, $dict): bool {
        return ! isset($finalKeys[GenomeBenchmarkLib::alphaCanonicalKey(WordCodec::decodeDefinition($e, $dict))]);
    }));
    $pool = array_map(static fn (array $e): array => [
        'ast' => WordCodec::decodeDefinition($e, $dict),
    ], $dict);

    return [
        'seedAsts' => $seedAsts,
        'dict' => $dict,
        'pool' => $pool,
    ];
}

/**
 * Proper-subtree кандидаты (4..12 узлов, глубина ≥1 — целая форма не кандидат).
 */
function subtreeCandidates(array $ast): array
{
    $out = [];
    $walk = static function (array $node, int $depth) use (&$walk, &$out): void {
        if (isset($node['atom'])) {
            return;
        }
        $n = WordCodec::nodesCount($node);
        if ($depth >= 1 && $n >= SUBTREE_MIN && $n <= SUBTREE_MAX) {
            $out[] = $node;
        }
        foreach (['l', 'r'] as $k) {
            if (isset($node[$k]) && is_array($node[$k])) {
                $walk($node[$k], $depth + 1);
            }
        }
    };
    $walk($ast, 0);

    return $out;
}

/**
 * Alpha-ключи словаря (duplicate-check birth-gate, A3).
 */
function dictAlphaKeys(array $dict): array
{
    $keys = [];
    foreach ($dict as $e) {
        $keys[GenomeBenchmarkLib::alphaCanonicalKey(WordCodec::decodeDefinition($e, $dict))] = true;
    }

    return $keys;
}

/**
 * Стартовая популяция задачи: 10 живых повреждённых копий seed-закона.
 */
function seedPopulation(array $task, array $seedAst, int $seed): array
{
    $rowsAll = array_merge($task['train'], $task['held']);
    $yAll = array_merge($task['y_train'], $task['y_held']);
    $pop = [];
    for ($i = 0; $i < POP_PER_TASK; $i++) {
        for ($s = 0; $s < 20; $s++) {
            $cand = GenomeEvolveLib::damageParent($seedAst, $seed + 97 * $i + $s);
            if (GenomeEvolveLib::rmse($cand['ast'], $rowsAll, $yAll) !== null) {
                $pop[] = [
                    'ast' => $cand['ast'],
                    'g' => null,
                    'lin' => $seed . '-t' . $i,
                    'chain' => 0,
                    'train' => null,
                    'valid' => null,
                ];

                break;
            }
        }
    }

    return $pop;
}

// ── Инстанс (ресумабельный по окнам) ───────────────────────────────────

function initInstance(string $arm, array $world, int $rep, int $w, string $mode, ?array $carry, int $seedBase): array
{
    $package = freshPackage($world, $rep, $w);
    $state = [
        'arm' => $arm,
        'rep' => $rep,
        'w' => $w,
        'mode' => $mode,
        'dict' => $package['dict'],
        'pool' => $package['pool'],
        'gen' => 0,
        'evals' => 0,
        'attempts' => 0,
        'improved' => 0,
        'births' => [],
        'word_usage' => [],
        'rootChainMax' => [],
        'genBestValid' => [],
        'birthChecks' => 0,
    ];
    $fitnessHash = WordCumTransition::fitnessHash($w, $world);
    $state['fitness_hash'] = $fitnessHash;
    $tasks = [];
    foreach ($world['tasks']['train'] as $ti => $t) {
        $tasks[$ti] = WordCumWorlds::task($t['formula'], $seedBase + 31 * $ti);
    }
    if ($mode === 'inherited' && $carry !== null) {
        $state['dict'] = $carry['dict'];
        $state['pool'] = $carry['pool'];
        $state['pops'] = $carry['pops'];
        // FIX-1 (EVO-FIX-001): переоценка ВСЕХ переносимых особей на данных
        // нового мира + world_id/fitness_hash; старые оценки не переживают границу
        $state['pops'] = WordCumTransition::reevaluate($state['pops'], $tasks, $w, $fitnessHash, $arm, $arm === 'D' ? null : $state['dict'], $state['evals'], EVAL_POOL);
    } else {
        foreach ($world['tasks']['train'] as $ti => $t) {
            $task = $tasks[$ti];
            $pop = seedPopulation($task, $package['seedAsts'][$ti], $seedBase + 200 + $ti);
            // геномные руки: энкод против словаря; начальная оценка (дебет пула)
            foreach ($pop as $pi => $ind) {
                if ($arm !== 'D') {
                    $pop[$pi]['g'] = WordCodec::encode($ind['ast'], $state['dict']);
                }
                if ($state['evals'] + COST_CANDIDATE <= EVAL_POOL) {
                    $ast = $arm === 'D' ? $ind['ast'] : WordCodec::decode($pop[$pi]['g'], $state['dict']);
                    $pop[$pi]['train'] = GenomeEvolveLib::rmse($ast, $task['train'], $task['y_train']);
                    $pop[$pi]['valid'] = GenomeEvolveLib::rmse($ast, $task['held'], $task['y_held']);
                    $pop[$pi]['world_id'] = $w;
                    $pop[$pi]['fitness_hash'] = $fitnessHash;
                    $state['evals'] += COST_CANDIDATE;
                }
            }
            $state['pops'][$ti] = $pop;
        }
    }

    return $state;
}

/**
 * Продвинуть инстанс на $gens поколений. Возвращает window-кандидаты рождения
 * (для C-перестановки снаружи). Все eval-потребители дебетуют пул.
 */
function advanceInstance(array $state, array $world, array $conf, array $lawKeys, int $gens, int $seedBase): array
{
    $arm = $state['arm'];
    // FIX-1 гард: все особи инстанса обязаны быть одного fitness-мира
    foreach ($state['pops'] ?? [] as $pop) {
        WordCumTransition::assertComparable($pop);
    }
    $tasks = [];
    foreach ($world['tasks']['train'] as $ti => $t) {
        $tasks[$ti] = WordCumWorlds::task($t['formula'], $seedBase + 31 * $ti);
    }
    // θ: 2% rmse родителя, но не ниже 3σ шума валидации (A6; σ = 0.02/√3 |y|)
    $thetaFloor = [];
    foreach ($tasks as $ti => $task) {
        $meanY = array_sum(array_map('abs', $task['y_held'])) / count($task['y_held']);
        $thetaFloor[$ti] = $conf['theta_floor_frac'] * $meanY;
    }

    $windowCandidates = [];
    for ($g = 0; $g < $gens; $g++) {
        $state['gen']++;
        $gen = $state['gen'];
        $offspring = [[], []];
        $improvedAsts = [[], []];

        foreach ($tasks as $ti => $task) {
            $pop = $state['pops'][$ti];
            for ($o = 0; $o < OFFSPRING_PER_TASK; $o++) {
                if ($state['evals'] + COST_CANDIDATE > EVAL_POOL) {
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
                $attempts = 0;
                $mut = null;
                try {
                    if ($arm === 'D') {
                        $r = GenomeEvolveLib::mutateAst($best['ast'], $rng, $state['pool']);
                        $mut = $r['noop'] ? null : [
                            'ast' => $r['ast'],
                        ];
                    } else {
                        $r = GenomeEvolveLib::mutateGenome($best['g'], $rng, $state['dict']);
                        $mut = $r['noop'] ? null : [
                            'g' => $r['genome'],
                        ];
                    }
                } catch (Throwable) {
                    $mut = null;
                }
                $state['attempts']++;
                if ($mut === null) {
                    continue;
                }
                $state['evals'] += COST_CANDIDATE;
                $ast = $arm === 'D' ? $mut['ast'] : WordCodec::decode($mut['g'], $state['dict']);
                $train = GenomeEvolveLib::rmse($ast, $task['train'], $task['y_train']);
                $valid = GenomeEvolveLib::rmse($ast, $task['held'], $task['y_held']);
                if ($train === null || $valid === null) {
                    continue; // некорректен
                }
                $theta = max($conf['theta_rel'] * (float) $best['valid'], $thetaFloor[$ti]);
                $improved = $best['valid'] !== null && $valid < $best['valid'] - $theta;
                $chain = $improved ? $best['chain'] + 1 : 1;
                $lin = $best['lin'] . '/g' . $gen . 'o' . $o;
                $ind = [
                    'ast' => $arm === 'D' ? $ast : null,
                    'g' => $arm === 'D' ? null : $mut['g'],
                    'lin' => $lin,
                    'chain' => $chain,
                    'train' => $train,
                    'valid' => $valid,
                ];
                $offspring[$ti][] = $ind;
                if ($improved) {
                    $state['improved']++;
                    $improvedAsts[$ti][] = $ast;
                    $root = strtok($lin, '/');
                    $state['rootChainMax'][$root] = max($state['rootChainMax'][$root] ?? 0, $chain);
                }
            }
        }

        // отбор: элита по train + лучшие потомки
        $newPops = [];
        foreach ($tasks as $ti => $task) {
            $elite = array_filter($state['pops'][$ti], static fn ($x): bool => $x['train'] !== null);
            usort($elite, static fn (array $a, array $b): int => $a['train'] <=> $b['train']);
            $kids = array_filter($offspring[$ti], static fn ($x): bool => $x['train'] !== null);
            usort($kids, static fn (array $a, array $b): int => $a['train'] <=> $b['train']);
            $new = array_merge(array_slice($elite, 0, ELITE), array_slice($kids, 0, POP_PER_TASK - ELITE));
            while (count($new) < POP_PER_TASK) {
                $new[] = $state['pops'][$ti][count($new) % max(1, count($state['pops'][$ti]))];
            }
            $newPops[$ti] = $new;
        }
        $state['pops'] = $newPops;

        // кривая: gen-best valid (среднее по задачам)
        $sum = 0.0;
        $n = 0;
        foreach ($tasks as $ti => $task) {
            $best = null;
            foreach ($state['pops'][$ti] as $ind) {
                if ($ind['valid'] !== null && ($best === null || $ind['valid'] < $best)) {
                    $best = $ind['valid'];
                }
            }
            if ($best !== null) {
                $sum += $best;
                $n++;
            }
        }
        $state['genBestValid'][] = $n > 0 ? $sum / $n : null;

        // использование слов (M4)
        foreach ([0, 1] as $ti) {
            foreach ($offspring[$ti] as $ind) {
                if ($ind['g'] === null) {
                    continue;
                }
                $refs = [];
                array_walk_recursive($ind['g'], static function ($v, $k) use (&$refs): void {
                    if ($k === 'word') {
                        $refs[(string) $v] = true;
                    }
                });
                foreach (array_keys($refs) as $wn) {
                    $state['word_usage'][$wn][$gen] = ($state['word_usage'][$wn][$gen] ?? 0) + 1;
                }
            }
        }

        // birth-gate: чётные поколения, окна
        if ($gen % BIRTH_WINDOW_EVERY === 0 && in_array($arm, ['B', 'C', 'D'], true)) {
            $perTask = [];
            foreach ([0, 1] as $ti) {
                foreach ($improvedAsts[$ti] as $ast) {
                    foreach (subtreeCandidates($ast) as $sub) {
                        $k = GenomeBenchmarkLib::alphaCanonicalKey($sub);
                        $perTask[$k][$ti] = true;
                        $perTask[$k]['ast'] ??= $sub;
                    }
                }
            }
            foreach ($perTask as $k => $info) {
                if (! isset($info[0]) || ! isset($info[1])) {
                    continue; // переносимая польза: ОБЕ задачи (§5.4)
                }
                if (isset($lawKeys[$k])) {
                    continue; // целая форма train/valid задачи; final НЕ используется (A3)
                }
                $already = false;
                foreach ($state['dict'] as $e) {
                    if (('BW' . md5($k)) === $e['name']) {
                        $already = true;
                        break;
                    }
                }
                if ($already || count($state['dict']) >= DICT_CAP) {
                    continue;
                }
                if ($state['evals'] + COST_CANDIDATE > EVAL_POOL) {
                    continue;
                }
                $state['evals'] += COST_CANDIDATE; // верификация дебетуется (A4)
                $state['birthChecks']++;
                $canonical = GenomeBenchmarkLib::alphaCanonical($info['ast']);
                $defStr = \BeeSwarm\Core\ExpressionNormalizer::render($canonical);
                $verify = WordCumWorlds::task($defStr, BASE_SEED + 999 + $state['w'] * 13);
                $alive = true;
                foreach (array_merge($verify['train'], $verify['held']) as $row) {
                    $v = ExpressionEvaluator::evalNode(WordCodec::parseDefinition($defStr), $row, [], []);
                    if ($v === null || ! is_finite($v)) {
                        $alive = false;
                        break;
                    }
                }
                if (! $alive) {
                    continue;
                }
                $entry = WordCodec::makeEntry('BW' . md5($k), $canonical, $state['dict']);
                $windowCandidates[] = [
                    'entry' => $entry,
                    'alpha' => substr(md5($k), 0, 8),
                    'gen' => $gen,
                    'nodes' => WordCodec::nodesCount($entry['genome']),
                ];
            }
        }
        // инъекция собственных рождений: B → словарь, D → пул (C — снаружи, перестановка)
        if ($arm === 'B') {
            foreach ($windowCandidates as $wc) {
                $state['dict'][] = $wc['entry'];
                $state['births'][] = [
                    'gen' => $wc['gen'],
                    'alpha' => $wc['alpha'],
                    'name' => $wc['entry']['name'],
                    'nodes' => $wc['nodes'],
                ];
            }
        }
        if ($arm === 'D') {
            foreach ($windowCandidates as $wc) {
                $state['pool'][] = [
                    'ast' => WordCodec::decodeDefinition($wc['entry'], $state['dict']),
                ];
            }
        }
    }

    return [$state, $windowCandidates];
}

/**
 * Финальный тест: однократный, НЕ дебетует пул (A3).
 */
function finalShot(array $state, array $world, int $seedBase): ?float
{
    $finalTask = WordCumWorlds::task($world['tasks']['final'][0]['formula'], $seedBase + 424242);
    $best = null;
    foreach ($state['pops'] as $pop) {
        foreach ($pop as $ind) {
            if ($ind['train'] === null) {
                continue;
            }
            $ast = $ind['ast'] ?? WordCodec::decode($ind['g'], $state['dict']);
            $v = GenomeEvolveLib::rmse($ast, $finalTask['train'], $finalTask['y_train']);
            if ($v !== null && ($best === null || $v < $best)) {
                $best = $v;
            }
        }
    }

    return $best;
}

// ── Драйвер ────────────────────────────────────────────────────────────

echo 'mode=', $isDev ? 'DEV' : 'CONFIRMATORY', "\n";
$conf = $CONF; // единое имя конфигурации
$worlds = WordCumWorlds::worlds();
$t0 = hrtime(true);
$results = [];
$nWindows = intdiv($conf['gens'] + GENS_PER_WINDOW - 1, GENS_PER_WINDOW);

/**
 * Полный прогон инстанса — руки без межрепликовой синхронизации (A/B/D).
 */
function runFull(string $arm, array $world, int $rep, int $wi, string $mode, ?array $carry, array $conf, array $lawKeys, int $seedBase): array
{
    $state = initInstance($arm, $world, $rep, $wi, $mode, $carry, $seedBase);
    if ($mode === 'inherited' && isset($carry['retentionRows'])) {
        foreach ($carry['retentionRows'] as $ti => $ptask) {
            foreach ($state['pops'][$ti] ?? [] as $ind) {
                if ($ind['train'] === null) {
                    continue;
                }
                $ast = $arm === 'D' ? $ind['ast'] : WordCodec::decode($ind['g'], $state['dict']);
                GenomeEvolveLib::rmse($ast, $ptask['train'], $ptask['y_train']);
                $state['evals'] += 12;
            }
        }
    }
    $nWindows = intdiv($conf['gens'] + GENS_PER_WINDOW - 1, GENS_PER_WINDOW);
    for ($win = 0; $win < $nWindows; $win++) {
        [$state] = advanceInstance($state, $world, $conf, $lawKeys, min(GENS_PER_WINDOW, $conf['gens'] - $win * GENS_PER_WINDOW), $seedBase);
    }
    $state['final'] = finalShot($state, $world, $seedBase);

    return $state;
}

/**
 * C: window-lockstep — рождения переставляются между независимыми линиями (A4).
 */
function runCLockstep(string $arm, array $world, int $wi, string $mode, array $carryStore, array $conf, array $lawKeys): array
{
    $states = [];
    $out = [];
    for ($rep = 0; $rep < $conf['reps']; $rep++) {
        $seedBase = BASE_SEED + 10007 * $rep;
        $carry = ($mode === 'inherited') ? ($carryStore[$rep] ?? null) : null;
        $states[$rep] = initInstance($arm, $world, $rep, $wi, $mode, $carry, $seedBase);
        if ($mode === 'inherited' && isset($carry['retentionRows'])) {
            foreach ($carry['retentionRows'] as $ti => $ptask) {
                foreach ($states[$rep]['pops'][$ti] ?? [] as $ind) {
                    if ($ind['train'] === null) {
                        continue;
                    }
                    GenomeEvolveLib::rmse(WordCodec::decode($ind['g'], $states[$rep]['dict']), $ptask['train'], $ptask['y_train']);
                    $states[$rep]['evals'] += 12;
                }
            }
        }
    }
    $nWindows = intdiv($conf['gens'] + GENS_PER_WINDOW - 1, GENS_PER_WINDOW);
    for ($win = 0; $win < $nWindows; $win++) {
        $gensLeft = min(GENS_PER_WINDOW, $conf['gens'] - $win * GENS_PER_WINDOW);
        $pending = [];
        foreach ($states as $rep => $state) {
            $seedBase = BASE_SEED + 10007 * $rep;
            [$states[$rep], $cands] = advanceInstance($state, $world, $conf, $lawKeys, $gensLeft, $seedBase);
            $pending[$rep] = $cands;
        }
        $reps = array_keys($states);
        foreach ($reps as $i => $r) {
            $donor = $reps[($i + 1) % count($reps)];
            foreach ($pending[$donor] ?? [] as $wc) {
                if (count($states[$r]['dict']) >= DICT_CAP) {
                    break;
                }
                $states[$r]['dict'][] = $wc['entry'];
                $states[$r]['births'][] = [
                    'gen' => $wc['gen'],
                    'alpha' => $wc['alpha'],
                    'name' => $wc['entry']['name'],
                    'nodes' => $wc['nodes'],
                    'shuffled_from' => $donor,
                ];
            }
        }
    }
    foreach ($states as $rep => $state) {
        $seedBase = BASE_SEED + 10007 * $rep;
        $state['final'] = finalShot($state, $world, $seedBase);
        $out[] = $state;
    }

    return $out;
}

$carryStore = [];
foreach ($conf['worlds'] as $wi) {
    $world = $worlds[$wi];
    $lawKeys = worldLawKeys($world, false); // A3: birth-gate БЕЗ final-ключей
    // аудит чистоты стартового пакета (разовый, A6): слова != целые формы задач (вкл. final)
    $pkgKeys = dictAlphaKeys(freshPackage($world, 0, $wi)['dict']);
    foreach (array_diff_key(worldLawKeys($world, true), worldLawKeys($world, false)) as $k => $_) {
        if (isset($pkgKeys[$k])) {
            echo "PURITY-FAIL: world {$wi} — слово == целая FINAL-задача\n";
            exit(1);
        }
    }
    foreach (['A', 'B', 'C', 'D'] as $arm) {
        if ($arm !== 'C') {
            for ($rep = 0; $rep < $conf['reps']; $rep++) {
                $seedBase = BASE_SEED + 10007 * $rep;
                $mode = ($wi === 0) ? 'fresh' : 'inherited';
                $carry = $mode === 'inherited' ? ($carryStore[$arm][$rep] ?? null) : null;
                $state = runFull($arm, $world, $rep, $wi, $mode, $carry, $conf, $lawKeys, $seedBase);
                $carryStore[$arm][$rep] = [
                    'dict' => $state['dict'],
                    'pool' => $state['pool'],
                    'pops' => $state['pops'],
                    'retentionRows' => [
                        0 => WordCumWorlds::task($world['tasks']['train'][0]['formula'], $seedBase + 31),
                        1 => WordCumWorlds::task($world['tasks']['train'][1]['formula'], $seedBase + 32),
                    ],
                ];
                $results[] = $state;
                echo sprintf(
                    "W%d rep%d %s[%s]: maxchain=%d chains=%d improved=%d births=%d dict=%d evals=%d final=%s\n",
                    $wi,
                    $rep,
                    $arm,
                    $mode,
                    $state['rootChainMax'] === [] ? 0 : max($state['rootChainMax']),
                    count(array_filter($state['rootChainMax'], static fn ($l): bool => $l >= 3)),
                    $state['improved'],
                    count($state['births']),
                    count($state['dict']),
                    $state['evals'],
                    $state['final'] === null ? 'null' : round($state['final'], 4)
                );
            }
            if ($wi > 0) {
                for ($rep = 0; $rep < $conf['reps']; $rep++) {
                    $seedBase = BASE_SEED + 10007 * $rep;
                    $state = runFull($arm, $world, $rep, $wi, 'restart', null, $conf, $lawKeys, $seedBase);
                    $results[] = $state;
                    echo sprintf(
                        "W%d rep%d %s[rst]: maxchain=%d improved=%d evals=%d final=%s\n",
                        $wi,
                        $rep,
                        $arm,
                        $state['rootChainMax'] === [] ? 0 : max($state['rootChainMax']),
                        $state['improved'],
                        $state['evals'],
                        $state['final'] === null ? 'null' : round($state['final'], 4)
                    );
                }
            }
            continue;
        }
        // C: window-lockstep (перестановка рождений между линиями, A4)
        $mode = ($wi === 0) ? 'fresh' : 'inherited';
        foreach (runCLockstep('C', $world, $wi, $mode, $carryStore['C'] ?? [], $conf, $lawKeys) as $state) {
            $rep = $state['rep'];
            $seedBase = BASE_SEED + 10007 * $rep;
            $carryStore['C'][$rep] = [
                'dict' => $state['dict'],
                'pool' => $state['pool'],
                'pops' => $state['pops'],
                'retentionRows' => [
                    0 => WordCumWorlds::task($world['tasks']['train'][0]['formula'], $seedBase + 31),
                    1 => WordCumWorlds::task($world['tasks']['train'][1]['formula'], $seedBase + 32),
                ],
            ];
            $results[] = $state;
            echo sprintf(
                "W%d rep%d C[%s]: maxchain=%d chains=%d improved=%d births=%d(shuf) dict=%d evals=%d final=%s\n",
                $wi,
                $rep,
                $mode,
                $state['rootChainMax'] === [] ? 0 : max($state['rootChainMax']),
                count(array_filter($state['rootChainMax'], static fn ($l): bool => $l >= 3)),
                $state['improved'],
                count($state['births']),
                count($state['dict']),
                $state['evals'],
                $state['final'] === null ? 'null' : round($state['final'], 4)
            );
        }
        if ($wi > 0) {
            foreach (runCLockstep('C', $world, $wi, 'restart', [], $conf, $lawKeys) as $state) {
                $results[] = $state;
                echo sprintf(
                    "W%d rep%d C[rst]: maxchain=%d improved=%d births=%d(shuf) evals=%d final=%s\n",
                    $wi,
                    $state['rep'],
                    $state['rootChainMax'] === [] ? 0 : max($state['rootChainMax']),
                    $state['improved'],
                    count($state['births']),
                    $state['evals'],
                    $state['final'] === null ? 'null' : round($state['final'], 4)
                );
            }
        }
    }
}
$wall = round((hrtime(true) - $t0) / 1e9, 1);
echo "wall={$wall}s\n";

$outFile = dirname(__DIR__, 2) . '/Benchmarks/word_cum001/' . ($isDev ? 'DEV.json' : 'checkpoint_raw.json');
file_put_contents($outFile, json_encode([
    'conf' => $conf,
    'wall_s' => $wall,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo 'written: ', $outFile, "\n";
