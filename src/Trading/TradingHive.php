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
        for ($g = 0; $g < $generations; $g++) {
            // АДАПТАЦИЯ: окна ЧЕРЕДУЮТСЯ — пчела должна выживать на СМЕНЕ
            // режимов (одно окно вечно эксплуатировать нельзя)
            $window = $windows[$g % count($windows)];
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
                // элитизм: лучшие родители — копируются без мутаций
                usort($parents, fn ($a, $b) => $b['energy'] <=> $a['energy']);
                $perParent = (int) ceil($this->popSize / count($parents));
                for ($i = 0; $i < $this->popSize; $i++) {
                    $p = $parents[$i % count($parents)];
                    $isElite = $i < max(1, (int) ($this->popSize * 0.1)) && $i < count($parents);
                    // ЭЛИТА мутирует СЛАБО (p=0.1 — удержание без заморозки шума);
                    // прибыльные — умеренно (0.3), убыточные — сильно (0.7, поиск)
                    $mp = $isElite ? 0.1 : ($p['energy'] > self::START_ENERGY ? 0.3 : 0.7);
                    $newPop[] = [
                        'genome' => self::mutate($p['genome'], $mp),
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
                // ЧИСТЫЙ PnL: переторговка генома с нуля на ВСЕХ окнах
                // (не наследство!) — честная «прибыль за всё время»
                $clean = 0.0;
                foreach ($windows as $w) {
                    $clean += self::trade($bee['genome'], $w);
                }
                $out[] = [
                    'genome' => $bee['genome'],
                    'energy' => $bee['energy'],
                    'oos_pnl' => $bee['energy'] - self::START_ENERGY,
                    'clean_pnl' => $clean,
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
            'hold' => [2, 3, 5, 10, 20][array_rand([2, 3, 5, 10, 20])],
            'lots' => [1, 2][array_rand([1, 2])],
        ];
    }

    /** @param array $g геном — мутируют ВСЕ параметры (v5), сила по p */
    private static function mutate(array $g, float $p): array
    {
        // порог: ОТНОСИТЕЛЬНЫЙ шаг (мелкий у малых порогов — точная подгонка
        // под слабые эффекты; + редкий крупный прыжок для исследования)
        if (rand(0, 99) < (int) ($p * 100)) {
            $step = $g['threshold'] * 0.3 + 0.0005;
            if (rand(0, 9) === 0) {
                $step = $g['threshold'] * 1.5 + 0.005; // крупный прыжок
            }
            $g['threshold'] = max(0.0, $g['threshold'] + (rand(0, 1) === 1 ? 1 : -1) * $step);
        }
        // оператор
        if (rand(0, 99) < (int) ($p * 100)) {
            $g['op'] = $g['op'] === '>' ? '<' : '>';
        }
        // сторона
        if (rand(0, 99) < (int) ($p * 100)) {
            $g['side'] = -$g['side'];
        }
        // hold — шаг по шкале
        if (rand(0, 99) < (int) ($p * 100)) {
            $holds = [2, 3, 5, 10, 20];
            $ci = array_search($g['hold'], $holds, true);
            $ci = $ci === false ? 2 : $ci;
            $ci = max(0, min(4, $ci + (rand(0, 1) === 1 ? 1 : -1)));
            $g['hold'] = $holds[$ci];
        }
        // лоты
        if (rand(0, 99) < (int) ($p * 100)) {
            $g['lots'] = $g['lots'] === 1 ? 2 : 1;
        }
        // атом (тип признака)
        if (rand(0, 99) < (int) ($p * 50)) {
            $atoms = ['r5', 'r20', 'vol'];
            $ci = array_search($g['atom'], $atoms, true);
            $ci = $ci === false ? 0 : $ci;
            $g['atom'] = $atoms[($ci + rand(1, 2)) % 3];
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
