<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * EXP-045 Route Heredity — геном исследовательского маршрута.
 *
 * Маршрут = программа из управляемых поисковых действий над существующими
 * примитивами Search (FLIGHT/USE_MARKS/LOCAL_BIRTH). Наследуется СПОСОБ
 * исследования, никогда — операторы или формулы (урок EXP-044: инъекция
 * словаря в глобальную грамматику подавляет поиск 0/36).
 *
 * Геном НЕ содержит формул, имён колонок и конкретных атомов: грамматика
 * действия — именованный класс (пресет-подмножество BASE_OPS).
 */
final class RouteGenome
{
    public const ACTION_OPS = ['FLIGHT', 'USE_MARKS', 'LOCAL_BIRTH'];

    /**
     * Именованные подмножества BASE_OPS (Grammar::baseOpNames).
     */
    public const GRAMMAR_PRESETS = [
        'base' => ['+', '×', '−', '/', 'min', 'max', 'sq'],
        'diffprod' => ['−', '×', '+'],
        'ratio' => ['×', '/'],
    ];

    /**
     * Связки реальных env-ручек Search (см. RouteExecutor::BEAM_KNOBS).
     */
    public const BEAM_PRESETS = ['default', 'beam10', 'beam40', 'narrow5', 'beam10nl'];

    public const CONDITION_KINDS = ['ALWAYS', 'NO_CERT_AFTER', 'INHERIT'];

    /** STRATEGY-TRANSFER rev.5: dynamic regulator prefix (state_t = a*state_{t-1} + (1-a)*yield_t). */
    public const CONDITION_INTEGRATOR_PREFIX = 'CLASS_INTEGRATOR:';

    /** STRATEGY-C2 (owner 04.11): (i) arm — class gate WITHOUT the theta barrier. */
    public const CONDITION_CLASSMATCH_PREFIX = 'CLASS_MATCH:';

    public const INTEGRATOR_ALPHA_MIN = 0.05;
    public const INTEGRATOR_ALPHA_MAX = 0.95;
    public const INTEGRATOR_N_MIN = 1;

    public const BUDGET_MIN = 0.05;

    public const BUDGET_MAX = 0.30;

    public const MAX_ACTIONS = 6;

    /**
     * @param array<string,mixed> $g
     * @return list<string> список ошибок схемы; [] = валиден
     */
    public static function validate(array $g): array
    {
        $errors = [];
        foreach (['id', 'actions', 'condition', 'budget_fraction'] as $key) {
            if (! array_key_exists($key, $g)) {
                $errors[] = "missing key: {$key}";
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        if (! is_string($g['id']) || preg_match('/^route_[0-9a-f]{4,}$/', $g['id']) !== 1) {
            $errors[] = 'bad id format';
        }
        if (isset($g['parent_id']) && $g['parent_id'] !== ''
            && (! is_string($g['parent_id']) || preg_match('/^route_[0-9a-f]{4,}$/', $g['parent_id']) !== 1)) {
            $errors[] = 'bad parent_id format';
        }
        $kindIsIntegrator = is_array($g['condition'] ?? null)
            && isset($g['condition']['kind'])
            && is_string($g['condition']['kind'])
            && (str_starts_with($g['condition']['kind'], self::CONDITION_INTEGRATOR_PREFIX)
                || str_starts_with($g['condition']['kind'], self::CONDITION_CLASSMATCH_PREFIX));
        if (! is_array($g['condition']) || ! isset($g['condition']['kind'])
            || (! in_array($g['condition']['kind'], self::CONDITION_KINDS, true)
                && ! $kindIsIntegrator)) {
            $errors[] = 'bad condition.kind';
        } elseif ($kindIsIntegrator) {
            $kind = $g['condition']['kind'];
            if (! $kindIsIntegrator && $kind === 'NO_CERT_AFTER') {
                $k = $g['condition']['flights'] ?? null;
                if (! is_int($k) || $k < 1 || $k > 5) {
                    $errors[] = 'condition.flights must be int 1..5';
                }
            }
        }
        if ($kindIsIntegrator) {
            $kindStr = (string) $g['condition']['kind'];
            $isInt = str_starts_with($kindStr, self::CONDITION_INTEGRATOR_PREFIX);
            $cls = $isInt
                ? substr($kindStr, strlen(self::CONDITION_INTEGRATOR_PREFIX))
                : substr($kindStr, strlen(self::CONDITION_CLASSMATCH_PREFIX));
            if ($cls === '' || ! preg_match('/^[A-Z]+$/', $cls)) {
                $errors[] = $isInt ? 'CLASS_INTEGRATOR class must be [A-Z]+' : 'CLASS_MATCH class must be [A-Z]+';
            }
            if (str_starts_with($g['condition']['kind'], self::CONDITION_INTEGRATOR_PREFIX)) {
                foreach (['alpha', 'theta', 'n_min'] as $req) {
                    if (! isset($g['condition'][$req]) || ! is_numeric($g['condition'][$req])) {
                        $errors[] = "CLASS_INTEGRATOR requires numeric {$req}";
                    }
                }
            } // CLASS_MATCH: n_min optional (default 1), no alpha/theta
            if (isset($g['condition']['alpha']) && is_numeric($g['condition']['alpha'])
                && ($g['condition']['alpha'] < self::INTEGRATOR_ALPHA_MIN
                    || $g['condition']['alpha'] > self::INTEGRATOR_ALPHA_MAX)) {
                $errors[] = 'alpha out of range';
            }
            if (isset($g['condition']['n_min']) && is_numeric($g['condition']['n_min'])
                && $g['condition']['n_min'] < self::INTEGRATOR_N_MIN) {
                $errors[] = 'n_min too small';
            }
        }
        $actions = $g['actions'];
        if (! is_array($actions) || $actions === [] || count($actions) > self::MAX_ACTIONS) {
            $errors[] = 'actions must be non-empty array of ≤' . self::MAX_ACTIONS;
        } else {
            foreach ($actions as $i => $a) {
                $errors = array_merge($errors, self::validateAction($a, (string) $i));
            }
        }
        $bf = $g['budget_fraction'];
        if (! is_int($bf) && ! is_float($bf)) {
            $errors[] = 'budget_fraction must be numeric';
        } elseif ((float) $bf < self::BUDGET_MIN || (float) $bf > self::BUDGET_MAX) {
            $errors[] = 'budget_fraction out of [' . self::BUDGET_MIN . ',' . self::BUDGET_MAX . ']';
        }
        if (! isset($g['max_steps']) || ! is_int($g['max_steps']) || $g['max_steps'] < 1 || $g['max_steps'] > self::MAX_ACTIONS) {
            // Обязателен (ревью deleg_1cae7b13): отсутствие молча исполняло
            // только первое действие (run() default = 1).
            $errors[] = 'max_steps must be int 1..' . self::MAX_ACTIONS;
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function validateAction(mixed $a, string $idx): array
    {
        if (! is_array($a) || ! isset($a['op']) || ! in_array($a['op'], self::ACTION_OPS, true)) {
            return ["actions[{$idx}]: unknown op"];
        }
        $op = $a['op'];
        if ($op === 'FLIGHT') {
            $depth = $a['depth'] ?? null;
            if (! is_int($depth) || $depth < 1 || $depth > 3) {
                return ["actions[{$idx}]: FLIGHT.depth must be int 1..3"];
            }
            if (! isset($a['grammar']) || ! array_key_exists($a['grammar'], self::GRAMMAR_PRESETS)) {
                return ["actions[{$idx}]: FLIGHT.grammar unknown preset"];
            }
            if (! isset($a['beam']) || ! in_array($a['beam'], self::BEAM_PRESETS, true)) {
                return ["actions[{$idx}]: FLIGHT.beam unknown preset"];
            }
        }
        if ($op === 'USE_MARKS') {
            $k = $a['k'] ?? null;
            if (! is_int($k) || $k < 1 || $k > 10) {
                return ["actions[{$idx}]: USE_MARKS.k must be int 1..10"];
            }
        }

        return [];
    }

    /**
     * Каркас нового генома с нулевой статистикой.
     *
     * @param list<array<string,mixed>> $actions
     * @return array<string,mixed>
     */
    public static function create(
        string $id,
        string $parentId,
        int $generation,
        array $condition,
        array $actions,
        float $budgetFraction,
        int $maxSteps,
        string $bornTaskFp = '',
        int $bornTick = 0,
    ): array {
        return [
            'id' => $id,
            'parent_id' => $parentId,
            'generation' => $generation,
            'condition' => $condition,
            'actions' => $actions,
            'budget_fraction' => $budgetFraction,
            'max_steps' => $maxSteps,
            'lineage' => [
                'born_task_fp' => $bornTaskFp,
                'born_tick' => $bornTick,
            ],
            'record' => [
                'tasks_seen' => 0,
                'delta_L_valid_sum' => 0.0,
                'wins' => 0,
                'fails' => 0,
                'wall_sum' => 0.0,
                'unspent_quota' => 0.0,
            ],
        ];
    }
}
