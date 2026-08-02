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
        $key = $domain . '::' . $task['name'] . '::' . $d['atom'];
        if (isset($this->knownLaws[$key])) {
            return ['inserted' => false, 'cross_domains' => [], 'key' => $key];
        }

        $this->knownLaws[$key] = true;

        // Cross-domain detection
        $crossDomains = [];
        if (($d['mode'] ?? '') === 'compose') {
            $other = Database::get()->prepare(
                'SELECT DISTINCT domain FROM laws WHERE formula=? AND domain!=?'
            );
            $other->execute([$d['atom'], $domain]);
            $crossDomains = $other->fetchAll(\PDO::FETCH_COLUMN);
        }

        Database::get()->prepare(
            'INSERT OR IGNORE INTO laws (name,formula,cv,domain,source_path,content_sample,col_labels) VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $task['name'], $d['atom'], $d['cv'], $domain,
            $task['source_path'] ?? '',
            mb_substr($task['content'] ?? '', 0, 200),
            json_encode($task['col_labels'] ?? []),
        ]);

        return ['inserted' => true, 'cross_domains' => $crossDomains, 'key' => $key];
    }

    public function preloadKnown(): int
    {
        $rows = Database::get()->query('SELECT name, formula, domain FROM laws')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $this->knownLaws[($r['domain'] ?? 'unknown') . '::' . $r['name'] . '::' . $r['formula']] = true;
        }
        return count($this->knownLaws);
    }
}
