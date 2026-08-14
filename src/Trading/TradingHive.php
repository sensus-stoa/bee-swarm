<?php
declare(strict_types=1);

namespace BeeSwarm\Trading;

/**
 * FIN-005 TRADING-BEES (v6, 15.08): пчёлы торгуют, без законов.
 * АРСЕНАЛ: 10 атомов (мультитаймфрейм r2-r40, vol, моментум, z-скор,
 * серия знаков, позиция в диапазоне = поддержка/сопротивление).
 * ФИТНЕС: t-статистика сделок (mean/std×√N — Шёпот: слабый эффект
 * накапливается статистически, шум усредняется).
 * ЭНЕРГИЯ: накапливается по t; energy<=0 → смерть; размножаются
 * накопившие; дележ при размножении (энергия не создаётся из ничего);
 * окна ЧЕРЕДУЮТСЯ (адаптация к смене режимов).
 */
final class TradingHive
{
    public const START_ENERGY = 1.0;
    public const COST = 0.002;       // 0.2% за цикл (вход+выход)
    public const T_SCALE = 0.3;      // масштаб t-статистики в энергию
    public const REPRO_ENERGY = 2.0; // порог размножения (накопили вдвое)
    public const POP_CAP = 500;      // потолок популяции (ёмкость среды)
    public const ATOMS = ['r2', 'r5', 'r10', 'r20', 'r40', 'vol', 'mom', 'zs', 'streak', 'pos20'];
    public const EXT_ATOMS = [
        'fund5', 'taker5', 'oi_chg5', 'fng',
        'body', 'uwick', 'lwick', 'engulf', 'doji', 'impulse', 'gapmin', 'gapmax', // свечи Гусева/Бегса
        'vix5', 'ndq5', 'dxy5', 'trends', 'dvol', 'month', 'dow', // макро/внимание/календарь
        'relstr5', 'amihud', 'volz', // межрыночные: rel strength vs BTC, Amihud, объём-z
    ];
    public const HOLDS = [2, 3, 5, 10, 20];

    /** @var list<array{genome: array, energy: float, alive: bool}> */
    private array $pop = [];
    private int $popSize;

    public function __construct(int $popSize = 100)
    {
        $this->popSize = $popSize;
    }

    /**
     * @param list<mixed> $windows — окна: list<float> ИЛИ ['ret'=>list<float>, 'ext'=>array<string,list<?float>>]
     * @return array{survivors: list<array{genome: array, energy: float, oos_pnl: float, clean_pnl: float}>, total_energy: float}
     */
    public function evolve(array $windows, int $generations, array $seedGenomes = []): array
    {
        $this->pop = [];
        if ($seedGenomes !== []) {
            // НАПРАВЛЕННАЯ СЕЛЕКЦИЯ: стартовая популяция — мутанты лучших
            // геномов предыдущего раунда (порода ведётся)
            for ($i = 0; $i < $this->popSize; $i++) {
                $sg = $seedGenomes[$i % count($seedGenomes)];
                $this->pop[] = [
                    'genome' => self::mutate($sg, 0.3),
                    'energy' => self::START_ENERGY,
                    'alive' => true,
                ];
            }
        } else {
            for ($i = 0; $i < $this->popSize; $i++) {
                $this->pop[] = [
                    'genome' => self::randomGenome(),
                    'energy' => self::START_ENERGY,
                    'alive' => true,
                ];
            }
        }
        for ($g = 0; $g < $generations; $g++) {
            // АДАПТАЦИЯ: окна ЧЕРЕДУЮТСЯ — пчела должна выживать на СМЕНЕ режимов
            $win = $windows[$g % count($windows)];
            [$window, $ext] = self::unpackWindow($win);
            foreach ($this->pop as &$bee) {
                if (! $bee['alive']) {
                    continue;
                }
                $pnls = self::tradeDeals($bee['genome'], $window, $ext);
                // t-статистика сделок: Шёпот — слабый эффект накапливается
                $t = self::tStat($pnls);
                // РЕДКИЕ СДЕЛКИ (v8): 1-2/мес — бонус качества входа;
                // частые — штраф (издержки и шум съедают)
                $nDeals = count($pnls);
                $freq = 1.0;
                if ($nDeals >= 1 && $nDeals <= 6) {
                    $freq = 1.5; // ~1-2 сделки в месяц на окно ~70 дней
                } elseif ($nDeals > 15) {
                    $freq = 0.5;
                }
                $bee['energy'] += $t * self::T_SCALE * $freq
                    - 0.03 * (count($bee['genome']['conds']) - 1); // parsimony: лишние условия дороги
                if ($bee['energy'] <= 0.0) {
                    $bee['alive'] = false;
                }
            }
            unset($bee);

            // РАЗМНОЖЕНИЕ (популяционная динамика): родитель, накопивший
            // REPRO_ENERGY, ДЕЛИТСЯ на двух потомков (по START_ENERGY каждому)
            // и исчезает. Потомки — мутанты генома родителя.
            $next = [];
            foreach ($this->pop as $bee) {
                if (! $bee['alive']) {
                    continue;
                }
                if ($bee['energy'] >= self::REPRO_ENERGY) {
                    $next[] = ['genome' => self::mutate($bee['genome'], 0.3), 'energy' => self::START_ENERGY, 'alive' => true];
                    $next[] = ['genome' => self::mutate($bee['genome'], 0.3), 'energy' => self::START_ENERGY, 'alive' => true];
                } else {
                    $next[] = $bee;
                }
            }
            // ЁМКОСТЬ СРЕДЫ: излишек убирается СЛУЧАЙНО (без bias отбора)
            if (count($next) > self::POP_CAP) {
                shuffle($next);
                $next = array_slice($next, 0, self::POP_CAP);
            }
            $this->pop = $next;
            if ($this->pop === []) {
                break; // вымирание — эволюция честно закончилась
            }
        }

        $out = [];
        $total = 0.0;
        foreach ($this->pop as $bee) {
            if ($bee['alive']) {
                $clean = 0.0;
                foreach ($windows as $w) {
                    [$rw, $ex] = self::unpackWindow($w);
                    $clean += array_sum(self::tradeDeals($bee['genome'], $rw, $ex));
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

    /** Распаковка окна: list<float> или ['ret'=>..., 'ext'=>...] */
    private static function unpackWindow(mixed $win): array
    {
        if (is_array($win) && isset($win['ret'])) {
            return [$win['ret'], $win['ext'] ?? []];
        }
        return [$win, []];
    }

    /** @return array{conds:list<array{atom:string,threshold:float,op:string}>,logics:list<string>,side:int,hold:int,lots:int} */
    private static function randomGenome(): array
    {
        $allAtoms = array_merge(self::ATOMS, self::EXT_ATOMS);
        $nConds = rand(1, 2); // стартуем с 1-2 условий — глубина растёт эволюцией
        $conds = [];
        for ($c = 0; $c < $nConds; $c++) {
            $atom = $allAtoms[array_rand($allAtoms)];
            $isExt = in_array($atom, self::EXT_ATOMS, true);
            $conds[] = [
                'atom' => $atom,
                'threshold' => $isExt ? (rand() / getrandmax() * 4 - 2) : (rand() / getrandmax()) * 0.06,
                'op' => rand(0, 1) === 1 ? '>' : '<',
            ];
        }
        $logics = [];
        for ($c = 1; $c < $nConds; $c++) {
            $logics[] = rand(0, 1) === 1 ? 'AND' : 'OR';
        }
        return [
            'conds' => $conds,
            'logics' => $logics,
            'side' => rand(0, 1) === 1 ? 1 : -1,
            'hold' => self::HOLDS[array_rand(self::HOLDS)],
            'lots' => [1, 2][array_rand([1, 2])],
        ];
    }

    /** @param array $g геном — мутируют ВСЕ параметры; структура условий растёт/ужимается */
    private static function mutate(array $g, float $p): array
    {
        $allAtoms = array_merge(self::ATOMS, self::EXT_ATOMS);
        // мутация случайного условия
        if ($g['conds'] !== [] && rand(0, 99) < (int) ($p * 100)) {
            $ci = rand(0, count($g['conds']) - 1);
            $c = $g['conds'][$ci];
            $isExt = in_array($c['atom'], self::EXT_ATOMS, true);
            $step = $isExt ? 0.3 : ($c['threshold'] * 0.3 + 0.0005);
            if (rand(0, 9) === 0) {
                $step = $isExt ? 1.0 : ($c['threshold'] * 1.5 + 0.005);
            }
            $c['threshold'] = max($isExt ? -4.0 : 0.0, $c['threshold'] + (rand(0, 1) === 1 ? 1 : -1) * $step);
            if (rand(0, 9) < 4) {
                $c['op'] = $c['op'] === '>' ? '<' : '>';
            }
            if (rand(0, 9) < 3) {
                $c['atom'] = $allAtoms[array_rand($allAtoms)];
            }
            $g['conds'][$ci] = $c;
        }
        // ДОБАВИТЬ условие (структура растёт; потолок 5 — parsimony)
        if (count($g['conds']) < 5 && rand(0, 99) < (int) ($p * 40)) {
            $atom = $allAtoms[array_rand($allAtoms)];
            $isExt = in_array($atom, self::EXT_ATOMS, true);
            $g['conds'][] = [
                'atom' => $atom,
                'threshold' => $isExt ? (rand() / getrandmax() * 4 - 2) : (rand() / getrandmax()) * 0.06,
                'op' => rand(0, 1) === 1 ? '>' : '<',
            ];
            $g['logics'][] = rand(0, 1) === 1 ? 'AND' : 'OR';
        }
        // УДАЛИТЬ условие (если больше одного)
        if (count($g['conds']) > 1 && rand(0, 99) < (int) ($p * 40)) {
            $ci = rand(0, count($g['conds']) - 1);
            array_splice($g['conds'], $ci, 1);
            if ($ci > 0) {
                array_splice($g['logics'], $ci - 1, 1);
            } elseif ($g['logics'] !== []) {
                array_splice($g['logics'], 0, 1);
            }
        }
        // мутация логики
        if ($g['logics'] !== [] && rand(0, 99) < (int) ($p * 100)) {
            $ci = rand(0, count($g['logics']) - 1);
            $g['logics'][$ci] = $g['logics'][$ci] === 'AND' ? 'OR' : 'AND';
        }
        if (rand(0, 99) < (int) ($p * 100)) {
            $g['side'] = -$g['side'];
        }
        if (rand(0, 99) < (int) ($p * 100)) {
            $ci = array_search($g['hold'], self::HOLDS, true);
            $ci = $ci === false ? 2 : $ci;
            $ci = max(0, min(count(self::HOLDS) - 1, $ci + (rand(0, 1) === 1 ? 1 : -1)));
            $g['hold'] = self::HOLDS[$ci];
        }
        if (rand(0, 99) < (int) ($p * 100)) {
            $g['lots'] = $g['lots'] === 1 ? 2 : 1;
        }
        return $g;
    }

    /** Признак атома по ПРОШЛЫМ дням (лаг — без текущего дня) */
    private static function feat(string $atom, array $ret, int $i): float
    {
        return match ($atom) {
            'r2' => array_sum(array_slice($ret, $i - 2, 2)) / 2,
            'r5' => array_sum(array_slice($ret, $i - 5, 5)) / 5,
            'r10' => array_sum(array_slice($ret, $i - 10, 10)) / 10,
            'r20' => array_sum(array_slice($ret, $i - 20, 20)) / 20,
            'r40' => array_sum(array_slice($ret, $i - 40, 40)) / 40,
            'vol' => self::volN($ret, $i, 20),
            // моментум: быстрый − медленный
            'mom' => array_sum(array_slice($ret, $i - 5, 5)) / 5
                   - array_sum(array_slice($ret, $i - 20, 20)) / 20,
            // z-скор: 20-дневная доходность / волатильность
            'zs' => (self::volN($ret, $i, 20) > 1e-9)
                ? (array_sum(array_slice($ret, $i - 20, 20)) / 20) / self::volN($ret, $i, 20)
                : 0.0,
            // серия знаков: длина текущей серии одного знака (±)
            'streak' => self::streak($ret, $i),
            // позиция в 20-дневном диапазоне (поддержка/сопротивление)
            default => self::posInRange($ret, $i, 20),
        };
    }

    private static function volN(array $ret, int $i, int $n): float
    {
        $seg = array_slice($ret, $i - $n, $n);
        $m = array_sum($seg) / $n;
        $v = 0.0;
        foreach ($seg as $x) {
            $v += ($x - $m) ** 2;
        }
        return sqrt($v / $n);
    }

    private static function streak(array $ret, int $i): float
    {
        $sign = $ret[$i - 1] >= 0 ? 1 : -1;
        $len = 1;
        for ($j = $i - 2; $j > $i - 21 && $j >= 0; $j--) {
            if (($ret[$j] >= 0 ? 1 : -1) === $sign) {
                $len++;
            } else {
                break;
            }
        }
        return $sign * $len;
    }

    private static function posInRange(array $ret, int $i, int $n): float
    {
        $seg = array_slice($ret, $i - $n, $n);
        $cum = array_sum($seg);
        $mn = $cum;
        $mx = $cum;
        $c = 0.0;
        for ($j = $i - $n; $j < $i; $j++) {
            $c += $ret[$j];
            $mn = min($mn, $c);
            $mx = max($mx, $c);
        }
        return ($mx - $mn) > 1e-9 ? ($c - $mn) / ($mx - $mn) : 0.5;
    }

    /** Список PnL сделок; сигнал — ЦЕПОЧКА условий (conds + logics) */
    private static function tradeDeals(array $g, array $ret, array $ext = []): array
    {
        $n = count($ret);
        $deals = [];
        $inPos = 0;
        $side = 0;
        $cur = 0.0;
        for ($i = 5; $i < $n; $i++) {
            if ($inPos > 0) {
                $cur += $side * $ret[$i] * $g['lots'];
                $inPos--;
                if ($inPos === 0) {
                    $cur -= self::COST * $g['lots'];
                    $deals[] = $cur;
                    $cur = 0.0;
                }
                continue;
            }
            // свернуть цепочку условий
            $sig = null;
            foreach ($g['conds'] as $ci => $cond) {
                $v = in_array($cond['atom'], self::EXT_ATOMS, true)
                    ? ($ext[$cond['atom']][$i] ?? null)
                    : self::feat($cond['atom'], $ret, $i);
                if ($v === null) {
                    // нет данных по внешнему атому: условие «неизвестно»
                    if ($sig === null) {
                        $sig = false;
                    }
                    if ($ci === 0) {
                        continue;
                    }
                    $lg = $g['logics'][$ci - 1];
                    $sig = $lg === 'AND' ? false : $sig; // AND×неизвестно=false; OR — без изменений
                    continue;
                }
                $csig = ($cond['op'] === '>' && $v >= $cond['threshold'])
                    || ($cond['op'] === '<' && $v <= $cond['threshold']);
                if ($sig === null) {
                    $sig = $csig;
                    continue;
                }
                $lg = $g['logics'][$ci - 1];
                $sig = $lg === 'AND' ? ($sig && $csig) : ($sig || $csig);
            }
            if ($sig === true) {
                $side = $g['side'];
                $inPos = $g['hold'];
                $cur = -self::COST * $g['lots'];
            }
        }
        return $deals;
    }

    /** t-статистика сделок: mean/std×√N (шёпот: слабый эффект накапливается) */
    private static function tStat(array $deals): float
    {
        $n = count($deals);
        if ($n < 2) {
            return 0.0;
        }
        $m = array_sum($deals) / $n;
        $v = 0.0;
        foreach ($deals as $d) {
            $v += ($d - $m) ** 2;
        }
        $sd = sqrt($v / $n);
        return $sd > 1e-9 ? ($m / $sd) * sqrt($n) : 0.0;
    }
}
