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
 */
class GrammarMutator
{
    /**
     * @param string[] $grammar current grammar
     * @param string[] $available all possible operations
     * @return string[] mutated grammar
     */
    public static function mutate(array $grammar, array $available): array
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
                $grammar[] = $missing[array_rand($missing)];
                break;
            case 'remove':
                $idx = array_rand($grammar);
                array_splice($grammar, $idx, 1);
                break;
            case 'replace':
                $idx = array_rand($grammar);
                $grammar[$idx] = $missing[array_rand($missing)];
                break;
        }

        return array_values($grammar);
    }
}
