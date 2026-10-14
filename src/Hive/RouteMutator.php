<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * EXP-045: наследуемые мутации маршрутов.
 *
 * Ровно 5 типов (спека §Мутации): replace / swap / condition / budget / shorten.
 * Никакой свободной генерации действий: новое действие выбирается из того же
 * словаря RouteGenome. Мутация создаёт НОВЫЙ геном (parent_id → старый id),
 * статистика record обнуляется, lineage переносится.
 */
final class RouteMutator
{
    public const TYPE_REPLACE = 0;
    public const TYPE_SWAP = 1;
    public const TYPE_CONDITION = 2;
    public const TYPE_BUDGET = 3;
    public const TYPE_SHORTEN = 4;

    /** Штраф сложности маршрута в score (Ledger). */
    public const COMPLEXITY_PENALTY = 0.05;

    /**
     * @return array<string,mixed>|null null = маршрут вымер (shorten одиночного действия)
     */
    public static function mutate(array $genome, int $type, int $tick = 0): ?array
    {
        $child = $genome;
        $child['id'] = self::newId();
        $child['parent_id'] = $genome['id'];
        $child['generation'] = ($genome['generation'] ?? 0) + 1;
        $child['record'] = [
            'tasks_seen' => 0,
            'delta_L_valid_sum' => 0.0,
            'wins' => 0,
            'fails' => 0,
            'wall_sum' => 0.0,
            'unspent_quota' => 0.0,
        ];

        switch ($type) {
            case self::TYPE_REPLACE:
                $idx = array_rand($child['actions']);
                $child['actions'][$idx] = self::randomAction();
                $child['actions'] = array_values($child['actions']);
                break;

            case self::TYPE_SWAP:
                if (count($child['actions']) < 2) {
                    return self::mutate($genome, self::TYPE_REPLACE, $tick);
                }
                $keys = array_keys($child['actions']);
                [$i, $j] = array_slice($keys, 0, 2);
                if (count($keys) > 2) {
                    $i = $keys[array_rand($keys)];
                    do {
                        $j = $keys[array_rand($keys)];
                    } while ($j === $i);
                }
                $tmp = $child['actions'][$i];
                $child['actions'][$i] = $child['actions'][$j];
                $child['actions'][$j] = $tmp;
                break;

            case self::TYPE_CONDITION:
                $child['condition'] = self::randomCondition();
                break;

            case self::TYPE_BUDGET:
                $delta = (mt_rand() / mt_getrandmax()) * 0.10 - 0.05;
                $bf = (float) $genome['budget_fraction'] + $delta;
                $child['budget_fraction'] = round(max(RouteGenome::BUDGET_MIN, min(RouteGenome::BUDGET_MAX, $bf)), 4);
                break;

            case self::TYPE_SHORTEN:
                if (count($child['actions']) <= 1) {
                    return null; // маршрут вымер
                }
                array_pop($child['actions']);
                break;

            default:
                throw new \InvalidArgumentException("unknown mutation type {$type}");
        }

        return $child;
    }

    /** @return array<string,mixed> */
    public static function randomAction(): array
    {
        $op = RouteGenome::ACTION_OPS[array_rand(RouteGenome::ACTION_OPS)];
        if ($op === 'FLIGHT') {
            $grammar = array_rand(RouteGenome::GRAMMAR_PRESETS);
            return [
                'op' => 'FLIGHT',
                'depth' => mt_rand(1, 3),
                'grammar' => $grammar,
                'beam' => RouteGenome::BEAM_PRESETS[array_rand(RouteGenome::BEAM_PRESETS)],
            ];
        }
        if ($op === 'USE_MARKS') {
            return ['op' => 'USE_MARKS', 'k' => mt_rand(1, 10)];
        }

        return ['op' => 'LOCAL_BIRTH'];
    }

    /** @return array{kind:string,flights?:int} */
    public static function randomCondition(): array
    {
        if (mt_rand(0, 1) === 0) {
            return ['kind' => 'ALWAYS'];
        }

        return ['kind' => 'NO_CERT_AFTER', 'flights' => mt_rand(1, 5)];
    }

    /** Случайный стартовый геном (рука B RANDOM и посев популяции C). */
    public static function randomGenome(int $tick = 0, string $taskFp = ''): array
    {
        $n = mt_rand(1, 3);
        $actions = [];
        for ($i = 0; $i < $n; $i++) {
            $actions[] = self::randomAction();
        }
        $bf = 0.05 + (mt_rand() / mt_getrandmax()) * (RouteGenome::BUDGET_MAX - RouteGenome::BUDGET_MIN);

        return RouteGenome::create(
            self::newId(),
            '',
            0,
            self::randomCondition(),
            $actions,
            round($bf, 4),
            count($actions),
            $taskFp,
            $tick,
        );
    }

    private static int $idCounter = 0;

    public static function newId(): string
    {
        // Детерминизм EXP-045 S6: тот же mt_srand-сид → та же популяция.
        // random_bytes() здесь ЗАПРЕЩЁН (ломает повторяемость прогонов).
        // Счётчик устраняет коллизии внутри процесса.
        self::$idCounter++;

        return 'route_' . substr(sha1(mt_rand() . ':' . self::$idCounter), 0, 12);
    }
}
