<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Core\ExpressionTree;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * EXP-045 Route Heredity — исполнитель маршрутов.
 *
 * Маршрут = последовательность вылетов Search::find с knob-наборами.
 * ЯДРО НЕ ТРОГАЕТСЯ: все ручки — env вокруг вызова, с полным восстановлением
 * после прогона (урок SEARCH_ESCALATED: env-флаг не должен переживать вызов).
 *
 * Изоляция (EXP-044):
 *  - route-local атомы (LOCAL_BIRTH) живут ТОЛЬКО в Grammar инстансе вылета
 *    (Grammar::addLocal — ноль записей в grammar_ops);
 *  - baseline-вылеты (вне исполнителя: driver.php baseline_flights) всегда
 *    без TRAIL_MARKS; route-канал всегда ПИШЕТ метки (v1.1 write-on,
 *    REPORT.md §3.1), USE_MARKS управляет только чтением (TRAIL_READ_K).
 *
 * Метрика прогресса L(f) = RMSE(f,y)/RMSE(mean_train,y) — PySR-style loss,
 * считается ВНЕШНЕ на внутреннем valid-хвосте (testRatio=0.2-срез движка,
 * строки [floor(0.8n), n)). Селекция маршрутов — только по L_valid.
 */
final class RouteExecutor
{
    /**
     * Внутренний valid-хвост = тот же сплит, что держит движок (testRatio=0.2).
     */
    private const TEST_RATIO = 0.2;

    /**
     * Env-ручки, которыми управляет исполнитель (снапшот+восстановление).
     */
    private const MANAGED_KNOBS = ['SEARCH_BEAM_K', 'L2_BEAM_K', 'TRAIL_MARKS', 'TRAIL_READ_K'];

    /**
     * Beam-пресеты = связки реальных env-ручек (см. RouteGenome::BEAM_PRESETS).
     */
    private const BEAM_KNOBS = [
        // Явный beam-off: пустая строка → (int)('' ?: '0') = 0 независимо от
        // ambient-env (phpunit.xml force-ставит SEARCH_BEAM_K=10 — пресет
        // обязан задавать СВОЮ семантику, а не наследовать окружение).
        'default' => [
            'SEARCH_BEAM_K' => '',
            'L2_BEAM_K' => '',
        ],
        'beam10' => [
            'SEARCH_BEAM_K' => '10',
            'L2_BEAM_K' => '40',
        ],
        'beam40' => [
            'SEARCH_BEAM_K' => '40',
            'L2_BEAM_K' => '80',
        ],
        'narrow5' => [
            'SEARCH_BEAM_K' => '5',
            'L2_BEAM_K' => '10',
        ],
    ];

    /**
     * Минимальный остаток канала, при котором ещё имеет смысл стартовать вылет.
     */
    private const MIN_FLIGHT_SEC = 1.0;

    /**
     * Условие активации. INHERIT обязан быть разрешён вызывающим (драйвером).
     *
     * @param array{flights_done:int, certified:bool} $baselineState
     */
    public static function shouldActivate(array $genome, array $baselineState): bool
    {
        $cond = $genome['condition'];
        if (($cond['kind'] ?? '') === 'ALWAYS') {
            return true;
        }
        if (($cond['kind'] ?? '') === 'NO_CERT_AFTER') {
            return ! $baselineState['certified']
                && $baselineState['flights_done'] >= (int) ($cond['flights'] ?? 1);
        }

        throw new \InvalidArgumentException('INHERIT must be resolved by the caller before run()');
    }

    /**
     * Исполнение маршрута в отведённом канале бюджета.
     *
     * @param list<list<float>> $X
     * @param list<float> $y
     * @param list<string>|null $labels
     * @param array<string,string> $localAtoms name => definition (ExpressionTree JSON)
     * @return array{activated:bool, records:list<array>, found:bool, cert_formula:?string,
     *               best_L_valid:?float, best_formula_x:?string, first_L_valid:?float,
     *               wall:float, unspent:float, local_atoms:array<string,string>, stop:string}
     */
    public static function run(
        array $genome,
        array $X,
        array $y,
        ?array $labels,
        float $channelBudgetSec,
        float $eps,
        array $localAtoms = [],
        int $marksMode = 1,
    ): array {
        $cond = $genome['condition'];
        if (($cond['kind'] ?? '') === 'INHERIT') {
            throw new \InvalidArgumentException('INHERIT must be resolved by the caller before run()');
        }

        $t0 = microtime(true);
        $n = count($y);
        $labels ??= array_map(fn (int $i): string => "x{$i}", range(0, count($X[0] ?? []) - 1));
        $records = [];
        $pendingMarksK = 0;
        $found = false;
        $certFormula = null;
        $bestL = null;
        $bestFormulaX = null;
        $firstL = null;
        $stop = 'actions_done';

        foreach (array_slice($genome['actions'], 0, max(1, (int) ($genome['max_steps'] ?? count($genome['actions'])))) as $seq => $action) {
            $remain = $channelBudgetSec - (microtime(true) - $t0);
            if ($remain < self::MIN_FLIGHT_SEC) {
                $stop = 'budget_exhausted';
                break;
            }
            $op = $action['op'];
            if ($op === 'USE_MARKS') {
                $pendingMarksK = (int) $action['k'];
                $records[] = [
                    'seq' => $seq,
                    'op' => 'USE_MARKS',
                    'knobs' => 'k=' . $pendingMarksK,
                    'found' => 0,
                    'cv_exposed' => null,
                    'l_train' => null,
                    'l_valid' => null,
                    'wall_s' => 0.0,
                ];
                continue;
            }
            if ($op === 'LOCAL_BIRTH') {
                $born = self::localBirth($bestFormulaX);
                $records[] = [
                    'seq' => $seq,
                    'op' => 'LOCAL_BIRTH',
                    'knobs' => $born !== null ? ('atom=' . $born['name']) : 'rejected',
                    'found' => 0,
                    'cv_exposed' => null,
                    'l_train' => null,
                    'l_valid' => null,
                    'wall_s' => 0.0,
                ];
                if ($born !== null) {
                    $localAtoms[$born['name']] = $born['definition'];
                }
                continue;
            }
            // FLIGHT
            $depth = max(1, min(3, (int) $action['depth']));
            $marksK = $pendingMarksK;
            $pendingMarksK = 0;
            $a0 = microtime(true);
            $r = self::flight(
                $X,
                $y,
                $labels,
                (array) RouteGenome::GRAMMAR_PRESETS[$action['grammar']],
                (string) $action['beam'],
                $depth,
                $marksK,
                $remain,
                $eps,
                $localAtoms,
                $marksMode
            );
            $wall = microtime(true) - $a0;
            [$foundFlight, $cvExposed, $formulaC, $formulaX, $trailFlight] = $r;
            // Route-local атомы живут только в Grammar-инстансе вылета: внешний
            // оценщик (ExpressionEvaluator) их не видит (ревью deleg_1cae7b13,
            // блокер-3). Разворачиваем (A atom B) → инфикс до любой оценки L.
            if ($formulaX !== null) {
                $formulaX = self::expandLocalAtoms($formulaX, $localAtoms);
            }
            $lValid = $formulaX !== null ? self::computeL($formulaX, self::tailX($X), self::tailY($y)) : null;
            $lTrain = $formulaX !== null ? self::computeL($formulaX, self::headX($X), self::headY($y)) : null;
            if ($foundFlight) {
                $found = true;
                $certFormula = $formulaC;
            }
            if ($lValid !== null) {
                $firstL ??= $lValid;
                if ($bestL === null || $lValid < $bestL) {
                    $bestL = $lValid;
                    $bestFormulaX = $formulaX;
                }
            }
            $records[] = [
                'seq' => $seq,
                'op' => 'FLIGHT',
                'knobs' => 'd=' . $depth . ';g=' . $action['grammar'] . ';b=' . $action['beam']
                    . ($marksK > 0 ? ';marks=' . $marksK : ''),
                'found' => $foundFlight ? 1 : 0,
                'cv_exposed' => $cvExposed,
                'l_train' => $lTrain,
                'l_valid' => $lValid,
                'wall_s' => round($wall, 3),
                // EXP-045 LOOP PROBE: событийная телеметрия trail-marks вылета
                'trail' => $trailFlight,
            ];
        }

        $wall = microtime(true) - $t0;

        return [
            'activated' => true,
            'records' => $records,
            'found' => $found,
            'cert_formula' => $certFormula,
            'best_L_valid' => $bestL,
            'best_formula_x' => $bestFormulaX,
            'first_L_valid' => $firstL,
            'wall' => $wall,
            'unspent' => max(0.0, $channelBudgetSec - $wall),
            'local_atoms' => $localAtoms,
            'stop' => $stop,
        ];
    }

    /**
     * ENV-ручки меток для вылета. v1.1 write-on (REPORT.md §3.1):
     * запись всегда on — route-канал накапливает дорогу независимо от
     * USE_MARKS (БД руки изолирована, task-start DELETE в драйвере);
     * USE_MARKS управляет ТОЛЬКО чтением через TRAIL_READ_K. '0' —
     * честный ноль чтения: Search::find различает '0' и unset
     * (unset = verify-канон EXP-040, default 5).
     *
     * @return array{TRAIL_MARKS:string, TRAIL_READ_K:string}
     */
    public static function marksKnobs(int $marksK, int $marksMode = 1): array
    {
        if ($marksMode === 0) {
            // EXP-045 LOOP PROBE (P1): негатив-контроль — ОБЕ стороны петли
            // выключены явно ('0': гейты Search ==='1' ложны — write и read
            // мертвы by construction; ambient-unset недостаточен, поскольку
            // ЭТОТ метод перезаписывает ambient).
            return [
                'TRAIL_MARKS' => '0',
                'TRAIL_READ_K' => '0',
            ];
        }

        return [
            'TRAIL_MARKS' => '1',
            'TRAIL_READ_K' => (string) max(0, $marksK),
        ];
    }

    /**
     * Один вылет Search::find c env-ручками. Возвращает
     * [found, cv_exposed|null, formula_c|label-space|null, formula_x|x-space|null].
     *
     * @param list<string> $presetOps
     * @param array<string,string> $localAtoms
     * @return array{0:bool,1:?float,2:?string,3:?string}
     */
    private static function flight(
        array $X,
        array $y,
        array $labels,
        array $presetOps,
        string $beam,
        int $depth,
        int $marksK,
        float $remainSec,
        float $eps,
        array $localAtoms,
        int $marksMode = 1,
    ): array {
        $snapshot = [];
        foreach (self::MANAGED_KNOBS as $k) {
            $v = getenv($k);
            $snapshot[$k] = $v === false ? null : $v;
        }
        try {
            foreach (self::BEAM_KNOBS[$beam] ?? [] as $k => $v) {
                putenv($k . '=' . $v);
            }
            // EXP-045 v1.1 write-on (REPORT.md §3.1, dev_run1 причина 1):
            // маршрут [FLIGHT, USE_MARKS, FLIGHT] не накапливал дорогу —
            // первый вылет не писал (TRAIL_MARKS снимался целиком), третий
            // читал пустую таблицу. Write в route-канале всегда on: БД руки
            // изолирована (S2), task-start DELETE в драйвере (driver.php:277).
            // USE_MARKS управляет ТОЛЬКО чтением — TRAIL_READ_K='0' читает
            // честный ноль (фикс fallback '0'?:'5' в Search::find).
            foreach (self::marksKnobs($marksK, $marksMode) as $k => $v) {
                putenv($k . '=' . $v);
            }
            $g = Grammar::fromOps($presetOps);
            foreach ($localAtoms as $name => $definition) {
                $g->addLocal($name, $definition);
            }
            $r = Search::find($X, $y, $g, $depth, $labels, self::TEST_RATIO, $eps, $remainSec);
            $found = (bool) $r[0];
            $cv = is_numeric($r[1]) ? (float) $r[1] : 9.99;
            // НЕ найдено: cv_train принудительно 9.99 (Search.php failure-путь),
            // НО r[2] несёт ЛУЧШУЮ plausible-форму — это и есть частичная
            // гипотеза. Экспозиция = факт реального имени, качество судит
            // computeL (ревью deleg_1cae7b13, блокер-2).
            $formulaC = is_string($r[2] ?? null) && $r[2] !== 'none' && $r[2] !== '' ? (string) $r[2] : null;
            $formulaX = $formulaC !== null ? self::labelsToX($formulaC, $labels) : null;

            return [$found, $found ? $cv : null, $formulaC, $formulaX, [
                // EXP-045 LOOP PROBE: событийная телеметрия этого find'а
                // (last-call семантика Search; сброс — в начале find).
                // ЧИСЛОВОЙ индекс 4 — контракт P5: деструктуризация в run()
                // позиционная; смешанный ключ 'trail' дал Undefined key 4
                // и null-телеметрию (поймано самопроверкой пробы, campaign-stop).
                'written' => Search::$lastTrailWritten,
                'writer_input' => Search::$lastTrailWriterInput,
                'read_fetched' => Search::$lastTrailReadFetched,
                'read_injected' => Search::$lastTrailReadInjected,
                'read_attempts' => Search::$lastTrailReadAttempts,
            ]];
        } finally {
            foreach ($snapshot as $k => $v) {
                if ($v === null) {
                    putenv($k);
                } else {
                    putenv($k . '=' . $v);
                }
            }
        }
    }

    /**
     * LOCAL_BIRTH: рождение route-local слова из лучшей частичной формы.
     * Гейты: ≤2 разных фич (бинарный контракт (a,b) изоморф-компрессора),
     * только базовые операции (никаких R/K-подгонок и чужих атомов — урок P8),
     * форма содержит хотя бы один оператор.
     *
     * @return array{name:string, definition:string}|null
     */
    public static function localBirth(?string $formulaX): ?array
    {
        if ($formulaX === null) {
            return null;
        }
        if (preg_match('/B[0-9a-f]{4,}|RL[0-9a-f]+/', $formulaX) === 1) {
            return null; // уже атом — повторное оборачивание запрещено
        }
        if (preg_match('/[RK]/', preg_replace('/\d+/', '', $formulaX)) === 1) {
            return null; // R/K null-подгонки (урок P8)
        }
        // Склеенные формы '(x0minx1)' дают ОДИН токен (ревью deleg_1cae7b13):
        // режем формулу на разрешённые фрагменты base-грамматики и требуем
        // пустой буквенный остаток. Длинные фрагменты первыми ('inverse' до
        // 'inv', 'sqrt' до 'sq').
        $rest = (string) preg_replace('/x\d+/', ' ', $formulaX);
        $rest = (string) preg_replace('/pow\d*/', ' ', $rest);
        $fragments = ['inverse', 'parity', 'sqrt', 'log2', 'abs', 'min', 'max', 'neg', 'inv', 'sq'];
        foreach ($fragments as $frag) {
            $rest = str_replace($frag, ' ', $rest);
        }
        if (preg_match('/[A-Za-z]/', $rest) === 1) {
            return null; // неизвестный буквенный токен = не base-грамматика
        }
        preg_match_all('/x(\d+)/', $formulaX, $fm);
        $feats = array_values(array_unique($fm[1]));
        if (count($feats) < 1 || count($feats) > 2) {
            return null; // бинарный контракт (a,b)
        }
        $map = [
            'x' . $feats[0] => 'a',
        ];
        if (count($feats) === 2) {
            $map['x' . $feats[1]] = 'b';
        }
        if (! str_contains($formulaX, '+') && ! str_contains($formulaX, '−')
            && ! str_contains($formulaX, '×') && ! str_contains($formulaX, '/')) {
            return null; // тождество без операции бессмысленно
        }
        // Парсим в x-пространстве: parse('a×b') не разбирает одиночные буквы
        // (возвращает atom-лист), x\d+ — разбирает корректно. Маппинг x→a/b
        // выполняется в toExprTree.
        $parsed = ExpressionNormalizer::parse($formulaX, []);
        if ($parsed === null) {
            return null;
        }
        $tree = self::toExprTree($parsed, $map);
        if ($tree === null || ! is_array($tree)) {
            return null;
        }
        $definition = json_encode($tree);
        if ($definition === false) {
            return null;
        }

        return [
            'name' => 'RL' . substr(sha1($definition), 0, 8),
            'definition' => $definition,
        ];
    }

    /**
     * Дерево ExpressionNormalizer {'op','l','r','atom'} → дерево ExpressionTree
     * {'op','left','right'|'arg'}. Листы-фичи маппятся x→a/b; числа проходят;
     * прочие токены (K-константы, чужие атомы) — reject.
     *
     * @param array<string,string> $map
     */
    private static function toExprTree(array|string|float|null $node, array $map = []): array|string|float|null
    {
        if ($node === null) {
            return null;
        }
        if (is_array($node)) {
            if (isset($node['atom']) && is_string($node['atom'])) {
                if (isset($map[$node['atom']])) {
                    return $map[$node['atom']];
                }
                if (is_numeric($node['atom'])) {
                    return (float) $node['atom'];
                }

                return null; // K-константы и чужие атомы не включаем
            }
            if (isset($node['op']) && is_string($node['op'])) {
                $op = $node['op'];
                if (in_array($op, ['sq', 'abs', 'sqrt'], true)) {
                    $arg = self::toExprTree($node['l'] ?? $node['arg'] ?? null, $map);
                    if ($arg === null) {
                        return null;
                    }

                    return [
                        'op' => $op,
                        'arg' => $arg,
                    ];
                }
                $left = self::toExprTree($node['l'] ?? null, $map);
                $right = self::toExprTree($node['r'] ?? null, $map);
                if ($left === null || $right === null) {
                    return null;
                }

                return [
                    'op' => $op,
                    'left' => $left,
                    'right' => $right,
                ];
            }

            return null;
        }
        if (is_string($node)) {
            if ($node === 'a' || $node === 'b') {
                return $node;
            }
            if (is_numeric($node)) {
                return (float) $node;
            }

            return null;
        }

        return null;
    }

    /**
     * Разворачивание route-local атомов до инфикса: "(A RLname B)" →
     * дерево определения с a→A, b→B. Нужен потому, что
     * ExpressionEvaluator::definition() резолвит атомы ТОЛЬКО из grammar_ops
     * (ревью deleg_1cae7b13, блокер-3) — route-local атомы вне БД.
     * Многократный проход покрывает формулы с несколькими атомами; вложенные
     * операнды обрабатываются скобочно-балансным сканом. Определения
     * RL-свободны (birth-гейт запрещает оборачивание атомов).
     *
     * @param array<string,string> $localAtoms name => ExpressionTree JSON
     */
    public static function expandLocalAtoms(string $formulaX, array $localAtoms): string
    {
        $out = $formulaX;
        for ($iter = 0; $iter < 8; $iter++) {
            $changed = false;
            foreach ($localAtoms as $name => $definition) {
                $pos = strpos($out, $name);
                if ($pos === false) {
                    continue;
                }
                // Открытие пары: идём влево от атома, первый несбалансированный '('
                $depth = 0;
                $open = -1;
                for ($i = $pos - 1; $i >= 0; $i--) {
                    $ch = $out[$i];
                    if ($ch === ')') {
                        $depth++;
                    } elseif ($ch === '(') {
                        if ($depth > 0) {
                            $depth--;
                        } else {
                            $open = $i;
                            break;
                        }
                    }
                }
                if ($open === -1) {
                    continue; // атом вне пары — нечего разворачивать
                }
                // Закрытие пары: идём вправо, первый несбалансированный ')'
                $depth = 0;
                $close = -1;
                $len = strlen($out);
                $end = $pos + strlen($name);
                for ($j = $end; $j < $len; $j++) {
                    $ch = $out[$j];
                    if ($ch === '(') {
                        $depth++;
                    } elseif ($ch === ')') {
                        if ($depth > 0) {
                            $depth--;
                        } else {
                            $close = $j;
                            break;
                        }
                    }
                }
                if ($close === -1) {
                    continue;
                }
                $a = trim(substr($out, $open + 1, $pos - ($open + 1)));
                $b = trim(substr($out, $end, $close - $end));
                $tree = json_decode($definition, true);
                if (! is_array($tree)) {
                    continue;
                }
                $expanded = self::renderTree($tree, $a, $b);
                if ($expanded === null) {
                    continue;
                }
                $out = substr($out, 0, $open) . $expanded . substr($out, $close + 1);
                $changed = true;
            }
            if (! $changed) {
                break;
            }
        }

        return $out;
    }

    /**
     * Рендер дерева (ExpressionTree-формат) в инфикс Search с a/b → операнды.
     */
    private static function renderTree(array|string|float|null $node, string $a, string $b): ?string
    {
        if (is_string($node)) {
            if ($node === 'a') {
                return $a;
            }
            if ($node === 'b') {
                return $b;
            }

            return is_numeric($node) ? $node : null;
        }
        if (is_int($node) || is_float($node)) {
            return (string) $node;
        }
        if (! is_array($node)) {
            return null;
        }
        $op = $node['op'] ?? '';
        if (in_array($op, ['sq', 'abs', 'sqrt'], true)) {
            $arg = self::renderTree($node['arg'] ?? null, $a, $b);
            if ($arg === null) {
                return null;
            }

            return '(' . $arg . $op . ')';
        }
        $l = self::renderTree($node['left'] ?? null, $a, $b);
        $r = self::renderTree($node['right'] ?? null, $a, $b);
        if ($l === null || $r === null) {
            return null;
        }

        return '(' . $l . $op . $r . ')';
    }

    /**
     * L(f) = RMSE(f,y)/RMSE(mean(y),y) на срезе. null = формула неоценима
     * (NaN/Inf/несовпадение длины) — считается «нет сигнала», не нулём.
     *
     * @param list<list<float>> $Xs
     * @param list<float> $ys
     */
    public static function computeL(string $formulaX, array $Xs, array $ys): ?float
    {
        if ($Xs === [] || $ys === []) {
            return null;
        }
        $vals = ExpressionEvaluator::evaluateFormula($formulaX, $Xs);
        if (! is_array($vals) || count($vals) !== count($ys)) {
            return null;
        }
        $seF = 0.0;
        foreach ($vals as $i => $v) {
            if (! is_numeric($v) || ! is_finite((float) $v)) {
                return null;
            }
            $d = (float) $v - $ys[$i];
            $seF += $d * $d;
        }
        $rmseF = sqrt($seF / count($ys));
        $mean = array_sum($ys) / count($ys);
        $seB = 0.0;
        foreach ($ys as $yv) {
            $d = $yv - $mean;
            $seB += $d * $d;
        }
        $rmseB = sqrt($seB / count($ys));
        if ($rmseB < 1e-12) {
            return null;
        }

        return $rmseF / $rmseB;
    }

    /**
     * @param list<float> $y @return list<float>
     */
    private static function tailY(array $y): array
    {
        $split = (int) floor(count($y) * (1.0 - self::TEST_RATIO));

        return array_slice($y, $split);
    }

    /**
     * @param list<float> $y @return list<float>
     */
    private static function headY(array $y): array
    {
        $split = (int) floor(count($y) * (1.0 - self::TEST_RATIO));

        return array_slice($y, 0, $split);
    }

    /**
     * @param list<list<float>> $X @return list<list<float>>
     */
    private static function tailX(array $X): array
    {
        $split = (int) floor(count($X) * (1.0 - self::TEST_RATIO));

        return array_slice($X, $split);
    }

    /**
     * @param list<list<float>> $X @return list<list<float>>
     */
    private static function headX(array $X): array
    {
        $split = (int) floor(count($X) * (1.0 - self::TEST_RATIO));

        return array_slice($X, 0, $split);
    }

    /** Формула в label-пространстве → x-пространство.
     * Порядок критичен: (1) RL-имена (sha1-гекс содержит 'c0'-подстроки)
     * прячутся плейсхолдерами; (2) метки заменяются ПРОСТОЙ заменой —
     * склеенные формы 'c0minc2' обязаны конвертироваться (boundary-lookaround
     * их ломал, ревью-фикс-регрессия); (3) имена возвращаются. */
    private static function labelsToX(string $formula, array $labels): string
    {
        $out = $formula;
        $kept = [];
        $out = (string) preg_replace_callback('/RL[0-9a-f]{8}/', static function (array $m) use (&$kept): string {
            $key = "\x00" . count($kept) . "\x00";
            $kept[$key] = $m[0];

            return $key;
        }, $out);
        $indexed = [];
        foreach ($labels as $i => $label) {
            if (is_string($label) && $label !== "x{$i}") {
                $indexed[] = [$label, "x{$i}"];
            }
        }
        usort($indexed, fn (array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));
        foreach ($indexed as [$label, $x]) {
            $out = str_replace($label, $x, $out);
        }

        return strtr($out, $kept);
    }
}
