<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Infra\Database;

/**
 * RecordKeeper — запись открытий в БД.
 *
 * Извлечён из Hive::recordDiscovery(). D18: dedup + DB insert + cross-domain.
 */
class RecordKeeper
{
    /** @var array<string, true> */
    private array $knownLaws = [];

    /**
     * @param array $d ['atom' => string, 'cv' => float, 'mode' => string]
     * @param array $task ['name' => string, 'source_path' => string, 'content' => string, 'col_labels' => array]
     * @param string $domain
     * @return array{inserted: bool, cross_domains: list<string>, key: string}
     */
    /** T5-post-3: операторы, получающие культурный вес от durable-законов. */
    // 'sq' НЕ в списке: квадрат в канон-форме записывается как (x*x), токена 'sq'
    // нет, а str_contains('sq') ловил бы 'sqrt' (двойной буст, self-check 05.09).
    private const CULTURE_OPS = ['+', '×', '−', '/', 'max', 'min', 'sqrt'];

    /** ЭКСП-014: cap против заморозки грамматики (квадратичный отрыв базовых ops). */
    private const MAX_CULTURE_WEIGHT = 50;

    /** T5-post-4: cap набора виденных fingerprint'ов на закон. */
    private const SEEN_FP_CAP = 10;

    public function record(array $d, array $task, string $domain): array
    {
        // FORMAL-LAYER Ф1: каноническая форма формулы — (x1+x0) ≡ (x0+x1)
        $canonFormula = \BeeSwarm\Core\ExpressionNormalizer::normalize($d['atom']);

        // Ключ БЕЗ name (CONCERNS Ф1 05.08): разные задачи с одинаковой
        // формулой в одном домене — один закон, не дубли
        $key = $domain . '::' . $canonFormula;
        $known = isset($this->knownLaws[$key]);
        $this->knownLaws[$key] = true;

        $lawClass = $d['class'] ?? 'EMPIRICAL';

        // Cross-domain detection
        $crossDomains = [];
        if (($d['mode'] ?? '') === 'compose') {
            $other = Database::get()->prepare(
                'SELECT DISTINCT domain FROM laws WHERE formula=? AND domain!=?'
            );
            $other->execute([$canonFormula, $domain]);
            $crossDomains = $other->fetchAll(\PDO::FETCH_COLUMN);
        }

        // Ф1: дедуп по (formula,domain); повторное открытие = usage_count+1
        // (сохраняет частотность для Grammar::capped)
        // T5-post: confirmed_count растёт ТОЛЬКО при повторе на ДРУГИХ данных
        // (другой task fingerprint). Повтор на тех же данных — не подтверждение
        // (unlucky-seed защита: EXP3 congruence, выборочная корреляция).
        $fingerprint = (string) ($task['fingerprint'] ?? '');
        $stmt = Database::get()->prepare(
            'SELECT last_fingerprint, confirmed_count, seen_fingerprints FROM laws WHERE formula=? AND domain=?'
        );
        $stmt->execute([$canonFormula, $domain]);
        $prev = $stmt->fetch(\PDO::FETCH_ASSOC);
        $isRepeat = $prev !== false;
        // T5-post-4 (ЭКСП-037): confirm = fp НОВЫЙ для закона (не в seen-наборе).
        // Повтор той же fp-пары не несёт новой информации — буста нет.
        $seen = [];
        if ($isRepeat) {
            $seen = (array) (json_decode((string) ($prev['seen_fingerprints'] ?? '[]'), true) ?: []);
        }
        $isNewPair = $isRepeat
            && $fingerprint !== ''
            && ! in_array($fingerprint, $seen, true)
            && (string) ($prev['last_fingerprint'] ?? '') !== $fingerprint;
        $confirm = $isNewPair;
        // И3 (премортем deleg_e8b0e05b): пустой fp не затирает сохранённый
        $fpToStore = ($fingerprint !== '' || ! $isRepeat)
            ? $fingerprint
            : (string) ($prev['last_fingerprint'] ?? '');

        Database::get()->prepare(
            'INSERT INTO laws (name,formula,cv,domain,source_path,content_sample,col_labels,law_class,usage_count,last_fingerprint,seen_fingerprints)
             VALUES (?,?,?,?,?,?,?,?,1,?,?)
             ON CONFLICT(formula,domain) DO UPDATE SET
               usage_count = MIN(usage_count + 1, ?),
               last_fingerprint = excluded.last_fingerprint,
               seen_fingerprints = excluded.seen_fingerprints,
               confirmed_count = MIN(confirmed_count + ?, ?)'
        )->execute([
            $task['name'], $canonFormula, $d['cv'], $domain,
            $task['source_path'] ?? '',
            mb_substr($task['content'] ?? '', 0, 200),
            json_encode($task['col_labels'] ?? []),
            $lawClass,
            $fpToStore,
            json_encode(self::updateSeenSet($fingerprint, $seen)),
            self::MAX_CULTURE_WEIGHT,
            $confirm ? 1 : 0,
            self::MAX_CULTURE_WEIGHT,
        ]);

        // T5-post-3 (премортем И3 deleg_122a0816): культура следует за durable-знанием.
        // Подтверждение закона бустит usage_count операторов его формулы —
        // weightedPick ведёт рой к строительным операторам. Graduated: каждый
        // confirm = +1 (не бинарный флаг).
        if ($confirm) {
            $this->boostOperators($canonFormula);
        }

        // knownLaws больше НЕ глушит запись: usage_count/confirmed_count обязаны
        // расти на повторах (T5-post). inserted = первое открытие в этом инстансе.
        return [
            'inserted' => ! $known,
            'confirmed' => $confirm,
            'cross_domains' => $crossDomains,
            'key' => $key,
        ];
    }

    /**
     * T5-post: durable-законы домена — подтверждённые повторным открытием
     * на разных данных (confirmed_count >= 1).
     *
     * @return list<array<string, mixed>>
     */
    public function confirmedLaws(string $domain): array
    {
        $stmt = Database::get()->prepare(
            'SELECT name, formula, cv, usage_count, confirmed_count
             FROM laws
             WHERE domain = ? AND confirmed_count >= 1
             ORDER BY confirmed_count DESC, usage_count DESC'
        );
        $stmt->execute([$domain]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * T5-post-2: презентация — durable-законы для экспорта/отчётов.
     * Семантика та же, что confirmedLaws; отдельное имя для читаемости
     * call-site (презентация ≠ внутренний durable-список).
     * NOTE (премортем И4): call-site появится в v4-экспорте; до тех пор
     * метод остаётся документированным API-контрактом durable-семантики.
     *
     * @return list<array<string, mixed>>
     */
    public function presentable(string $domain): array
    {
        return $this->confirmedLaws($domain);
    }

    /**
     * T5-post-3: +1 к usage_count операторов, присутствующих в формуле.
     * grammar_ops имеет UNIQUE(name) — один оп = одна строка, source = первое
     * происхождение. Буст: UPDATE по имени; оп не в грамматике — создаётся.
     * Чужие токены (колонки, константы) молча игнорируются.
     */
    private function boostOperators(string $canonFormula): void
    {
        $db = Database::get();
        foreach (self::CULTURE_OPS as $op) {
            if (! str_contains($canonFormula, $op)) {
                continue;
            }
            // ЭКСП-014 урок (премортем И2 deleg_cca310fb): монотонный буст →
            // базовые ops квадратично отрываются → грамматика замерзает.
            // Cap ограничивает отрыв, сохраняя graduated-порядок.
            $upd = $db->prepare(
                'UPDATE grammar_ops SET usage_count = usage_count + 1
                 WHERE name = ? AND usage_count < ?'
            );
            $upd->execute([$op, self::MAX_CULTURE_WEIGHT]);
            if ($upd->rowCount() === 0) {
                // op отсутствует ИЛИ на cap. INSERT только для отсутствующих.
                $exists = $db->prepare('SELECT 1 FROM grammar_ops WHERE name = ?');
                $exists->execute([$op]);
                if ($exists->fetchColumn() === false) {
                    $db->prepare(
                        'INSERT INTO grammar_ops (name, source, usage_count) VALUES (?, ?, 1)'
                    )->execute([$op, 'culture']);
                }
            }
        }
    }

    /**
     * T5-post-4: обновить набор виденных fingerprint'ов (cap 10).
     * Новый fp добавляется; при переполнении вытесняется самый старый.
     * fp, уже в наборе, не добавляется.
     *
     * @param list<string> $seen
     * @return list<string>
     */
    private static function updateSeenSet(string $fingerprint, array $seen): array
    {
        if ($fingerprint === '' || in_array($fingerprint, $seen, true)) {
            return $seen;
        }
        $seen[] = $fingerprint;
        if (count($seen) > self::SEEN_FP_CAP) {
            array_shift($seen); // вытесняем самый старый
        }
        return $seen;
    }

    public function preloadKnown(): int
    {
        $rows = Database::get()->query('SELECT name, formula, domain FROM laws')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $this->knownLaws[($r['domain'] ?? 'unknown') . '::' . $r['formula']] = true;
        }
        return count($this->knownLaws);
    }
}
