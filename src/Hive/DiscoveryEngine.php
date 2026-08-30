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
     * @return array{0: list<array>, 1: float, 2: float, 3: string|null} [candidates, bestCv, searchCv, diagnosis]
     */
    public function discover(
        array $X,
        array $y,
        array $grammarOps,
        float $cvThreshold,
        ?array $colLabels = null,
        float $testRatio = 0.2,
        ?int $depth = null,
        ?int $tMin = null,
    ): array {
        $nFeat = count($X[0] ?? []);
        $tMin = max(10, $nFeat * 5);
        if (count($y) < $tMin) {
            // §3.3: DATA-диагноз из маршрутизации (pre-filter §1.2)
            return [[], 9.99, 9.99, 'DATA'];
        }

        $found = [];
        $bestCv = 9.99;
        $searchCv = 9.99;  // S1.6-GRADIENT: only Search::find, not compose

        // 1. Generative search
        if (count($grammarOps) >= 2) {
            $searchGrammar = Grammar::fromOps($grammarOps);
            // DISCOVERY-DEPTH (09.08): глубина — параметр (инъекция ?? env ??
            // 2), НЕ хардкод. ADAPTIVE-DEPTH: default 2 (быстрые тики),
            // эскалация до SEARCH_DEPTH_MAX при пустом результате.
            $depth ??= max(1, (int) (getenv('SEARCH_DEPTH') ?: '2'));
            $maxDepth = max($depth, (int) (getenv('SEARCH_DEPTH_MAX') ?: '4'));
            $depth = min($depth, $maxDepth); // кламп: depth > MAX → пустой цикл (CONCERNS)
            $sFound = false;
            $sCv = 9.99;
            $sFormula = null;
            $sCvTest = 9.99;
            $sClass = 'EMPIRICAL';
            $lastDiagnosis = null;
            for ($d = $depth; $d <= $maxDepth; $d++) {
                [$sFound, $sCv, $sFormula, $sCvTest, $sClass, $sDiagnosis] = Search::find($X, $y, $searchGrammar, $d, $colLabels, $testRatio, $cvThreshold);
                $lastDiagnosis = $sDiagnosis;
                // CONCERNS deleg_ceef5093: «нашёл хоть что-то» — слабый критерий.
                // Тень (простой атом, CV=0) не должна останавливать эскалацию:
                // break ТОЛЬКО при составном законе с хорошим CV.
                $isShadow = $sFormula !== null && ! preg_match('/[+×−\/(]/', $sFormula);
                if ($sFound && $sCv <= 0.05 && ! $isShadow) {
                    break;
                }
            }
            $bestCv = min($bestCv, $sCv);
            $searchCv = $sCv;
            if ($sFound) {
                $found[] = [
                    'atom' => $sFormula,
                    'cv' => $sCv,
                    'cv_test' => $sCvTest,
                    'mode' => 'search',
                    'class' => $sClass,
                ];
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

        return [$found, $bestCv, $searchCv, $lastDiagnosis ?? null];
    }
}
