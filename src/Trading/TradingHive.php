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
    public const POP_CAP = 2000;     // потолок популяции (ёмкость среды)
    public const ATOMS = ['r2', 'r5', 'r10', 'r20', 'r40', 'vol', 'mom', 'zs', 'streak', 'pos20', 'brk20', 'regime'];
    public const EXT_ATOMS = [
        'fund5', 'taker5', 'oi_chg5', 'fng',
        'body', 'uwick', 'lwick', 'engulf', 'doji', 'impulse', 'gapmin', 'gapmax', // свечи Гусева/Бегса
        'vix5', 'ndq5', 'dxy5', 'trends', 'dvol', 'month', 'dow', // макро/внимание/календарь
        'relstr5', 'amihud', 'volz', 'rank20', // межрыночные: rank среди монет (О'Нил)
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
    public function evolve(array $windows, int $generations, array $seedGenomes = [], array $champions = []): array
    {
        $this->pop = [];
        if ($seedGenomes !== []) {
            // НАПРАВЛЕННАЯ СЕЛЕКЦИЯ: стартовая популяция — мутанты лучших
            for ($i = 0; $i < $this->popSize; $i++) {
                $sg = $seedGenomes[$i % count($seedGenomes)];
                $this->pop[] = [
                    'genome' => self::mutate($sg, 0.3),
                    'energy' => self::START_ENERGY,
                    'conf' => 0.0,
                    'calib' => 0.5,
                    'journal' => ['deals' => [], 'feats' => []],
                    'alive' => true,
                ];
            }
        } else {
            for ($i = 0; $i < $this->popSize; $i++) {
                $this->pop[] = [
                    'genome' => self::randomGenome(),
                    'energy' => self::START_ENERGY,
                    'conf' => 0.0,
                    'calib' => 0.5,
                    'journal' => ['deals' => [], 'feats' => []],
                    'alive' => true,
                ];
            }
        }
        for ($g = 0; $g < $generations; $g++) {
            $win = $windows[$g % count($windows)];
            [$window, $ext] = self::unpackWindow($win);
            // НИШИ: дни входов чемпионов на этом окне (вычисляем раз за поколение)
            $champDays = [];
            foreach ($champions as $cg) {
                $cd = self::tradeDeals($cg, $window, $ext);
                $champDays[] = $cd['entryDays'];
            }
            foreach ($this->pop as &$bee) {
                if (! $bee['alive']) {
                    continue;
                }
                // KELLY-РИСК с КАЛИБРОВКОЙ (v10: интеллект улья):
                // ставка ∝ уверенности × надёжность грамматики (calib)
                $kelly = max(0.5, min(2.0, 1.0 + $bee['conf'] / 5.0)) * $bee['calib'];
                $kelly = max(0.25, min(3.0, $kelly));
                $g2 = $bee['genome'];
                $g2['lots'] = $bee['genome']['lots'] * $kelly;
                $td = self::tradeDeals($g2, $window, $ext);
                $pnls = $td['deals'];
                $t = self::tStat($pnls);
                // ЖУРНАЛ копится через поколения (ограничим 300 последних сделок)
                $bee['journal']['deals'] = array_slice(
                    array_merge($bee['journal']['deals'], $pnls), -300);
                $bee['journal']['feats'] = array_slice(
                    array_merge($bee['journal']['feats'], $td['entryFeats']), -300);
                // ДООБУЧЕНИЕ (каждые 5 поколений) по НАКОПЛЕННОМУ журналу
                if ($g % 5 === 4) {
                    self::learnFilter($bee['genome'], $bee['journal']['deals'], $bee['journal']['feats']);
                }
                // уверенность сглаживается (0.7 прежняя + 0.3 свежий t)
                $bee['conf'] = 0.7 * $bee['conf'] + 0.3 * $t;
                // КАЛИБРОВКА: ставка оправдалась (высокая уверенность → прибыль) или
                // сомнение спасло (низкая уверенность при убытке) → calib↑; иначе ↓
                $stakeRight = ($bee['conf'] > 0.5 && $t > 0) || ($bee['conf'] < -0.5 && $t < 0)
                    || abs($bee['conf']) <= 0.5;
                $bee['calib'] = max(0.25, min(1.75, $bee['calib'] + ($stakeRight ? 0.05 : -0.1)));
                // НИША-ШТРАФ: занята ли её ниша чемпионом (совпадение входов)
                $maxOv = 0.0;
                foreach ($champDays as $cd) {
                    $maxOv = max($maxOv, self::nicheOverlap($td['entryDays'], $cd));
                }
                $niche = self::nichePenalty($maxOv);
                $nDeals = count($pnls);
                $freq = 1.0;
                if ($nDeals >= 1 && $nDeals <= 6) {
                    $freq = 1.5;
                } elseif ($nDeals > 15) {
                    $freq = 0.5;
                }
                $bee['energy'] += $niche * ($t * self::T_SCALE * $freq)
                    - 0.03 * (count($bee['genome']['conds']) - 1);
                if ($bee['energy'] <= 0.0) {
                    $bee['alive'] = false;
                }
            }
            unset($bee);

            $next = [];
            foreach ($this->pop as $bee) {
                if (! $bee['alive']) {
                    continue;
                }
                if ($bee['energy'] >= self::REPRO_ENERGY) {
                    $next[] = ['genome' => self::mutate($bee['genome'], 0.3), 'energy' => self::START_ENERGY, 'conf' => $bee['conf'] * 0.8, 'calib' => $bee['calib'], 'journal' => $bee['journal'], 'alive' => true];
                    $next[] = ['genome' => self::mutate($bee['genome'], 0.3), 'energy' => self::START_ENERGY, 'conf' => $bee['conf'] * 0.8, 'calib' => $bee['calib'], 'journal' => $bee['journal'], 'alive' => true];
                } else {
                    $next[] = $bee;
                }
            }
            if (count($next) > self::POP_CAP) {
                shuffle($next);
                $next = array_slice($next, 0, self::POP_CAP);
            }
            $this->pop = $next;
            if ($this->pop === []) {
                break;
            }
        }

        $out = [];
        $total = 0.0;
        foreach ($this->pop as $bee) {
            if ($bee['alive']) {
                $clean = 0.0;
                $kelly = max(0.5, min(2.0, 1.0 + $bee['conf'] / 5.0)) * $bee['calib'];
                $g2 = $bee['genome'];
                $g2['lots'] = $bee['genome']['lots'] * $kelly;
                foreach ($windows as $w) {
                    [$rw, $ex] = self::unpackWindow($w);
                    $clean += array_sum(self::tradeDeals($g2, $rw, $ex)['deals']);
                }
                $out[] = [
                    'genome' => $bee['genome'],
                    'energy' => $bee['energy'],
                    'oos_pnl' => $bee['energy'] - self::START_ENERGY,
                    'clean_pnl' => $clean,
                    'conf' => $bee['conf'],
                    'calib' => $bee['calib'],
                ];
                $total += $bee['energy'];
            }
        }
        return ['survivors' => $out, 'total_energy' => $total];
    }

    /** Доля входов A, совпавших со входами B (ниша-штраф) */
    public static function nicheOverlap(array $a, array $b): float
    {
        if ($a === []) {
            return 0.0;
        }
        $bSet = array_flip($b);
        $hit = 0;
        foreach ($a as $d) {
            if (isset($bSet[$d])) {
                $hit++;
            }
        }
        return $hit / count($a);
    }

    /** Фактор энергии за занятость ниши: 1.0 (свободна) → 0.2 (занята) */
    public static function nichePenalty(float $overlap): float
    {
        return max(0.2, 1.0 - 0.8 * $overlap);
    }

    /**
     * ПОРТФЕЛЬНЫЙ ОТБОР: жадный выбор пчёл по независимости сделок.
     * Лучшая по PnL — первая; следующая берётся, только если её серия
     * слабо коррелирует (< maxCorr) со всеми уже выбранными.
     *
     * @param array<int, list<float>> $series — sparse PnL-серии по дням
     * @param array<int, float> $pnls — чистый PnL пчёл
     * @return list<int> — индексы выбранных
     */
    public static function selectPortfolio(array $series, array $pnls, int $topN, float $maxCorr): array
    {
        $order = array_keys($pnls);
        usort($order, fn ($a, $b) => $pnls[$b] <=> $pnls[$a]);
        $selected = [];
        foreach ($order as $idx) {
            if (count($selected) >= $topN) {
                break;
            }
            $ok = true;
            foreach ($selected as $selIdx) {
                if (self::pearson($series[$idx], $series[$selIdx]) >= $maxCorr) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $selected[] = $idx;
            }
        }
        return $selected;
    }

    /** Pearson-корреляция двух рядов (выравнивание по длине) */
    private static function pearson(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n < 2) {
            return 0.0;
        }
        $ma = 0.0;
        $mb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $ma += $a[$i];
            $mb += $b[$i];
        }
        $ma /= $n;
        $mb /= $n;
        $cov = 0.0;
        $va = 0.0;
        $vb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $da = $a[$i] - $ma;
            $db = $b[$i] - $mb;
            $cov += $da * $db;
            $va += $da * $da;
            $vb += $db * $db;
        }
        if ($va < 1e-12 || $vb < 1e-12) {
            return 0.0;
        }
        return $cov / sqrt($va * $vb);
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
            // ТРЕЙЛИНГ: 0 = выключен (выход по hold), иначе порог отката от пика
            'trail' => rand(0, 9) < 3 ? 0.0 : (0.02 + (rand() / getrandmax()) * 0.08),
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
        // трейлинг: вкл/выкл/шаг порога
        if (rand(0, 99) < (int) ($p * 100)) {
            if ($g['trail'] <= 0.0) {
                $g['trail'] = 0.02 + (rand() / getrandmax()) * 0.08;
            } elseif (rand(0, 9) < 4) {
                $g['trail'] = 0.0;
            } else {
                $g['trail'] = max(0.01, min(0.15, $g['trail'] + (rand(0, 1) === 1 ? 1 : -1) * 0.02));
            }
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
            'pos20' => self::posInRange($ret, $i, 20),
            // ПРОБОЙ КАНАЛА (Turtle/Джонс): насколько close выше max прошлых 20д, в сигмах
            'brk20' => self::breakout20($ret, $i),
            // РЕЖИМ: z-скор 200-дневного тренда (кумулят / vol·√200)
            default => self::regime200($ret, $i),
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

    /** Пробой канала: (cum20 − max прошлых 20д) / σ20 — в сигмах (Turtle/Джонс) */
    private static function breakout20(array $ret, int $i): float
    {
        if ($i < 45) {
            return 0.0;
        }
        $cum = 0.0;
        for ($j = $i - 20; $j < $i; $j++) {
            $cum += $ret[$j];
        }
        // максимум кумулятивной позиции в окне [i-40, i-21)
        $mxPrev = -1e18;
        $c = 0.0;
        for ($j = $i - 40; $j < $i - 20; $j++) {
            $c += $ret[$j];
            $mxPrev = max($mxPrev, $c);
        }
        $sig = self::volN($ret, $i, 20);
        return $sig > 1e-9 ? ($cum - $mxPrev) / $sig : 0.0;
    }

    /** Режим: z-скор 200-дневного тренда */
    private static function regime200(array $ret, int $i): float
    {
        if ($i < 210) {
            return 0.0;
        }
        $cum = 0.0;
        for ($j = $i - 200; $j < $i; $j++) {
            $cum += $ret[$j];
        }
        $sig = self::volN($ret, $i, 20);
        return $sig > 1e-9 ? $cum / ($sig * sqrt(200)) : 0.0;
    }

    /** Сделки + главный признак на входе (для индивидуального обучения пчелы) */
    private static function tradeDeals(array $g, array $ret, array $ext = []): array
    {
        $n = count($ret);
        $deals = [];
        $entryFeats = []; // [сделка][атом] = значение на входе
        $entryDays = [];  // индекс дня входа (для ниша-штрафа)
        $inPos = 0;
        $side = 0;
        $cur = 0.0;
        $peak = 0.0;
        for ($i = 5; $i < $n; $i++) {
            if ($inPos > 0) {
                $cur += $side * $ret[$i] * $g['lots'];
                // ТРЕЙЛИНГ-СТОП: закрыть при откате от пика позиции на trail
                if (($g['trail'] ?? 0.0) > 0.0) {
                    $peak = max($peak, $cur);
                    if ($cur <= $peak - $g['trail']) {
                        $cur -= self::COST * $g['lots'];
                        $deals[] = $cur;
                        $cur = 0.0;
                        $inPos = 0;
                        $side = 0;
                        continue;
                    }
                }
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
                $peak = $cur;
                // ВСЕ внутренние атомы на входе (для дообучения-фильтра)
                $fvRow = [];
                foreach (self::ATOMS as $an) {
                    $fvRow[$an] = self::feat($an, $ret, $i);
                }
                $entryFeats[] = $fvRow;
                $entryDays[] = $i;
            }
        }
        return ['deals' => $deals, 'entryFeats' => $entryFeats, 'entryDays' => $entryDays];
    }

    /** Дообучение: добавить атом-фильтр, отделяющий успешные входы от неудачных.
     *  Успешные параметры НЕ меняются — только добавляется AND-условие,
     *  отсекающее неудачные зоны (не входим, если фильтр не пропускает). */
    private static function learnFilter(array &$g, array $pnls, array $feats): void
    {
        if (count($pnls) < 6 || count($g['conds']) >= 5) {
            return;
        }
        $succ = 0;
        $fail = 0;
        foreach ($pnls as $p) {
            if ($p > 0) {
                $succ++;
            } else {
                $fail++;
            }
        }
        if ($succ < 3 || $fail < 3) {
            return;
        }
        $bestAtom = null;
        $bestThr = 0.0;
        $bestOp = '>';
        $bestAcc = 0.0;
        foreach (self::ATOMS as $an) {
            $vals = [];
            foreach ($pnls as $idx => $p) {
                if (isset($feats[$idx][$an])) {
                    $vals[] = [$feats[$idx][$an], $p > 0];
                }
            }
            if (count($vals) < 6) {
                continue;
            }
            // порог = середина средних успешных/неудачных
            $ms = 0.0;
            $mf = 0.0;
            $cs = 0;
            $cf = 0;
            foreach ($vals as [$v, $ok]) {
                if ($ok) {
                    $ms += $v;
                    $cs++;
                } else {
                    $mf += $v;
                    $cf++;
                }
            }
            if ($cs === 0 || $cf === 0) {
                continue;
            }
            $ms /= $cs;
            $mf /= $cf;
            $thr = ($ms + $mf) / 2;
            $op = $ms > $mf ? '>' : '<'; // успешные — по одну сторону порога
            // точность разделения
            $right = 0;
            foreach ($vals as [$v, $ok]) {
                $pred = $op === '>' ? ($v >= $thr) : ($v <= $thr);
                if ($pred === $ok) {
                    $right++;
                }
            }
            $acc = $right / count($vals);
            if ($acc > $bestAcc) {
                $bestAcc = $acc;
                $bestAtom = $an;
                $bestThr = $thr;
                $bestOp = $op;
            }
        }
        // добавляем фильтр только при хорошем разделении (защита от переобучения)
        if ($bestAtom !== null && $bestAcc >= 0.65) {
            $g['conds'][] = ['atom' => $bestAtom, 'op' => $bestOp, 'threshold' => $bestThr];
            $g['logics'][] = 'AND';
        }
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
