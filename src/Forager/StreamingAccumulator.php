<?php

declare(strict_types=1);

namespace BeeSwarm\Forager;

use BeeSwarm\Core\AtomRegistry;

/**
 * StreamingAccumulator — extracted scanWithAccumulator() from Forager (D10 Phase 2).
 *
 * Groups same patterns across files using an in-memory SQLite accumulator.
 * Strategies (base + composed) and SemanticFactInserter are injected — no dependency on Forager.
 *
 * Caller pre-computes composed strategies and passes the merged set.
 * Paths collected during scan are available via getPaths() for fingerprint computation.
 */
class StreamingAccumulator
{
    /** E1-FIX: расширения файлов для текстового скоринга */
    private const TEXT_EXTENSIONS = ['md', 'txt', 'markdown', 'org', 'rst'];

    /** @var resource|null stderr handle for logging */
    private static $logStream = null;

    private static function log(string $msg): void
    {
        if (self::$logStream === null) {
            self::$logStream = fopen('php://stderr', 'w');
        }
        fwrite(self::$logStream, '[Accumulator] ' . $msg . "\n");
    }
    /**
     * @var array<string, callable>
     */
    private array $strategies;

    private SemanticFactInserter $factInserter;

    /**
     * @var string[] paths collected during scan
     */
    private array $lastPaths = [];

    /**
     * @param array<string, callable> $strategies base + composed (caller merges)
     */
    public function __construct(array $strategies, SemanticFactInserter $factInserter)
    {
        $this->strategies = $strategies;
        $this->factInserter = $factInserter;
    }

    /**
     * @return string[] paths collected during last scan()
     */
    public function getPaths(): array
    {
        return $this->lastPaths;
    }

    /**
     * Streaming scan with SQLite accumulator — groups same patterns across files.
     *
     * @param array<string, int> $dirs dir => priority
     * @return array<int, array{name: string, data: array, domain: string, content: string}>
     */
    public function scan(array $dirs): array
    {
        $this->lastPaths = [];

        $db = new \PDO('sqlite::memory:');
        $db->exec('CREATE TABLE fd (pattern TEXT, row_json TEXT, domain TEXT, source_path TEXT, col_labels TEXT, content TEXT, PRIMARY KEY(pattern, row_json, source_path))');
        $stmt = $db->prepare('INSERT OR IGNORE INTO fd (pattern,row_json,domain,source_path,col_labels,content) VALUES (?, ?, ?, ?, ?, ?)');

        $sorted = $dirs;
        arsort($sorted);

        foreach ($sorted as $dir => $pri) {
            if (! is_dir($dir)) {
                continue;
            }
            try {
                $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($iter as $f) {
                    try {
                        $path = $f->getPathname();
                        $this->lastPaths[] = $path;
                    } catch (\Throwable $e) {
                        self::log("path error: " . $e->getMessage());
                        continue;
                    }
                    if (str_contains($path, '.git/') || str_contains($path, 'venv/') || str_contains($path, 'node_modules/')) {
                        continue;
                    }
                    if (str_contains($path, '/.cache/') || str_contains($path, '/.local/share/')) {
                        continue;
                    }
                    if (str_contains($path, '/.mozilla/') || str_contains($path, '/.config/')) {
                        continue;
                    }
                    // Skip binary files — text atoms/strategies won't extract signal
                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    if ($ext !== '' && ! in_array($ext, self::TEXT_EXTENSIONS)
                        && ! in_array($ext, ['json', 'jsonl', 'csv', 'log'])) {
                        continue;
                    }
                    $content = @file_get_contents($path, false, null, 0, 50_000);
                    if (! $content) {
                        continue;
                    }
                    try {
                    $contentSample = mb_substr($content, 0, 5000);
                    $colLabels = self::guessLabels($contentSample);
                    foreach ($this->strategies as $sname => $fn) {
                        try {
                            $r = $fn($content);
                            if (empty($r) || ! is_array($r)) {
                                continue;
                            }
                            $isSemantic = false;
                            foreach ($r as $entry) {
                                if (is_array($entry) && isset($entry['semantic'])) {
                                    $this->factInserter->insert($entry['s'], $entry['p'], $entry['o']);
                                    $pat = 'sem_' . md5($entry['s'] . $entry['p'] . $entry['o']);
                                    $stmt->execute([$pat, json_encode([$entry['s'], $entry['p'], $entry['o']]), 'foraged_semantic', $path, $colLabels, $contentSample]);
                                    $isSemantic = true;
                                }
                            }
                            if ($isSemantic) {
                                continue;
                            }
                            if (isset($r[0]) && is_array($r[0])) {
                                foreach ($r as $row) {
                                    $allNum = true;
                                    foreach ($row as $v) {
                                        if (! is_numeric($v)) {
                                            $allNum = false;
                                            break;
                                        }
                                    }
                                    if (! $allNum) {
                                        continue;
                                    }
                                    $pat = 'num_' . md5($sname . count($row));
                                    $stmt->execute([$pat, json_encode($row), 'foraged', $path, $colLabels, $contentSample]);
                                }
                            }
                        } catch (\Throwable $e) {
                            self::log("strategy {$sname} error on " . basename($path) . ": " . $e->getMessage());
                        }
                    }
                    // Apply discovered text atoms (E1.6)
                    $txtAtoms = array_filter(AtomRegistry::all(), fn ($a) => AtomRegistry::isTextAtom($a) && str_contains($a, '('));
                    foreach ($txtAtoms as $atom) {
                        if (preg_match('/^(\w+)\((.+)\)$/', $atom, $m)) {
                            try {
                                $result = AtomRegistry::applyTextAtom($m[1], $content, $m[2]);
                                if (is_array($result) && ! empty($result)) {
                                    $pat = 'txt_' . md5($atom);
                                    if (is_numeric($result[0] ?? null)) {
                                        foreach ($result as $val) {
                                            $stmt->execute([$pat, json_encode([(float) $val]), 'foraged', $path, $colLabels, $contentSample]);
                                        }
                                    } else {
                                        // Non-numeric (e.g., preg_match without capture groups):
                                        // count occurrences in this file as a numeric feature
                                        $stmt->execute([$pat, json_encode([(float) count($result)]), 'foraged', $path, $colLabels, $contentSample]);
                                    }
                                }
                            } catch (\Throwable $e) {
                                self::log("text atom {$atom} error on " . basename($path) . ": " . $e->getMessage());
                            }
                        }
                    }

                    // E1-FIX Phase 2: markdown → текстовая задача для bootstrap.
                    // Общий паттерн чтобы накопилось ≥10 файлов.
                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    if (in_array($ext, self::TEXT_EXTENSIONS) && ! empty($contentSample)) {
                        $stmt->execute(['txt_content_raw', json_encode(['text' => $contentSample]), 'foraged_text', $path, '[]', $contentSample]);
                    }
                    } catch (\Throwable $e) {
                        self::log("file error on " . basename($path) . ": " . $e->getMessage());
                        continue;
                    }
                }
            } catch (\Throwable $e) {
                self::log("scan directory error: " . $e->getMessage());
            }
        }

        $tMin = 10;
        $tasks = [];
        $rows = $db->query("SELECT pattern, domain, COUNT(*) cnt FROM fd GROUP BY pattern, domain HAVING cnt >= {$tMin}");
        while ($r = $rows->fetch(\PDO::FETCH_ASSOC)) {
            $dr = $db->query("SELECT row_json, content, source_path, col_labels FROM fd WHERE pattern='{$r['pattern']}' LIMIT 1");
            $cr = $dr->fetch(\PDO::FETCH_ASSOC);
            $contentSample = $cr['content'] ?? '';
            $sourcePath = $cr['source_path'] ?? '';
            $colLabelsJson = $cr['col_labels'] ?? '[]';
            $data = [];
            $dr = $db->query("SELECT row_json FROM fd WHERE pattern='{$r['pattern']}' LIMIT 200");
            while ($d = $dr->fetch(\PDO::FETCH_NUM)) {
                $data[] = json_decode($d[0], true);
            }
            $tasks[] = [
                'name' => 'foraged_' . substr($r['pattern'], 0, 16),
                'data' => $data,
                'domain' => $r['domain'],
                'content' => $contentSample,
                'source_path' => $sourcePath,
                'col_labels' => json_decode($colLabelsJson, true) ?? [],
            ];
        }

        return $tasks;
    }

    /**
     * S1.11 Phase 3: Извлечение заголовков колонок из контента.
     * Пытается CSV (первая строка), markdown-таблицу (| h1 | h2 |), иначе пустой массив.
     * @return string JSON-encoded array of labels (e.g. '["price","qty"]')
     */
    private static function guessLabels(string $content): string
    {
        $lines = explode("\n", trim($content));
        if (empty($lines)) {
            return '[]';
        }

        // Markdown table: | header1 | header2 |
        // followed by |---|----|
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if (preg_match('/^\|(.+)\|$/', $line, $m)) {
                $cells = array_map('trim', explode('|', $m[1]));
                $cells = array_filter($cells, fn ($c) => $c !== '' && !preg_match('/^[-: ]+$/', $c));
                if (count($cells) >= 2 && isset($lines[$i + 1])
                    && preg_match('/^\|[-: |]+\|$/', trim($lines[$i + 1]))) {
                    return json_encode(array_values($cells)) ?: '[]';
                }
            }
        }

        // CSV: первая строка с нечисловыми значениями → заголовки
        $first = trim($lines[0]);
        $parts = str_getcsv($first);
        $nonNumeric = array_filter($parts, fn ($p) => $p !== '' && !is_numeric($p));
        if (count($nonNumeric) >= 2 && count($parts) >= 2) {
            return json_encode($parts) ?: '[]';
        }

        return '[]';
    }
}
