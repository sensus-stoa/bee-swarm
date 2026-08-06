<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * GrammarMutator — random mutation of grammar operations (Protocol §2.2).
 *
 * Three equiprobable mutations:
 * - ADD: one random available op not in grammar
 * - REMOVE: one random op from grammar (if |G| > 2)
 * - REPLACE: swap one op for another
 *
 * GRAMMAR-PROPAGATION (ЭКСП-012): $weights[op] для weightedPick —
 * культурная эволюция (успех оператора → вес → чаще мутируют в него).
 */
class GrammarMutator
{
    /**
     * @param string[] $grammar current grammar
     * @param string[] $available all possible operations
     * @param array<string,float>|null $weights op → weight (null = uniform)
     * @return string[] mutated grammar
     */
    /**
     * @param array<string,float>|null $weights op → weight
     * @param float $p доля культурного выбора (0.0 = uniform, 1.0 = weights)
     */
    public static function mutate(array $grammar, array $available, ?array $weights = null, float $p = 1.0): array
    {
        $grammar = array_values($grammar);
        $available = array_values(array_unique($available));
        $missing = array_values(array_diff($available, $grammar));

        $choices = [];
        if (! empty($missing)) {
            $choices[] = 'add';
        }
        if (count($grammar) > 2) {
            $choices[] = 'remove';
        }
        if (! empty($missing)) {
            $choices[] = 'replace';
        }

        if (empty($choices)) {
            return $grammar;
        }

        $action = $choices[array_rand($choices)];

        switch ($action) {
            case 'add':
                $grammar[] = self::pickOp($missing, $weights, $p);
                break;
            case 'remove':
                $idx = array_rand($grammar);
                array_splice($grammar, $idx, 1);
                break;
            case 'replace':
                $idx = array_rand($grammar);
                $grammar[$idx] = self::pickOp($missing, $weights, $p);
                break;
        }

        return array_values($grammar);
    }

    private static function pickOp(array $ops, ?array $weights, float $p = 1.0): string
    {
        // ЭКСП-016: с вероятностью (1-p) — uniform (exploration)
        if ($weights === null || mt_rand(0, 1000000) / 1000000.0 >= $p) {
            return $ops[array_rand($ops)];
        }
        $total = 0.0;
        foreach ($ops as $op) {
            $total += $weights[$op] ?? 1.0;
        }
        $roll = mt_rand(0, 1000000) / 1000000.0 * $total;
        foreach ($ops as $op) {
            $roll -= $weights[$op] ?? 1.0;
            if ($roll <= 0) {
                return $op;
            }
        }
        return $ops[array_rand($ops)];
    }
}
