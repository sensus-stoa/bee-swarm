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

    /** Именованные подмножества BASE_OPS (Grammar::baseOpNames). */
    public const GRAMMAR_PRESETS = [
        'base' => ['+', '×', '−', '/', 'min', 'max', 'sq'],
        'diffprod' => ['−', '×', '+'],
        'ratio' => ['×', '/'],
    ];

    /** Связки реальных env-ручек Search (см. RouteExecutor::BEAM_KNOBS). */
    public const BEAM_PRESETS = ['default', 'beam10', 'beam40', 'narrow5'];

    public const CONDITION_KINDS = ['ALWAYS', 'NO_CERT_AFTER', 'INHERIT'];

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
        if (! is_array($g['condition']) || ! isset($g['condition']['kind'])
            || ! in_array($g['condition']['kind'], self::CONDITION_KINDS, true)) {
            $errors[] = 'bad condition.kind';
        } else {
            $kind = $g['condition']['kind'];
            if ($kind === 'NO_CERT_AFTER') {
                $k = $g['condition']['flights'] ?? null;
                if (! is_int($k) || $k < 1 || $k > 5) {
                    $errors[] = 'condition.flights must be int 1..5';
                }
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
        if (isset($g['max_steps']) && (! is_int($g['max_steps']) || $g['max_steps'] < 1 || $g['max_steps'] > self::MAX_ACTIONS)) {
            $errors[] = 'max_steps must be int 1..' . self::MAX_ACTIONS;
        }

        return $errors;
    }

    /**
     * @param mixed $a
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
            'lineage' => ['born_task_fp' => $bornTaskFp, 'born_tick' => $bornTick],
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
