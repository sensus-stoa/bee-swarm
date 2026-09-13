<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Infra\Database;

/**
 * §2.5.14 AUTOPHAGY: селективная деградация грамматики при голодании.
 *
 * Заменяет случайную HUNGER_MUTATE (panic → strategy): пчела при
 * 3.0 ≤ E < 5.0 деградирует наименее ценные атомы своей грамматики,
 * возвращая их в общий пул и получая ΔE = +0.5 за атом. Атомы НЕ
 * удаляются из системы: grammar_ops — общий пул, деградация убирает
 * атом только из per-bee грамматики; другие пчёлы могут взять его
 * (перераспределение грамматического капитала: слабые уступают сильным).
 *
 * Биоаналог: клеточный аутофаг — голодная клетка разбирает повреждённые
 * митохондрии на аминокислоты. Атомы = митохондрии, AtomRegistry =
 * цитоплазма, энергия = ATP.
 *
 * Utility (протокол): use_count × cv_improvement × cross_domain_count.
 * Нормировка (монотонность сохранена):
 *  - use_count = grammar_ops.usage_count (default 1);
 *  - cv_improvement = 1 − min(cv) законов атома (лучший закон атома
 *    сильнее повышает полезность); 0 если законов нет;
 *  - cross_domain_count = COUNT(DISTINCT domain) законов атома; 0 если нет.
 * Атом без законов → utility 0 → деградируется первым.
 *
 * Медиана (протокол «population median»): популяция = живые пчёлы демона,
 * медиана utility кандидатов по ВСЕМ грамматикам роя; Hive вычисляет
 * агрегат и передаёт в degradeOne (сигнатура Bee::autophagy(?float)).
 * Fallback при пустой популяции — медиана кандидатов собственной
 * грамматики (одиночная пчела = вырожденная популяция из 1).
 *
 * Правило беззаконного атома: utility ≤ 0 (атом не строил законов)
 * деградируем всегда при голоде — для этой пчелы он не вносит вклад в
 * законы, а в пуле остаётся доступным другим. Без правила механизм
 * вырождался бы в «никогда не деградировать» при медиане 0 (все нули).
 * Ценные атомы (utility > 0 при util ≥ медианы) не затрагиваются.
 *
 * Граница кандидатов: protected core (BASE_OPS + SEMANTIC_OPS + fn-алиасы)
 * не деградирует никогда. Кандидат со статусом 'candidate' (RCB
 * двухфазность: reuse≥1 → PROMOTED active) НЕ деградируется — это
 * непроверенный frontier exploration-конвейера; его утилизирует
 * TTL-чистка (24h). Autophagy ест только ВЕРИФИЦИРОВАННЫЙ грамматический
 * капитал (premortem H1/H4: иначе «родил-съел» конвертирует exploration
 * в метаболизм). Атомы без строки в grammar_ops (мусор seed'а) —
 * беззаконные, правило беззаконного атома к ним применимо.
 *
 * Токен-матчинг атома в формуле (инфиксный язык роя): B-атомы (B1,
 * BW7a7aee, B7a7aee — "B" + [A-Za-z0-9]) — жадный токен-лист, решает
 * B1-vs-B10 и префикс-коллизии; именованные ops — вхождение "name("
 * (unary-вызов; 'sq' не матчится в 'sqrt(' — sqrt⊃sq по канону).
 */
final class AutophagyEngine
{
    /**
     * ΔE за деградацию атома (согласовано с rewardNovelty = 0.5).
     */
    public const DEGRADE_ENERGY = 0.5;

    /**
     * Стоп деградации: E_hunger (5.0) + E_buffer (2.0), §2.5.14.
     */
    public const STOP_ENERGY = 7.0;

    /**
     * Один шаг деградации: худший кандидат ниже ПОПУЛЯЦИОННОЙ медианы
     * полезности. Правило беззаконного атома: utility ≤ 0 деградируем
     * всегда (см. class doc).
     *
     * @param string[] $grammar per-bee грамматика (seed + custom)
     * @param string[] $exclude атомы, уже деградированные этой пчелой (lifetime)
     * @param float|null $populationMedian медиана utility кандидатов по рою
     *        (null = fallback: медиана собственной грамматики)
     * @return string|null имя атома или null (деградировать нечего)
     */
    public function degradeOne(array $grammar, array $exclude = [], ?float $populationMedian = null): ?string
    {
        $candidates = $this->candidates($grammar, $exclude);
        if ($candidates === []) {
            return null;
        }
        $utilities = $this->utilities($candidates);
        $median = $populationMedian ?? $this->median(array_values($utilities));
        foreach ($this->byWorstFirst($utilities) as $atom => $util) {
            if ($util <= 0.0 || $util < $median) {
                return $atom;
            }
            return null; // худший ≥ медианы и полезен → защищён
        }
        return null;
    }

    /**
     * Кандидаты: атомы пчелы минус защищённое ядро (§2.5.14 трогает только
     * рождённые/приобретённые атомы):
     *  - BASE_OPS (символы +,×,−,/,min,max,sq) — ядро выразимости;
     *  - fn-алиасы add/sub/mul/div — Grammar::apply принимает ОБА написания,
     *    легаси seed-грамматики используют fn-имена (без защиты голодная
     *    пчела деградировала бы 'add' как «беззаконный кастомный»);
     *  - SEMANTIC_OPS — knowledge-слой, доступны всем, не приобретение;
     *  - статус 'candidate' (непроверенный frontier, RCB двухфазность) —
     *    не деградируется (см. class doc, premortem H1/H4).
     * минус lifetime-исключения.
     *
     * @param string[] $grammar
     * @param string[] $exclude
     * @return string[]
     */
    private function candidates(array $grammar, array $exclude): array
    {
        $protected = array_merge(
            array_keys(Grammar::BASE_OPS),
            Grammar::SEMANTIC_OPS,
            ['add', 'sub', 'mul', 'div']
        );
        $out = [];
        foreach ($grammar as $atom) {
            if (in_array($atom, $protected, true) || in_array($atom, $exclude, true)) {
                continue;
            }
            $out[$atom] = true;
        }
        return array_keys($out);
    }

    /**
     * @param string[] $atoms
     * @return array<string,float>
     */
    private function utilities(array $atoms): array
    {
        $db = Database::get();
        $out = [];
        foreach ($atoms as $atom) {
            $out[$atom] = $this->atomUtility($db, $atom);
        }
        return $out;
    }

    /**
     * Utility атома. Атомы со статусом 'candidate' получают POSITIVE floor
     * (+ε выше беззаконного нуля): деградация их обходит (см. class doc),
     * но в популяционной медиане они считаются по существу.
     */
    private function atomUtility(\PDO $db, string $atom): float
    {
        $stmt = $db->prepare('SELECT usage_count, status FROM grammar_ops WHERE name = ? LIMIT 1');
        $stmt->execute([$atom]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $useCount = $row === false ? 1 : max(1, (int) $row['usage_count']);
        $isCandidate = $row !== false && (string) $row['status'] === 'candidate';

        // ORDER BY cv ASC: глобальный min-cv всегда в первых строках
        // (agent-review F3: LIMIT без сортировки терял лучший закон).
        // LIKE-префильтр (оптимизация), точность — token-матчинг ниже.
        $stmt = $db->prepare(
            "SELECT formula, cv, domain FROM laws
             WHERE formula LIKE '%" . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $atom) . "%'
             ORDER BY cv ASC
             LIMIT 200"
        );
        $stmt->execute();
        $bestCv = null;
        $domains = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $law) {
            if (! $this->formulaUsesAtom((string) $law['formula'], $atom)) {
                continue;
            }
            $cv = (float) $law['cv'];
            $bestCv = $bestCv === null || $cv < $bestCv ? $cv : $bestCv;
            $domains[(string) $law['domain']] = true;
        }

        $cvImprovement = $bestCv === null ? 0.0 : 1.0 - min(1.0, max(0.0, $bestCv));
        $utility = (float) $useCount * $cvImprovement * (float) count($domains);
        // candidate-floor: ε=1e-6, строго выше беззаконного 0.0, ниже любой
        // реальной пользы; гейт degradeOne <= 0.0 кандидата не выбирает
        return $isCandidate && $utility === 0.0 ? 1e-6 : $utility;
    }

    /**
     * Инфиксный токен-матчинг (см. class doc). Пространство B-имён:
     * B1 (birth-счётчик), B7a7aee (легаси), BW<md5-hex> (компрессор) —
     * общий шаблон "B" + опциональная "W" + hex-хвост. Переменные xN и
     * константы KN не начинаются с B, алфавит хвоста [0-9a-f] не жрёт
     * инфиксный контекст (в (x0B1x1) токен = B1, стоп на 'x').
     */
    private function formulaUsesAtom(string $formula, string $atom): bool
    {
        if (preg_match('/^BW?[0-9a-f]+$/', $atom) === 1) {
            preg_match_all('/BW?[0-9a-f]+/', $formula, $m);
            return in_array($atom, $m[0], true);
        }
        return str_contains($formula, $atom . '(');
    }

    /**
     * @param array<string,float> $utilities
     * @return array<string,float> utility asc, ties → имя asc (детерминизм)
     */
    private function byWorstFirst(array $utilities): array
    {
        uksort($utilities, static function (string $x, string $y) use ($utilities): int {
            $cmp = $utilities[$x] <=> $utilities[$y];
            return $cmp !== 0 ? $cmp : strcmp($x, $y);
        });
        return $utilities;
    }

    /**
     * Медиана utility КАНДИДАТОВ по всем живым пчёлам роя (популяционная,
     * протокол §2.5.14 «population median»). Hive вызывает один раз на
     * тик, результат передаёт в autophagy всех голодных пчёл.
     *
     * @param array<int, string[]> $grammars грамматики живых пчёл
     * @param string[] $excludeGlobal атомы вне риска (агрегированный lifetime)
     * @return float|null null = кандидатов в популяции нет
     */
    public function populationMedian(array $grammars, array $excludeGlobal = []): ?float
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT status FROM grammar_ops WHERE name = ? LIMIT 1');
        $all = [];
        $seen = [];
        foreach ($grammars as $grammar) {
            $candidates = $this->candidates($grammar, $excludeGlobal);
            foreach ($candidates as $atom) {
                if (isset($seen[$atom])) {
                    continue;
                }
                $seen[$atom] = true;
                // уникальные utility по атому (атом глобален: одинаков
                // для всех пчёл, дубли раздували бы медиану)
                $stmt->execute([$atom]);
                $status = $stmt->fetchColumn();
                if ($status !== false && (string) $status === 'candidate') {
                    continue; // frontier вне риска — в медиану не входит
                }
                $all[$atom] = $this->atomUtility($db, $atom);
            }
        }
        return $all === [] ? null : $this->median(array_values($all));
    }

    /**
     * @param float[] $values
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 === 1
            ? $values[$mid]
            : ($values[$mid - 1] + $values[$mid]) / 2.0;
    }
}
