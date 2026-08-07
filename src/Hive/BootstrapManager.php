<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\Grammar;

/**
 * BootstrapManager — создание seed-популяции (§0.6).
 *
 * Извлечён из Hive::bootstrap(). Создаёт 3 seed-пчелы с G₁=B, G₂=mutate(B), G₃=mutate(mutate(B)).
 */
class BootstrapManager
{
    /**
     * Создать 3 seed-пчелы с попарно различными грамматиками.
     *
     * @return Bee[]
     *
     * @throws \RuntimeException если не удалось создать различающиеся грамматики
     */
    public function createSeedBees(): array
    {
        $allOps = array_keys(Grammar::BASE_OPS);
        $semOps = Grammar::SEMANTIC_OPS;
        $available = array_merge($allOps, $semOps);

        // G₁ = baseline grammar B
        $g1 = $allOps;

        // G₂ = mutate(B) — retry until Jaccard < 1.0 with G₁
        $g2 = $g1;
        for ($retry = 0; $retry < 10; $retry++) {
            $g2 = GrammarMutator::mutate($allOps, $available);
            if ($this->jaccard($g1, $g2) < 1.0) {
                break;
            }
        }
        if ($this->jaccard($g1, $g2) >= 1.0) {
            throw new \RuntimeException('BOOTSTRAP: G₂ identical to G₁ after 10 retries');
        }

        // G₃ = mutate(mutate(B)) — retry until distinct from both G₁ and G₂
        $g3 = $g1;
        for ($retry = 0; $retry < 20; $retry++) {
            $g3 = GrammarMutator::mutate($g2, $available);
            if ($this->jaccard($g1, $g3) < 1.0 && $this->jaccard($g2, $g3) < 0.95) {
                break;
            }
        }
        if ($this->jaccard($g1, $g3) >= 0.95 || $this->jaccard($g2, $g3) >= 0.95) {
            throw new \RuntimeException('BOOTSTRAP: G₃ not sufficiently distinct after 20 retries');
        }

        return [
            // ЭКСП-017: SEED_ENERGY env — голодный тест (низкая энергия = быстрая смерть)
            new Bee($g1, (float) (getenv('SEED_ENERGY') ?: '10.0')),
            new Bee($g2, (float) (getenv('SEED_ENERGY') ?: '10.0')),
            new Bee($g3, (float) (getenv('SEED_ENERGY') ?: '10.0')),
        ];
    }

    private function jaccard(array $a, array $b): float
    {
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        return $union > 0 ? $intersection / $union : 0.0;
    }
}
