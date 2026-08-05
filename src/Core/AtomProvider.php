<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * AtomProvider — открытие атомов из данных.
 * Вынесено из AtomRegistry (SOLID S).
 */
class AtomProvider
{
    /**
     * Применить атом к строке данных. null если атом не подходит.
     */
    private static function applyToRow(string $atom, int $nFeat, array $row): ?float
    {
        // Guard: пустые ряды → null, без Warning (millions in logs, ноутбук 05.08)
        if (! isset($row[0])) {
            return null;
        }
        if (AtomRegistry::isBinary($atom) && $nFeat >= 2) {
            return AtomRegistry::apply($atom, (float) $row[0], (float) $row[1]);
        }
        if (AtomRegistry::isUnary($atom)) {
            return AtomRegistry::apply($atom, (float) $row[0]);
        }
        return null;
    }

    /**
     * Перебирает ВСЕ атомы, возвращает те что дают CV=0 на данных.
     * @return array [{atom, cv, mode}, ...]
     */
    public static function discover(array $X, array $y): array
    {
        $found = [];
        $nFeat = count($X[0] ?? []);
        $n = count($y);

        foreach (AtomRegistry::all() as $atom) {
            // Skip trivial atoms (HONEST_CRITERIA §1.4)
            if (AtomRegistry::isTrivial($atom, $X, $y)) {
                continue;
            }

            $vec = [];
            $valid = true;

            foreach ($X as $row) {
                $v = self::applyToRow($atom, $nFeat, $row);
                if ($v === null || is_nan($v) || is_infinite($v)) {
                    $valid = false;
                    break;
                }
                $vec[] = $v;
            }

            if (! $valid || count($vec) !== $n) {
                continue;
            }

            $cv = AtomRegistry::cv($vec, $y);
            if ($cv < 0.001) {
                $found[] = [
                    'atom' => $atom,
                    'cv' => $cv,
                    'mode' => AtomRegistry::isBinary($atom) ? 'binary' : 'unary',
                ];
            }
        }

        return $found;
    }

    /**
     * Перебирает все пары grammar-атомов, возвращает compose с CV=0.
     */
    public static function discoverCompose(array $X, array $y, array $grammar, ?float $cvThreshold = null): array
    {
        $found = [];
        $nFeat = count($X[0] ?? []);
        $n = count($y);
        $threshold = $cvThreshold ?? 0.001;
        $bestCv = $threshold;  // early exit: abort pairs worse than threshold

        foreach ($grammar as $outer) {
            foreach ($grammar as $inner) {
                if ($outer === $inner) {
                    continue;
                }
                if (! AtomRegistry::isUnary($outer) && ! AtomRegistry::isBinary($outer)) {
                    continue;
                }
                if (! AtomRegistry::isUnary($inner) && ! AtomRegistry::isBinary($inner)) {
                    continue;
                }

                $vec = [];
                $valid = true;
                $checkInterval = max(10, (int)($n / 5));  // check every 20% of rows

                foreach ($X as $i => $row) {
                    $v1 = self::applyToRow($inner, $nFeat, $row);
                    if ($v1 === null || is_nan($v1) || is_infinite($v1)) {
                        $valid = false;
                        break;
                    }

                    if (AtomRegistry::isBinary($outer) && $nFeat >= 3) {
                        $v2 = AtomRegistry::apply($outer, $v1, (float) $row[2]);
                    } elseif (AtomRegistry::isBinary($outer) && $nFeat >= 2) {
                        $v2 = AtomRegistry::apply($outer, $v1, (float) $row[1]);
                    } elseif (AtomRegistry::isUnary($outer)) {
                        $v2 = AtomRegistry::apply($outer, $v1);
                    } else {
                        $valid = false;
                        break;
                    }
                    if ($v2 === null || is_nan($v2) || is_infinite($v2)) {
                        $valid = false;
                        break;
                    }
                    $vec[] = $v2;

                    // Early exit: estimate partial CV, abort if clearly above threshold
                    if ($cvThreshold !== null && $i > 0 && $i % $checkInterval === 0 && count($vec) >= 3) {
                        $partialCv = AtomRegistry::cv($vec, array_slice($y, 0, count($vec)));
                        if ($partialCv > $threshold * 5) {  // 5x margin for partial estimate
                            $valid = false;
                            break;
                        }
                    }
                }

                if (! $valid || count($vec) !== $n) {
                    continue;
                }

                $cv = AtomRegistry::cv($vec, $y);
                $composeAtom = "{$outer}({$inner})";
                if ($cv <= $threshold && ! AtomRegistry::isTrivial($composeAtom, $X, $y)) {
                    $found[] = [
                        'atom' => $composeAtom,
                        'cv' => $cv,
                        'mode' => 'compose',
                    ];
                    if ($cv < $bestCv) {
                        $bestCv = $cv;
                    }
                }
            }
        }

        return $found;
    }
}
