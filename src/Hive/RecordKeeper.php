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
     * @return array{inserted: bool, cross_domains: string[]}
     */
    public function record(array $d, array $task, string $domain): array
    {
        // FORMAL-LAYER Ф1: каноническая форма формулы — (x1+x0) ≡ (x0+x1)
        $canonFormula = \BeeSwarm\Core\ExpressionNormalizer::normalize($d['atom']);

        // Ключ БЕЗ name (CONCERNS Ф1 05.08): разные задачи с одинаковой
        // формулой в одном домене — один закон, не дубли
        $key = $domain . '::' . $canonFormula;
        if (isset($this->knownLaws[$key])) {
            return ['inserted' => false, 'cross_domains' => [], 'key' => $key];
        }

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
        Database::get()->prepare(
            'INSERT INTO laws (name,formula,cv,domain,source_path,content_sample,col_labels,law_class,usage_count)
             VALUES (?,?,?,?,?,?,?,?,1)
             ON CONFLICT(formula,domain) DO UPDATE SET usage_count = usage_count + 1'
        )->execute([
            $task['name'], $canonFormula, $d['cv'], $domain,
            $task['source_path'] ?? '',
            mb_substr($task['content'] ?? '', 0, 200),
            json_encode($task['col_labels'] ?? []),
            $lawClass,
        ]);

        return ['inserted' => true, 'cross_domains' => $crossDomains, 'key' => $key];
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
