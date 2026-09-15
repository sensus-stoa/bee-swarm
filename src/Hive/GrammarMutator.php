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
     * @param float $p доля культурного выбора (0.0 = uniform, 1.0 = weights)
     * @param array<string,float>|null $preferred S1.6-GRADIENT: op ⇒ множитель
     *        веса для ops последней signal-формы при add/replace (null = слепая).
     *        Предпочтение НЕ гарантирует выбор (иначе эксплорация вырождается).
     * @return string[] mutated grammar
     */
    public static function mutate(array $grammar, array $available, ?array $weights = null, float $p = 1.0, ?array $preferred = null): array
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
                $grammar[] = self::pickOp($missing, $weights, $p, $preferred);
                break;
            case 'remove':
                $idx = array_rand($grammar);
                array_splice($grammar, $idx, 1);
                break;
            case 'replace':
                $idx = array_rand($grammar);
                $grammar[$idx] = self::pickOp($missing, $weights, $p, $preferred);
                break;
        }

        return array_values($grammar);
    }

    private static function pickOp(array $ops, ?array $weights, float $p = 1.0, ?array $preferred = null): string
    {
        $weights = self::applySignalBoost($ops, $weights, $preferred);

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

    /**
     * S1.6-GRADIENT: preferred — карта op ⇒ множитель веса. Применяется
     * только при реальном пересечении с $ops — иначе исходное распределение
     * (null = uniform, культурные веса) неискажённое.
     *
     * @param string[] $ops
     * @param array<string,float>|null $weights
     * @param array<string,float>|null $preferred op ⇒ множитель
     * @return array<string,float>|null
     */
    private static function applySignalBoost(array $ops, ?array $weights, ?array $preferred): ?array
    {
        if ($preferred === null || $preferred === []) {
            return $weights;
        }
        // Premortem H3: $weights=null означает «пропагация выключена» —
        // uniform-ветка pickOp обязана сохраниться. Boost применяем ТОЛЬКО
        // к ненулевым культурным весам, null не материализуем.
        if ($weights === null) {
            return null;
        }
        $boosted = $weights;
        $applied = false;
        foreach ($ops as $op) {
            if (isset($preferred[$op])) {
                $boosted[$op] = ($weights[$op] ?? 1.0) * $preferred[$op];
                $applied = true;
            }
        }

        return $applied ? $boosted : $weights;
    }
}
