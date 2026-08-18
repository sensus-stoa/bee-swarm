<?php
declare(strict_types=1);

namespace BeeSwarm\Trading;

/**
 * LiveExecutor — чистые функции боевого исполнителя (тестируемое ядро).
 * Без I/O: сигналы, приоритет плеча, объём, трейлинг, решения о выходе.
 */
class LiveExecutor
{
    /** r-атом: среднее последних n значений */
    public static function rAtom(array $ret, int $n): float
    {
        $n = max(1, min($n, count($ret)));
        return array_sum(array_slice($ret, -$n, $n)) / $n;
    }

    /** сигнал ветки: все условия AND по r-атомам; неизвестный атом → false */
    public static function branchSignal(array $branch, array $ret): bool
    {
        foreach (($branch['conds'] ?? []) as $c) {
            $v = match ($c['atom']) {
                'r2' => self::rAtom($ret, 2),
                'r5' => self::rAtom($ret, 5),
                'r10' => self::rAtom($ret, 10),
                'r20' => self::rAtom($ret, 20),
                'r40' => self::rAtom($ret, 40),
                default => null,
            };
            if ($v === null) {
                return false; // консервативно: не входим без полного сигнала
            }
            $csig = ($c['op'] === '>' && $v >= $c['threshold'])
                || ($c['op'] === '<' && $v <= $c['threshold']);
            if (! $csig) {
                return false;
            }
        }
        return true;
    }

    /** порядок индексов стратегий по max-lev в геноме (DESC) */
    public static function maxLevOrder(array $portfolio): array
    {
        $maxLev = [];
        foreach ($portfolio as $si => $s) {
            $ml = 1;
            foreach (($s['genome']['branches'] ?? []) as $br) {
                $ml = max($ml, (int) ($br['lev'] ?? 1));
            }
            $maxLev[$si] = $ml;
        }
        $order = array_keys($maxLev);
        usort($order, fn ($a, $b) => $maxLev[$b] <=> $maxLev[$a]);
        return $order;
    }

    /** округление объёма по volScale, не ниже минимума */
    public static function roundVol(float $vol, int $scale, float $minVol): float
    {
        $r = round($vol, $scale);
        return max($r, $minVol);
    }

    /**
     * MEXC side-код закрытия позиции:
     * 1=open long, 2=close SHORT, 3=open short, 4=close LONG.
     * БАГ 18.08: путали (2/4 наоборот) — позиция не закрывалась (2009).
     */
    public static function closeSide(int $positionSide): int
    {
        return $positionSide > 0 ? 4 : 2;
    }

    /** трейлинг-параметры: closeSide (2=шорт-закрытие, 4=лонг-закрытие) + backValue в долях */
    public static function trailParams(array $branch): ?array
    {
        $trail = (float) ($branch['trail'] ?? 0);
        if ($trail <= 0) {
            return null;
        }
        return [
            'closeSide' => ($branch['side'] ?? 1) > 0 ? 4 : 2,
            'backValue' => $trail,
        ];
    }

    /**
     * Решение о выходе: 'трейлинг X' | 'стоп +Y' | 'hold истёк' | null.
     * Для шорта пик = минимум цены; откат вверх от пика на trail → выход.
     */
    public static function exitDecision(array $pos, float $price, int $now): ?string
    {
        if ($price <= 0) {
            return null;
        }
        $trail = (float) ($pos['trail'] ?? 0);
        if ($trail > 0) {
            $peak = $pos['peak'] ?? $price;
            if (($pos['side'] ?? 1) > 0) {
                // лонг: пик = максимум; откат вниз
                $peak = max($peak, $price);
                if ($price <= $peak * (1 - $trail)) {
                    return 'трейлинг ' . $trail;
                }
            } else {
                // шорт: пик = минимум; откат вверх
                $peak = min($peak, $price);
                if ($price >= $peak * (1 + $trail)) {
                    return 'трейлинг ' . $trail;
                }
            }
        }
        $entry = (float) ($pos['entry'] ?? 0);
        if ($entry > 0) {
            $adverse = ($pos['side'] ?? 1) > 0
                ? ($entry - $price) / $entry
                : ($price - $entry) / $entry;
            if ($adverse >= 0.05) {
                return 'стоп +0.05';
            }
        }
        if (($pos['close_after'] ?? 0) <= $now) {
            return 'hold истёк';
        }
        return null;
    }
}
