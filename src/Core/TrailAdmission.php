<?php
declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * EXP-045 ROAD V4 (19.10, спека WU_ROAD_V4_SPEC.md §2/§4): классификатор
 * кандидатов admission дорожных меток. Самодостаточен (только
 * ExpressionNormalizer/ExpressionEvaluator); Search вызывает classify() для
 * EVAL-класса + admission-proxy и classOf() для knowledge-класса слотов.
 *
 * EVAL-классы смерти кандидата (спека §4 — смерть с причинами, не blanket):
 *  - LABEL_UNRESOLVABLE: конверсия дала неразрешимые атомы → АЛАРМ (bug signal
 *    label-space, счётчик отдельно);
 *  - PARSE_FAIL: нормализатор не разобрал;
 *  - EVAL_NULL: оценка null (неизвестный атом / домен: div-в-ноль, sqrt<0);
 *  - NON_FINITE: Inf/NaN в векторе (переполнение);
 *  - CONSTANT: stddev < 1e-6;
 *  - DEPTH_EXCEEDED: COUNT '+' узлов AST > DEPTH_PLUS_MAX.
 *
 * Граница EVAL_NULL/NON_FINITE: evaluateFormula() гасит весь вектор на
 * первом null/Inf ряде (returns null wholesale), поэтому классификация идёт
 * per-row через публичный evalNode: null-элемент = EVAL_NULL (домен),
 * Inf/NaN = NON_FINITE (физика).
 */
final class TrailAdmission
{
    public const DEPTH_PLUS_MAX = 3;

    public const CLASS_STAIR = 'stair';

    public const CLASS_PETAL = 'petal';

    public const CLASS_UNARY_BINARY = 'unary_binary';

    public const CLASS_OTHER = 'other';

    public const EVAL_OK = 'OK';

    public const EVAL_LABEL_UNRESOLVABLE = 'LABEL_UNRESOLVABLE';

    public const EVAL_PARSE_FAIL = 'PARSE_FAIL';

    public const EVAL_NULL = 'EVAL_NULL';

    public const EVAL_NON_FINITE = 'NON_FINITE';

    public const EVAL_CONSTANT = 'CONSTANT';

    public const EVAL_DEPTH_EXCEEDED = 'DEPTH_EXCEEDED';

    /**
     * Карта label→xN в конвенции testCv: longest-first (префиксные коллизии
     * 'c0'/'cc0') — тот же порядок, что в read-блоке Search.
     *
     * @param list<string>|null $colLabels
     * @return array<string, string>
     */
    public static function labelsToX(?array $colLabels): array
    {
        $map = [];
        if ($colLabels !== null) {
            foreach ($colLabels as $ci => $cl) {
                if (is_string($cl) && $cl !== '') {
                    $map[$cl] = 'x' . $ci;
                }
            }
            uksort($map, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        }

        return $map;
    }

    /**
     * EVAL-классификация кандидата + admission-proxy (|Pearson(values, y)|,
     * спека §2: прокси допускает ошибки — их чинит валюта выживания, не admission).
     *
     * @param array<string, string> $labelsToX
     * @param list<list<float>> $X
     * @param list<float> $y
     * @return array{eval: string, proxy: float, xform: string}
     */
    public static function classify(string $form, array $labelsToX, array $X, array $y): array
    {
        $head = self::parseCandidate($form, $labelsToX);
        if ($head['eval'] !== null) {
            return self::deadResult($head['eval'], $head['xform']);
        }
        $ev = self::evalVector($head['node'], $X, count($y));
        if ($ev['eval'] !== self::EVAL_OK) {
            return self::deadResult($ev['eval'], $head['xform']);
        }

        return self::okResult($ev['vec'], $y, $head['xform']);
    }

    /**
     * Быстрый путь admission: вектор кандидата уже посчитан поиском ($exprs).
     * Семантика классов идентична classify(); пере-оценки на X нет.
     *
     * @param array<string, string> $labelsToX
     * @param list<float|null> $vec
     * @param list<float> $y
     * @return array{eval: string, proxy: float, xform: string}
     */
    public static function classifyWithVec(string $form, array $labelsToX, array $vec, array $y): array
    {
        $head = self::parseCandidate($form, $labelsToX);
        if ($head['eval'] !== null) {
            return self::deadResult($head['eval'], $head['xform']);
        }
        $cls = self::vecClass($vec, count($y));
        if ($cls !== self::EVAL_OK) {
            return self::deadResult($cls, $head['xform']);
        }

        return self::okResult(array_map('floatval', $vec), $y, $head['xform']);
    }

    /**
     * Knowledge-класс формы по AST (слоты ёмкости, спека §3).
     */
    public static function classOf(string $xForm): string
    {
        $node = ExpressionNormalizer::parse($xForm);
        if ($node === null) {
            return self::CLASS_OTHER;
        }
        $ops = self::scanOps($node);
        if ($ops['plus'] && $ops['times']) {
            return self::CLASS_STAIR;
        }
        if ($ops['rootUnary'] && ($ops['plus'] || $ops['times'])) {
            return self::CLASS_UNARY_BINARY;
        }
        if ($ops['times']) {
            return self::CLASS_PETAL;
        }

        return self::CLASS_OTHER;
    }

    /**
     * COUNT '+' узлов AST (гейт глубины; 4-членная лестница = 3 — на границе).
     */
    public static function countPlusNodes(string $xForm): int
    {
        $node = ExpressionNormalizer::parse($xForm);
        if ($node === null) {
            return 0;
        }

        return self::countPlusNodesInNode($node);
    }

    /**
     * |Pearson(a, b)|; вырожденная дисперсия → 0.0.
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function pearson(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $ma = array_sum(array_slice($a, 0, $n)) / $n;
        $mb = array_sum(array_slice($b, 0, $n)) / $n;
        $cov = 0.0;
        $v1 = 0.0;
        $v2 = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $d1 = $a[$i] - $ma;
            $d2 = $b[$i] - $mb;
            $cov += $d1 * $d2;
            $v1 += $d1 * $d1;
            $v2 += $d2 * $d2;
        }
        if ($v1 <= 1e-12 || $v2 <= 1e-12) {
            return 0.0;
        }

        return abs($cov / sqrt($v1 * $v2));
    }

    /**
     * Участие метки в законе: подстрока формы С ГРАНИЦАМИ ТОКАНА (ревью
     * deleg_231f9459: голый str_contains подтверждал '(x1×x3)' законом
     * с x12 — x1 префикс x12).
     */
    public static function markInLaw(string $lawFormula, string $form): bool
    {
        if ($form === '' || $lawFormula === '') {
            return false;
        }

        return preg_match('/(?<![A-Za-z0-9])' . preg_quote($form, '/') . '(?![0-9])/', $lawFormula) === 1;
    }

    /** Голова classify*: конверсия + parse + листья + depth.
     *
     * @param array<string, string> $labelsToX
     * @return array{eval: string|null, xform: string, node: array<string, mixed>|null}
     */
    private static function parseCandidate(string $form, array $labelsToX): array
    {
        $xform = self::convertForm($form, $labelsToX);
        $node = ExpressionNormalizer::parse($xform);
        if ($node === null) {
            return self::headDead(self::EVAL_PARSE_FAIL, $xform);
        }
        if (! self::leavesResolvable($node)) {
            return self::headDead(self::EVAL_LABEL_UNRESOLVABLE, $xform);
        }
        if (self::countPlusNodesInNode($node) > self::DEPTH_PLUS_MAX) {
            return self::headDead(self::EVAL_DEPTH_EXCEEDED, $xform);
        }

        return self::headLive($xform, $node);
    }

    /**
     * @return array{eval: string, xform: string, node: null}
     */
    private static function headDead(string $eval, string $xform): array
    {
        return [
            'eval' => $eval,
            'xform' => $xform,
            'node' => null,
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @return array{eval: null, xform: string, node: array<string, mixed>}
     */
    private static function headLive(string $xform, array $node): array
    {
        return [
            'eval' => null,
            'xform' => $xform,
            'node' => $node,
        ];
    }

    /**
     * Хвост OK-кандидата: CONSTANT-гейт + admission-proxy.
     *
     * @param list<float> $vec
     * @param list<float> $y
     * @return array{eval: string, proxy: float, xform: string}
     */
    private static function okResult(array $vec, array $y, string $xform): array
    {
        if (self::stddev($vec) < 1e-6) {
            return self::deadResult(self::EVAL_CONSTANT, $xform);
        }

        return [
            'eval' => self::EVAL_OK,
            'proxy' => self::pearson($vec, $y),
            'xform' => $xform,
        ];
    }

    /**
     * @return array{eval: string, proxy: float, xform: string}
     */
    private static function deadResult(string $eval, string $xform): array
    {
        return [
            'eval' => $eval,
            'proxy' => 0.0,
            'xform' => $xform,
        ];
    }

    /**
     * Конверсия label→x (longest-first). Неразрешимые токены ловит
     * leavesResolvable() по AST-листьям (строчный residual-скан невозможен:
     * identity-метки 'x0'→'x0' легальны и не утечка).
     *
     * @param array<string, string> $labelsToX
     */
    private static function convertForm(string $form, array $labelsToX): string
    {
        $xform = $form;
        foreach ($labelsToX as $cl => $xv) {
            $xform = str_replace($cl, $xv, $xform);
        }

        return $xform;
    }

    /**
     * Per-row оценка дерева; класс вектора решает vecClass (null=домен,
     * Inf/NaN=физика). Успех → вектор конечных float.
     *
     * @param array<string, mixed> $node
     * @param list<list<float>> $X
     * @return array{eval: string, vec: list<float>}
     */
    private static function evalVector(array $node, array $X, int $nY): array
    {
        if (count($X) !== $nY || $nY === 0) {
            return [
                'eval' => self::EVAL_NULL,
                'vec' => [],
            ];
        }
        $stats = ExpressionEvaluator::collectStats(ExpressionNormalizer::render($node), $X);
        $vec = [];
        foreach ($X as $row) {
            $vec[] = ExpressionEvaluator::evalNode($node, $row, $stats);
        }
        $cls = self::vecClass($vec, $nY);
        if ($cls !== self::EVAL_OK) {
            return [
                'eval' => $cls,
                'vec' => [],
            ];
        }

        return [
            'eval' => self::EVAL_OK,
            'vec' => array_map('floatval', $vec),
        ];
    }

    /**
     * Класс вектора значений: null-элемент = EVAL_NULL (домен: неизвестный
     * атом, div-в-ноль, sqrt<0), Inf/NaN = NON_FINITE (переполнение).
     *
     * @param list<float|null> $vec
     */
    private static function vecClass(array $vec, int $nY): string
    {
        if (count($vec) !== $nY || $nY === 0) {
            return self::EVAL_NULL;
        }
        foreach ($vec as $v) {
            if ($v === null) {
                return self::EVAL_NULL;
            }
            if (! is_finite($v)) {
                return self::EVAL_NON_FINITE;
            }
        }

        return self::EVAL_OK;
    }

    /**
     * @param list<float> $vec
     */
    private static function stddev(array $vec): float
    {
        $n = count($vec);
        if ($n === 0) {
            return 0.0;
        }
        $mean = array_sum($vec) / $n;
        $var = 0.0;
        foreach ($vec as $v) {
            $var += ($v - $mean) * ($v - $mean);
        }

        return sqrt($var / $n);
    }

    /**
     * Все листья дерева разрешимы в x-пространстве: позиционные xN, K/R-
     * константы, birth-атомы (B/BW), числа. Чужой буквенный атом (утечка
     * label-space / мусор) — неразрешимый → аларм LABEL_UNRESOLVABLE.
     *
     * @param array<string, mixed> $node
     */
    private static function leavesResolvable(array $node): bool
    {
        if (isset($node['atom'])) {
            return self::atomResolvable((string) $node['atom']);
        }
        foreach (['l', 'r'] as $side) {
            if (isset($node[$side]) && is_array($node[$side]) && ! self::leavesResolvable($node[$side])) {
                return false;
            }
        }

        return true;
    }

    private static function atomResolvable(string $atom): bool
    {
        if (is_numeric($atom)) {
            return true;
        }

        return preg_match('/^(x\d+(?:²)?|K\d+(\.\d+)?|[RK].*|BW[0-9a-f]+|B\d+)$/', $atom) === 1;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function countPlusNodesInNode(array $node): int
    {
        $count = (($node['op'] ?? null) === '+') ? 1 : 0;
        foreach (['l', 'r'] as $side) {
            if (isset($node[$side]) && is_array($node[$side])) {
                $count += self::countPlusNodesInNode($node[$side]);
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{plus: bool, times: bool, rootUnary: bool}
     */
    private static function scanOps(array $node): array
    {
        $plus = false;
        $times = false;
        $walk = function (array $n) use (&$walk, &$plus, &$times): void {
            $op = $n['op'] ?? null;
            if ($op === '+') {
                $plus = true;
            }
            if ($op === '×') {
                $times = true;
            }
            foreach (['l', 'r'] as $side) {
                if (isset($n[$side]) && is_array($n[$side])) {
                    $walk($n[$side]);
                }
            }
        };
        $walk($node);
        $isBinaryRoot = in_array($node['op'] ?? null, ['+', '−', '×', '/', 'max', 'min'], true);

        return [
            'plus' => $plus,
            'times' => $times,
            'rootUnary' => isset($node['op']) && ! $isBinaryRoot,
        ];
    }
}
