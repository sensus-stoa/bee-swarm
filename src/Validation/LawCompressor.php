<?php
declare(strict_types=1);

namespace BeeSwarm\Validation;

use BeeSwarm\Database;


// ~/.bee_swarm/src/LawCompressor.php
// Сжатие кластеров законов: N compose-законов → 1 meta-law

class LawCompressor
{
    private int $minClusterSize;

    public function __construct(int $minClusterSize = 3)
    {
        $this->minClusterSize = $minClusterSize;
    }

    /** Сжать все домены с compose-законами */
    public function compress(): array
    {
        $db = Database::get();
        $domains = $db->query("SELECT DISTINCT domain FROM laws WHERE formula LIKE '%(%'")->fetchAll(\PDO::FETCH_COLUMN);

        $result = ['total_compressed' => 0, 'domains' => []];

        foreach ($domains as $domain) {
            $clusters = $this->clusterByOuter($domain);
            $domainCompressed = 0;

            foreach ($clusters as $outer => $count) {
                if ($count >= $this->minClusterSize) {
                    $innerList = $db->prepare("SELECT formula FROM laws WHERE domain = ? AND formula LIKE ?");
                    $innerList->execute([$domain, "$outer(%"]);
                    $inners = array_map(fn($f) => preg_replace("/^{$outer}\((.+)\)$/", '$1', $f), $innerList->fetchAll(\PDO::FETCH_COLUMN));
                    $innerStr = implode(',', array_slice($inners, 0, 5));

                    // Удалить индивидуальные
                    $db->prepare("DELETE FROM laws WHERE domain = ? AND formula LIKE ?")->execute([$domain, "$outer(%"]);
                    // Создать meta-law
                    $db->prepare("INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)")
                       ->execute(["meta_{$outer}", "{$outer}(*) — {$count} ops: {$innerStr}", 0.0, $domain]);
                    $domainCompressed++;
                }
            }

            if ($domainCompressed > 0) {
                $result['domains'][$domain] = $domainCompressed;
                $result['total_compressed'] += $domainCompressed;
            }
        }

        return $result;
    }

    private function clusterByOuter(string $domain): array
    {
        $db = Database::get();
        $laws = $db->prepare("SELECT formula FROM laws WHERE domain = ? AND formula LIKE '%(%'");
        $laws->execute([$domain]);
        $rows = $laws->fetchAll(\PDO::FETCH_COLUMN);

        $clusters = [];
        foreach ($rows as $f) {
            if (preg_match('/^(\w+)\(/', $f, $m)) {
                $outer = $m[1];
                $clusters[$outer] = ($clusters[$outer] ?? 0) + 1;
            }
        }
        return $clusters;
    }
}
