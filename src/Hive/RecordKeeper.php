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
            'SELECT last_fingerprint, confirmed_count FROM laws WHERE formula=? AND domain=?'
        );
        $stmt->execute([$canonFormula, $domain]);
        $prev = $stmt->fetch(\PDO::FETCH_ASSOC);
        $isRepeat = $prev !== false;
        $confirm = $isRepeat
            && $fingerprint !== ''
            && (string) ($prev['last_fingerprint'] ?? '') !== $fingerprint;

        Database::get()->prepare(
            'INSERT INTO laws (name,formula,cv,domain,source_path,content_sample,col_labels,law_class,usage_count,last_fingerprint)
             VALUES (?,?,?,?,?,?,?,?,1,?)
             ON CONFLICT(formula,domain) DO UPDATE SET
               usage_count = usage_count + 1,
               last_fingerprint = excluded.last_fingerprint,
               confirmed_count = confirmed_count + ?'
        )->execute([
            $task['name'], $canonFormula, $d['cv'], $domain,
            $task['source_path'] ?? '',
            mb_substr($task['content'] ?? '', 0, 200),
            json_encode($task['col_labels'] ?? []),
            $lawClass,
            $fingerprint,
            $confirm ? 1 : 0,
        ]);

        // knownLaws больше НЕ глушит запись: usage_count/confirmed_count обязаны
        // расти на повторах (T5-post). inserted = первое открытие в этом инстансе.
        return [
            'inserted' => ! $known,
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

    public function preloadKnown(): int
    {
        $rows = Database::get()->query('SELECT name, formula, domain FROM laws')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $this->knownLaws[($r['domain'] ?? 'unknown') . '::' . $r['formula']] = true;
        }
        return count($this->knownLaws);
    }
}
