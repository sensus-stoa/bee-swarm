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
    public static float $costOverride = -1.0; // микро-ад: 0.0007 и т.п.
    public static int $levCap = 100; // потолок плеча (устойчивый отбор: 3)

    private static function cost(): float
    {
        return self::$costOverride >= 0 ? self::$costOverride : self::COST;
    }
    public const T_SCALE = 0.3;      // масштаб t-статистики в энергию
    public const REPRO_ENERGY = 2.0; // порог размножения (накопили вдвое)
    public const POP_CAP = 20000;     // потолок популяции (ёмкость среды)
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
    public function evolve(array $windows, int $generations, array $seedGenomes = [], array $champions = [], int $minDealsPerWindow = 0, int $binary = 0, int $swarmLearn = 0): array
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
                foreach ($g2['branches'] ?? [] as $bi => $_) {
                    $g2['branches'][$bi]['lots'] = ($g2['branches'][$bi]['lots'] ?? 1) * $kelly;
                    if ($binary === 1) {
                        $g2['branches'][$bi]['hold'] = 1; // БИНАРКА: горизонт 1 день
                    }
                }
                if (empty($g2['branches'])) {
                    $g2['lots'] = ($bee['genome']['lots'] ?? 1) * $kelly;
                }
                $td = self::tradeDeals($g2, $window, $ext);
                $pnls = $td['deals'];
                $t = self::tStat($pnls);
                // РЕАЛЬНЫЙ МАРЖИН-КОЛЛ: каждая ликвидация = потеря залога (1.0 энергии)
                if (($td['liquidations'] ?? 0) > 0) {
                    $bee['energy'] -= 1.0 * $td['liquidations'];
                    if ($bee['energy'] <= 0.0) {
                        $bee['alive'] = false;
                        continue;
                    }
                }
                // ЖУРНАЛ копится через поколения (ограничим 300 последних сделок)
                $bee['journal']['deals'] = array_slice(
                    array_merge($bee['journal']['deals'], $pnls), -300);
                $bee['journal']['feats'] = array_slice(
                    array_merge($bee['journal']['feats'], $td['entryFeats']), -300);
                // ДООБУЧЕНИЕ (каждые 5 поколений) по НАКОПЛЕННОМУ журналу
                if ($g % 5 === 4) {
                    // обучаем ветку, давшую больше всего сделок
                    $counts = array_count_values($td['entryBranches']);
                    arsort($counts);
                    $bestBranch = array_key_first($counts);
                    if ($bestBranch !== null && isset($bee['genome']['branches'][$bestBranch])) {
                        self::learnFilterBranch($bee['genome']['branches'][$bestBranch], $pnls, $td['entryFeats']);
                    }
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
                if ($binary === 1 && count($pnls) > 0) {
                    // БИНАРНЫЙ ФИТНЕС v3: только при N>=15 (защита от wr-шума
                    // малых выборок). z-оценка vs 54% + ЖЁСТКАЯ смерть при
                    // подтверждённой монетке (N>=20 и wr<50% → −0.6 энергии).
                    $wins = 0;
                    foreach ($pnls as $p) {
                        if ($p > 0) {
                            $wins++;
                        }
                    }
                    $nB = count($pnls);
                    $wr = $wins / $nB;
                    if ($nB >= 15) {
                        $se = sqrt($wr * (1 - $wr) / $nB);
                        $z = ($wr - 0.54) / max($se, 1e-9);
                        $bee['energy'] += $niche * $z * 0.5;
                        if ($nB >= 20 && $wr < 0.50) {
                            $bee['energy'] -= 0.6; // подтверждённая монетка = смерть
                        }
                    }
                } else {
                    $bee['energy'] += $niche * ($t * self::T_SCALE * $freq)
                        - 0.03 * max(0, array_sum(array_map(fn ($br) => count($br['conds'] ?? []), $bee['genome']['branches'] ?? [])) - 1);
                }
                // ЧАСТЫЙ АД: обязан торговать — редкие сделки штрафуются
                // (отбор сам уберёт длинные hold — выживут только активные)
                if ($minDealsPerWindow > 0 && count($pnls) < $minDealsPerWindow) {
                    $bee['energy'] -= 0.3;
                }
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
                foreach ($g2['branches'] ?? [] as $bi => $_) {
                    $g2['branches'][$bi]['lots'] = ($g2['branches'][$bi]['lots'] ?? 1) * $kelly;
                    if ($binary === 1) {
                        $g2['branches'][$bi]['hold'] = 1; // БИНАРКА: горизонт 1 день
                    }
                }
                if (empty($g2['branches'])) {
                    $g2['lots'] = ($bee['genome']['lots'] ?? 1) * $kelly;
                }
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

    /** Доступные плечи (с учётом потолка устойчивости) */
    private static function levChoices(): array
    {
        return array_values(array_filter([1, 2, 3, 5, 10, 20, 50, 100], fn ($l) => $l <= self::$levCap));
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

    /** @return array{branches:list<array>,conf...}: пчела = набор ВЕТОК (каждая со своей стороной) */
    private static function randomGenome(): array
    {
        $nBranches = rand(1, 2); // стартуем с 1-2 веток — универсальность растёт эволюцией
        $branches = [];
        for ($b = 0; $b < $nBranches; $b++) {
            $branches[] = self::randomBranch();
        }
        return ['branches' => $branches];
    }

    /** Одна ветка: условия + сторона + выход (бык/медведь/флэт-ветка) */
    private static function randomBranch(): array
    {
        $allAtoms = array_merge(self::ATOMS, self::EXT_ATOMS);
        $nConds = rand(1, 2);
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
            'lev' => self::levChoices()[array_rand(self::levChoices())], // ПЛЕЧО (≤ cap)
            'trail' => rand(0, 9) < 3 ? 0.0 : (0.02 + (rand() / getrandmax()) * 0.08),
        ];
    }

    /** @param array $g геном — мутирует случайную ВЕТКУ; ветки добавляются/удаляются */
    private static function mutate(array $g, float $p): array
    {
        if ($g['branches'] !== []) {
            $bi = rand(0, count($g['branches']) - 1);
            $g['branches'][$bi] = self::mutateBranch($g['branches'][$bi], $p);
        }
        // ДОБАВИТЬ ветку (универсальность растёт; потолок 4 — parsimony)
        if (count($g['branches']) < 4 && rand(0, 99) < (int) ($p * 40)) {
            $g['branches'][] = self::randomBranch();
        }
        // УДАЛИТЬ ветку (если больше одной)
        if (count($g['branches']) > 1 && rand(0, 99) < (int) ($p * 25)) {
            array_splice($g['branches'], rand(0, count($g['branches']) - 1), 1);
        }
        return $g;
    }

    /** Мутация одной ветки (условия, сторона, выход) */
    private static function mutateBranch(array $b, float $p): array
    {
        $allAtoms = array_merge(self::ATOMS, self::EXT_ATOMS);
        // мутация случайного условия
        if ($b['conds'] !== [] && rand(0, 99) < (int) ($p * 100)) {
            $ci = rand(0, count($b['conds']) - 1);
            $c = $b['conds'][$ci];
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
            $b['conds'][$ci] = $c;
        }
        if (count($b['conds']) < 5 && rand(0, 99) < (int) ($p * 40)) {
            $atom = $allAtoms[array_rand($allAtoms)];
            $isExt = in_array($atom, self::EXT_ATOMS, true);
            $b['conds'][] = [
                'atom' => $atom,
                'threshold' => $isExt ? (rand() / getrandmax() * 4 - 2) : (rand() / getrandmax()) * 0.06,
                'op' => rand(0, 1) === 1 ? '>' : '<',
            ];
            $b['logics'][] = rand(0, 1) === 1 ? 'AND' : 'OR';
        }
        if (count($b['conds']) > 1 && rand(0, 99) < (int) ($p * 40)) {
            $ci = rand(0, count($b['conds']) - 1);
            array_splice($b['conds'], $ci, 1);
            if ($ci > 0) {
                array_splice($b['logics'], $ci - 1, 1);
            } elseif ($b['logics'] !== []) {
                array_splice($b['logics'], 0, 1);
            }
        }
        if ($b['logics'] !== [] && rand(0, 99) < (int) ($p * 100)) {
            $ci = rand(0, count($b['logics']) - 1);
            $b['logics'][$ci] = $b['logics'][$ci] === 'AND' ? 'OR' : 'AND';
        }
        if (rand(0, 99) < (int) ($p * 100)) {
            $b['side'] = -$b['side'];
        }
        if (rand(0, 99) < (int) ($p * 100)) {
            $ci = array_search($b['hold'], self::HOLDS, true);
            $ci = $ci === false ? 2 : $ci;
            $ci = max(0, min(count(self::HOLDS) - 1, $ci + (rand(0, 1) === 1 ? 1 : -1)));
            $b['hold'] = self::HOLDS[$ci];
        }
        if (rand(0, 99) < (int) ($p * 100)) {
            $b['lots'] = $b['lots'] === 1 ? 2 : 1;
        }
        if (rand(0, 99) < (int) ($p * 100)) {
            $levs = self::levChoices();
            $ci = array_search($b['lev'] ?? 1, $levs, true);
            $ci = $ci === false ? 0 : $ci;
            $ci = max(0, min(count($levs) - 1, $ci + (rand(0, 1) === 1 ? 1 : -1)));
            $b['lev'] = $levs[$ci];
        }
        if (rand(0, 99) < (int) ($p * 100)) {
            if ($b['trail'] <= 0.0) {
                $b['trail'] = 0.02 + (rand() / getrandmax()) * 0.08;
            } elseif (rand(0, 9) < 4) {
                $b['trail'] = 0.0;
            } else {
                $b['trail'] = max(0.01, min(0.15, $b['trail'] + (rand(0, 1) === 1 ? 1 : -1) * 0.02));
            }
        }
        return $b;
    }

    /** Волатильность n дней (std) */
    private static function volN(array $ret, int $i, int $n): float
    {
        if ($i < $n) {
            return 0.0;
        }
        $m = 0.0;
        for ($j = $i - $n; $j < $i; $j++) {
            $m += $ret[$j];
        }
        $m /= $n;
        $v = 0.0;
        for ($j = $i - $n; $j < $i; $j++) {
            $v += ($ret[$j] - $m) ** 2;
        }
        return sqrt($v / $n);
    }

    /** Серия знаков: длина текущей серии одного знака (со знаком) */
    private static function streak(array $ret, int $i): float
    {
        if ($i < 2) {
            return 0.0;
        }
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
            'mom' => array_sum(array_slice($ret, $i - 5, 5)) / 5 - array_sum(array_slice($ret, $i - 20, 20)) / 20,
            'zs' => self::volN($ret, $i, 20) > 1e-9 ? (array_sum(array_slice($ret, $i - 20, 20)) / 20) / self::volN($ret, $i, 20) : 0.0,
            'streak' => self::streak($ret, $i),
            'pos20' => self::posInRange($ret, $i, 20),
            'brk20' => self::breakout20($ret, $i),
            default => self::regime200($ret, $i),
        };
    }

    /** Позиция цены в n-дневном диапазоне (0=низ, 1=верх) */
    private static function posInRange(array $ret, int $i, int $n): float
    {
        if ($i < $n) {
            return 0.5;
        }
        $mn = 1e18;
        $mx = -1e18;
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

    /** Сделки + признаки/дни/ветка на входе (для обучения и ниш) */
    private static function tradeDeals(array $g, array $ret, array $ext = []): array
    {
        $n = count($ret);
        $deals = [];
        $entryFeats = [];
        $entryDays = [];
        $liquidations = 0;
        $entryBranches = [];
        $inPos = 0;
        $side = 0;
        $cur = 0.0;
        $peak = 0.0;
        $activeBranch = null;
        for ($i = 5; $i < $n; $i++) {
            if ($inPos > 0) {
                // РЕАЛЬНАЯ БИРЖА: интрабар-ликвидация по ВНУТРИДНЕВНОМУ
                // движению (high/low), а не по close: свеча могла закрыться
                // в плюс, но ВНУТРИ дня пробить цену ликвидации
                $levNow = ($activeBranch['lev'] ?? 1);
                $adv = $ext['adv'][$i] ?? abs($ret[$i]); // запасной: |ret|
                if ($adv * $levNow >= 0.95) {
                    $deals[] = -1.0 - 0.02 * $levNow;
                    $liquidations++;
                    $cur = 0.0;
                    $inPos = 0;
                    $side = 0;
                    continue;
                }
                // движение close×плечо (если adv не пробил, но close дошёл)
                $mv = $side * $ret[$i] * $levNow;
                if ($mv <= -0.95) {
                    // залог + штраф ликвидации (растёт с плечом, как на бирже)
                    $deals[] = -1.0 - 0.02 * ($activeBranch['lev'] ?? 1);
                    $liquidations++;
                    $cur = 0.0;
                    $inPos = 0;
                    $side = 0;
                    continue;
                }
                $cur += $mv * $activeBranch['lots'];
                // FUNDING × ПЛЕЧО: шорт получает, лонг платит (с множителем lev)
                if (isset($ext['funding'][$i])) {
                    $cur -= $side * $ext['funding'][$i] * $activeBranch['lots'] * ($activeBranch['lev'] ?? 1);
                }
                // кумулятивный МАРЖИН-КОЛЛ
                if ($cur <= -1.0) {
                    $deals[] = -1.0 - 0.02 * ($activeBranch['lev'] ?? 1);
                    $liquidations++;
                    $cur = 0.0;
                    $inPos = 0;
                    $side = 0;
                    continue;
                }
                if (($activeBranch['trail'] ?? 0.0) > 0.0) {
                    $peak = max($peak, $cur);
                    if ($cur <= $peak - $activeBranch['trail']) {
                        $cur -= self::cost() * $activeBranch['lots'] * ($activeBranch['lev'] ?? 1);
                        $deals[] = $cur;
                        $cur = 0.0;
                        $inPos = 0;
                        $side = 0;
                        continue;
                    }
                }
                $inPos--;
                if ($inPos === 0) {
                    $cur -= self::cost() * $activeBranch['lots'] * ($activeBranch['lev'] ?? 1);
                    $deals[] = $cur;
                    $cur = 0.0;
                }
                continue;
            }
            // перебор ВЕТОК: первая с выполненными условиями даёт вход
            $branches = $g['branches'] ?? [self::legacyBranch($g)];
            foreach ($branches as $bi => $branch) {
                if (self::branchSignal($branch, $ret, $ext, $i)) {
                    $side = $branch['side'];
                    $inPos = $branch['hold'];
                    $cur = -self::cost() * $branch['lots'] * ($branch['lev'] ?? 1);
                    $peak = $cur;
                    $activeBranch = $branch;
                    $fvRow = [];
                    foreach (self::ATOMS as $an) {
                        $fvRow[$an] = self::feat($an, $ret, $i);
                    }
                    $entryFeats[] = $fvRow;
                    $entryDays[] = $i;
                    $entryBranches[] = $bi;
                    break;
                }
            }
        }
        return ['deals' => $deals, 'entryFeats' => $entryFeats, 'entryDays' => $entryDays, 'entryBranches' => $entryBranches, 'liquidations' => $liquidations];
    }

    /** Условия ветки выполнены? */
    private static function branchSignal(array $branch, array $ret, array $ext, int $i): bool
    {
        $sig = null;
        foreach ($branch['conds'] as $ci => $cond) {
            $v = in_array($cond['atom'], self::EXT_ATOMS, true)
                ? ($ext[$cond['atom']][$i] ?? null)
                : self::feat($cond['atom'], $ret, $i);
            if ($v === null) {
                if ($ci === 0) {
                    $sig = false;
                    continue;
                }
                $lg = $branch['logics'][$ci - 1];
                $sig = $lg === 'AND' ? false : $sig;
                continue;
            }
            $csig = ($cond['op'] === '>' && $v >= $cond['threshold'])
                || ($cond['op'] === '<' && $v <= $cond['threshold']);
            if ($sig === null) {
                $sig = $csig;
                continue;
            }
            $lg = $branch['logics'][$ci - 1];
            $sig = $lg === 'AND' ? ($sig && $csig) : ($sig || $csig);
        }
        return $sig === true;
    }

    /** Совместимость: старый геном (conds+side) → одна ветка */
    private static function legacyBranch(array $g): array
    {
        return [
            'conds' => $g['conds'] ?? [],
            'logics' => $g['logics'] ?? [],
            'side' => $g['side'] ?? 1,
            'hold' => $g['hold'] ?? 20,
            'lots' => $g['lots'] ?? 1,
            'lev' => $g['lev'] ?? 1,
            'trail' => $g['trail'] ?? 0.0,
        ];
    }

    /** Дообучение: добавить атом-фильтр, отделяющий успешные входы от неудачных.
     *  Успешные параметры НЕ меняются — только добавляется AND-условие,
     *  отсекающее неудачные зоны (не входим, если фильтр не пропускает). */
    private static function learnFilterBranch(array &$branch, array $pnls, array $feats): void
    {
        if (count($pnls) < 6 || count($branch['conds']) >= 5) {
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
            $branch['conds'][] = ['atom' => $bestAtom, 'op' => $bestOp, 'threshold' => $bestThr];
            $branch['logics'][] = 'AND';
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
