<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Validation\LawValidator;

/**
 * ComposeEngine — compose-поиск законов через пары grammar-операций.
 *
 * Извлечён из Hive::doComposeTick(). Phase 4: атомарный compose().
 */
class ComposeEngine
{
    /**
     * Поиск законов через compose пар grammar-операций.
     *
     * @param array $X features
     * @param array $y targets
     * @param string[] $grammarOps операции грамматики
     * @param float $cvThreshold порог CV
     * @return array<int, array{atom: string, cv: float, mode: string}>
     */
    public function compose(array $X, array $y, array $grammarOps, float $cvThreshold = 0.01): array
    {
        $nFeat = count($X[0] ?? []);
        $tMin = max(10, $nFeat * 5);
        if (count($y) < $tMin) {
            return [];
        }

        if (count($grammarOps) < 2) {
            return [];
        }

        $candidates = AtomRegistry::discoverCompose($X, $y, $grammarOps, $cvThreshold);
        if (empty($candidates)) {
            return [];
        }

        return LawValidator::validate($candidates, $X, $y);
    }
}
