<?php
declare(strict_types=1);

namespace BeeSwarm\Trading;

/**
 * FIN-005 TRADING-BEES (14.08, v4): пчёлы торгуют, без законов.
 * ЭНЕРГИЯ-МЕХАНИКА: пчела имеет капитал (energy). PnL прибавляется,
 * energy <= 0 → СМЕРТЬ (gambler's ruin). Размножаются только НАКОПИВШИЕ
 * (energy > стартовой). На шуме — гарантированное вымирание (издержки
 * дают отрицательный дрейф); на эффекте — накопление и потомство.
 */
final class TradingHive
{
    public const START_ENERGY = 1.0;
    public const COST = 0.002;    // 0.2% за цикл (вход+выход)

    /** @var list<array{genome: array, energy: float, alive: bool}> */
    private array $pop = [];
    private int $popSize;

    public function __construct(int $popSize = 100)
    {
        $this->popSize = $popSize;
    }

    /**
     * @param list<list<float>> $windows — ряды ret1: [train..., oos...]
     * @return array{survivors: list<array{genome: array, energy: float, oos_pnl: float}>, total_energy: float}
     */
    public function evolve(array $windows, int $generations): array
    {
        $this->pop = [];
        for ($i = 0; $i < $this->popSize; $i++) {
            $this->pop[] = [
                'genome' => self::randomGenome(),
                'energy' => self::START_ENERGY,
                'alive' => true,
            ];
        }
        $lastIdx = count($windows) - 1;

        for ($g = 0; $g < $generations; $g++) {
            $window = $windows[$lastIdx]; // OOS: всегда НЕ-обучающее окно
            foreach ($this->pop as &$bee) {
                if (! $bee['alive']) {
                    continue;
                }
                $pnl = self::trade($bee['genome'], $window);
                $bee['energy'] += $pnl;
                if ($bee['energy'] <= 0.0) {
                    $bee['alive'] = false;
                }
            }
            unset($bee);

            // родители = живые НАКОПИВШИЕ (energy > стартовой)
            $parents = [];
            foreach ($this->pop as $bee) {
                if ($bee['alive'] && $bee['energy'] > self::START_ENERGY) {
                    $parents[] = $bee;
                }
            }
            $newPop = [];
            if ($parents !== []) {
                $perParent = (int) ceil($this->popSize / count($parents));
                for ($i = 0; $i < $this->popSize; $i++) {
                    $p = $parents[$i % count($parents)];
                    $newPop[] = [
                        'genome' => self::mutate($p['genome']),
                        // ДЕЛЕЖ энергии: потомок получает ДОЛЮ родителя
                        // (энергия не создаётся из ничего — сумма сохраняется)
                        'energy' => $p['energy'] / $perParent,
                        'alive' => true,
                    ];
                }
            }
            // вымершие линии не размножаются; популяция схлопывается
            $this->pop = $newPop;
        }

        $out = [];
        $total = 0.0;
        foreach ($this->pop as $bee) {
            if ($bee['alive']) {
                $out[] = [
                    'genome' => $bee['genome'],
                    'energy' => $bee['energy'],
                    'oos_pnl' => $bee['energy'] - self::START_ENERGY,
                ];
                $total += $bee['energy'];
            }
        }
        return ['survivors' => $out, 'total_energy' => $total];
    }

    /** @return array{atom:string,threshold:float,op:string,side:int,hold:int,lots:int} */
    private static function randomGenome(): array
    {
        $atoms = ['r5', 'r20', 'vol'];
        return [
            'atom' => $atoms[array_rand($atoms)],
            'threshold' => (rand() / getrandmax()) * 0.06,
            'op' => rand(0, 1) === 1 ? '>' : '<',
            'side' => rand(0, 1) === 1 ? 1 : -1,
            'hold' => [5, 10, 20, 40][array_rand([5, 10, 20, 40])],
            'lots' => [1, 2][array_rand([1, 2])],
        ];
    }

    /** @param array $g геном */
    private static function mutate(array $g): array
    {
        switch (rand(0, 4)) {
            case 0:
                $g['threshold'] = max(0.0, $g['threshold'] + (rand() / getrandmax() - 0.5) * 0.02);
                break;
            case 1:
                $g['op'] = $g['op'] === '>' ? '<' : '>';
                break;
            case 2:
                $g['side'] = -$g['side'];
                break;
            case 3:
                $g['hold'] = [5, 10, 20, 40][array_rand([5, 10, 20, 40])];
                break;
            default:
                $g['lots'] = $g['lots'] === 1 ? 2 : 1;
        }
        return $g;
    }

    /** Торговля пчелы по ряду ret1: итоговый PnL с издержками */
    private static function trade(array $g, array $ret): float
    {
        $n = count($ret);
        $pnl = 0.0;
        $inPos = 0;
        $side = 0;
        for ($i = 5; $i < $n; $i++) {
            if ($inPos > 0) {
                $pnl += $side * $ret[$i] * $g['lots'];
                $inPos--;
                if ($inPos === 0) {
                    $pnl -= self::COST * $g['lots'];
                }
                continue;
            }
            // признаки по ПРОШЛЫМ дням (лаг — без текущего дня)
            $feat = match ($g['atom']) {
                'r5' => array_sum(array_slice($ret, $i - 5, 5)) / 5,
                'r20' => array_sum(array_slice($ret, $i - 20, 20)) / 20,
                default => self::vol20($ret, $i),
            };
            $sig = ($g['op'] === '>' && $feat >= $g['threshold'])
                || ($g['op'] === '<' && $feat <= $g['threshold']);
            if ($sig) {
                $side = $g['side'];
                $inPos = $g['hold'];
                $pnl -= self::COST * $g['lots'];
            }
        }
        return $pnl;
    }

    private static function vol20(array $ret, int $i): float
    {
        $seg = array_slice($ret, $i - 20, 20);
        $m = array_sum($seg) / 20;
        $v = 0.0;
        foreach ($seg as $x) {
            $v += ($x - $m) ** 2;
        }
        return sqrt($v / 20);
    }
}
