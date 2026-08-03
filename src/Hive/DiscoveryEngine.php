<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Validation\LawValidator;

/**
 * DiscoveryEngine — поиск законов в данных (§1.1-1.4).
 *
 * Извлечён из Hive::doDiscoverTick(). Phase 3: discover() с Search::find.
 * recordDiscovery и побочные эффекты остаются в Hive (Phase 6).
 */
class DiscoveryEngine
{
    /**
     * Поиск законов: Search::find (генеративный) + Heldout/AtomRegistry + Compose.
     *
     * @param array $X features
     * @param array $y targets
     * @param string[] $grammarOps операции грамматики (per-bee + BASE_OPS)
     * @param float $cvThreshold порог CV (калиброванный вызывающим)
     * @param array|null $colLabels метки колонок для Search::find
     * @return array<int, array{atom: string, cv: float, mode: string}>
     */
    public function discover(
        array $X,
        array $y,
        array $grammarOps,
        float $cvThreshold,
        ?array $colLabels = null,
        float $testRatio = 0.2,
    ): array {
        $nFeat = count($X[0] ?? []);
        $tMin = max(10, $nFeat * 5);
        if (count($y) < $tMin) {
            return [];
        }

        $found = [];

        // 1. Generative search (Search::find — основной путь)
        if (count($grammarOps) >= 2) {
            $searchGrammar = Grammar::fromOps($grammarOps);
            [$sFound, $sCv, $sFormula, $sCvTest, $sClass] = Search::find($X, $y, $searchGrammar, 2, $colLabels, $testRatio);
            if ($sFound) {
                $found[] = ['atom' => $sFormula, 'cv' => $sCv, 'cv_test' => $sCvTest, 'mode' => 'search', 'class' => $sClass];
            }
        }

        // 2. Held-out или raw discover
        if (AtomRegistry::isHeldoutEnabled()) {
            foreach (LawValidator::discoverHeldout($X, $y, cvTrainMax: $cvThreshold) as $d) {
                $found[] = $d;
            }
        } else {
            foreach (AtomRegistry::discover($X, $y) as $d) {
                if ($d['cv'] <= $cvThreshold) {
                    $found[] = $d;
                }
            }
        }

        // 3. Compose — используем переданные grammarOps
        if (count($grammarOps) >= 2) {
            foreach (AtomRegistry::discoverCompose($X, $y, $grammarOps, $cvThreshold) as $d) {
                $found[] = $d;
            }
        }

        // Normalize: ensure all results have 'class' field
        foreach ($found as &$d) {
            if (! isset($d['class'])) {
                $d['class'] = 'EMPIRICAL';
            }
        }

        return $found;
    }
}
