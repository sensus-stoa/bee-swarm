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
 *  - baseline-вылеты всегда без TRAIL_MARKS; route-канал управляет своими.
 *
 * Метрика прогресса L(f) = RMSE(f,y)/RMSE(mean_train,y) — PySR-style loss,
 * считается ВНЕШНЕ на внутреннем valid-хвосте (testRatio=0.2-срез движка,
 * строки [floor(0.8n), n)). Селекция маршрутов — только по L_valid.
 */
final class RouteExecutor
{
    /** Внутренний valid-хвост = тот же сплит, что держит движок (testRatio=0.2). */
    private const TEST_RATIO = 0.2;

    /** Env-ручки, которыми управляет исполнитель (снапшот+восстановление). */
    private const MANAGED_KNOBS = ['SEARCH_BEAM_K', 'L2_BEAM_K', 'TRAIL_MARKS', 'TRAIL_READ_K'];

    /** Beam-пресеты = связки реальных env-ручек (см. RouteGenome::BEAM_PRESETS). */
    private const BEAM_KNOBS = [
        'default' => [], // beam off: pool-путь, raw-pair первыми (runtime-дефолт Search)
        'beam10' => ['SEARCH_BEAM_K' => '10', 'L2_BEAM_K' => '40'],
        'beam40' => ['SEARCH_BEAM_K' => '40', 'L2_BEAM_K' => '80'],
        'narrow5' => ['SEARCH_BEAM_K' => '5', 'L2_BEAM_K' => '10'],
    ];

    /** Минимальный остаток канала, при котором ещё имеет смысл стартовать вылет. */
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
    ): array {
        $cond = $genome['condition'];
        if (($cond['kind'] ?? '') === 'INHERIT') {
            throw new \InvalidArgumentException('INHERIT must be resolved by the caller before run()');
        }

        $t0 = microtime(true);
        $n = count($y);
        $labels = $labels ?? array_map(fn (int $i): string => "x{$i}", range(0, count($X[0] ?? []) - 1));
        $records = [];
        $pendingMarksK = 0;
        $found = false;
        $certFormula = null;
        $bestL = null;
        $bestFormulaX = null;
        $firstL = null;
        $stop = 'actions_done';

        foreach (array_slice($genome['actions'], 0, max(1, (int) $genome['max_steps'])) as $seq => $action) {
            $remain = $channelBudgetSec - (microtime(true) - $t0);
            if ($remain < self::MIN_FLIGHT_SEC) {
                $stop = 'budget_exhausted';
                break;
            }
            $op = $action['op'];
            if ($op === 'USE_MARKS') {
                $pendingMarksK = (int) $action['k'];
                $records[] = ['seq' => $seq, 'op' => 'USE_MARKS', 'knobs' => 'k=' . $pendingMarksK,
                    'found' => 0, 'cv_exposed' => null, 'l_train' => null, 'l_valid' => null, 'wall_s' => 0.0];
                continue;
            }
            if ($op === 'LOCAL_BIRTH') {
                $born = self::localBirth($bestFormulaX);
                $records[] = ['seq' => $seq, 'op' => 'LOCAL_BIRTH',
                    'knobs' => $born !== null ? ('atom=' . $born['name']) : 'rejected',
                    'found' => 0, 'cv_exposed' => null, 'l_train' => null, 'l_valid' => null, 'wall_s' => 0.0];
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
            $r = self::flight($X, $y, $labels, (array) RouteGenome::GRAMMAR_PRESETS[$action['grammar']],
                (string) $action['beam'], $depth, $marksK, $remain, $eps, $localAtoms);
            $wall = microtime(true) - $a0;
            [$foundFlight, $cvExposed, $formulaC, $formulaX] = $r;
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
            $records[] = ['seq' => $seq, 'op' => 'FLIGHT',
                'knobs' => 'd=' . $depth . ';g=' . $action['grammar'] . ';b=' . $action['beam']
                    . ($marksK > 0 ? ';marks=' . $marksK : ''),
                'found' => $foundFlight ? 1 : 0, 'cv_exposed' => $cvExposed,
                'l_train' => $lTrain, 'l_valid' => $lValid, 'wall_s' => round($wall, 3)];
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
            if ($marksK > 0) {
                putenv('TRAIL_MARKS=1');
                putenv('TRAIL_READ_K=' . $marksK);
            } else {
                putenv('TRAIL_MARKS'); // baseline-гигиена: чтение меток выключено
            }
            $g = Grammar::fromOps($presetOps);
            foreach ($localAtoms as $name => $definition) {
                $g->addLocal($name, $definition);
            }
            $r = Search::find($X, $y, $g, $depth, $labels, self::TEST_RATIO, $eps, $remainSec);
            $found = (bool) $r[0];
            $cv = is_numeric($r[1]) ? (float) $r[1] : 9.99;
            $formulaC = is_string($r[2] ?? null) && $r[2] !== 'none' ? (string) $r[2] : null;
            // Экспозиция частичной гипотезы = тот же гейт, что у движка
            // (Search возвращает лучшую plausible-форму только при cv < eps).
            if (! $found && ($cv >= $eps || $formulaC === null)) {
                $formulaC = null;
            }
            $formulaX = $formulaC !== null ? self::labelsToX($formulaC, $labels) : null;

            return [$found, $found ? $cv : ($formulaC !== null ? $cv : null), $formulaC, $formulaX];
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
        if (preg_match_all('/[A-Za-z][A-Za-z0-9]*/', $formulaX, $m) === 1) {
            foreach ($m[0] as $tok) {
                if (preg_match('/^x\d+$/', $tok) === 1) {
                    continue;
                }
                if (in_array($tok, ['min', 'max', 'sq'], true)) {
                    continue;
                }

                return null; // неизвестный токен = не base-грамматика
            }
        }
        preg_match_all('/x(\d+)/', $formulaX, $fm);
        $feats = array_values(array_unique($fm[1]));
        if (count($feats) < 1 || count($feats) > 2) {
            return null; // бинарный контракт (a,b)
        }
        $map = ['x' . $feats[0] => 'a'];
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

        return ['name' => 'RL' . substr(sha1($definition), 0, 8), 'definition' => $definition];
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

                    return ['op' => $op, 'arg' => $arg];
                }
                $left = self::toExprTree($node['l'] ?? null, $map);
                $right = self::toExprTree($node['r'] ?? null, $map);
                if ($left === null || $right === null) {
                    return null;
                }

                return ['op' => $op, 'left' => $left, 'right' => $right];
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

    /** @param list<float> $y @return list<float> */
    private static function tailY(array $y): array
    {
        $split = (int) floor(count($y) * (1.0 - self::TEST_RATIO));

        return array_slice($y, $split);
    }

    /** @param list<float> $y @return list<float> */
    private static function headY(array $y): array
    {
        $split = (int) floor(count($y) * (1.0 - self::TEST_RATIO));

        return array_slice($y, 0, $split);
    }

    /** @param list<list<float>> $X @return list<list<float>> */
    private static function tailX(array $X): array
    {
        $split = (int) floor(count($X) * (1.0 - self::TEST_RATIO));

        return array_slice($X, $split);
    }

    /** @param list<list<float>> $X @return list<list<float>> */
    private static function headX(array $X): array
    {
        $split = (int) floor(count($X) * (1.0 - self::TEST_RATIO));

        return array_slice($X, 0, $split);
    }

    /** Формула в label-пространстве → x-пространство (длинные метки первыми). */
    private static function labelsToX(string $formula, array $labels): string
    {
        $out = $formula;
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

        return $out;
    }
}
