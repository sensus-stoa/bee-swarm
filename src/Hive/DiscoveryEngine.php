<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Validation\LawValidator;

/**
 * DiscoveryEngine — поиск законов в данных (§1.1-1.4).
 */
class DiscoveryEngine
{
    /**
     * @return array{0: list<array>, 1: float, 2: float} [candidates, bestCv, searchCv]
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
            return [[], 9.99, 9.99];
        }

        $found = [];
        $bestCv = 9.99;
        $searchCv = 9.99;  // S1.6-GRADIENT: only Search::find, not compose

        // 1. Generative search
        if (count($grammarOps) >= 2) {
            $searchGrammar = Grammar::fromOps($grammarOps);
            [$sFound, $sCv, $sFormula, $sCvTest, $sClass] = Search::find($X, $y, $searchGrammar, 2, $colLabels, $testRatio, $cvThreshold);
            $bestCv = min($bestCv, $sCv);
            $searchCv = $sCv;
            if ($sFound) {
                $found[] = ['atom' => $sFormula, 'cv' => $sCv, 'cv_test' => $sCvTest, 'mode' => 'search', 'class' => $sClass];
            }
        }

        // 2. Held-out / raw discover
        if (AtomRegistry::isHeldoutEnabled()) {
            foreach (LawValidator::discoverHeldout($X, $y, cvTrainMax: $cvThreshold) as $d) {
                $found[] = $d;
                $bestCv = min($bestCv, $d['cv'] ?? 9.99);
            }
        } else {
            foreach (AtomRegistry::discover($X, $y) as $d) {
                $bestCv = min($bestCv, $d['cv'] ?? 9.99);
                if ($d['cv'] <= $cvThreshold) {
                    $found[] = $d;
                }
            }
        }

        // 3. Compose (does NOT affect searchCv)
        if (count($grammarOps) >= 2) {
            foreach (AtomRegistry::discoverCompose($X, $y, $grammarOps, $cvThreshold) as $d) {
                $found[] = $d;
                $bestCv = min($bestCv, $d['cv'] ?? 9.99);
            }
        }

        foreach ($found as &$d) {
            if (! isset($d['class'])) {
                $d['class'] = 'EMPIRICAL';
            }
        }

        return [$found, $bestCv, $searchCv];
    }
}
