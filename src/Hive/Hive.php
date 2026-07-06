<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Forager\DataSelfGenerator;
use BeeSwarm\Forager\Forager;
use BeeSwarm\Infra\Database;
use BeeSwarm\Infra\PlateauDetector;
use BeeSwarm\Text\CorpusVocabulary;
use BeeSwarm\Text\SentenceRegistry;

/**
 * Hive — главный цикл роя.
 * agenda.php → (new Hive(...))->run()
 */
class Hive
{
    private PlateauDetector $plateau;

    private Forager $forager;

    private array $foragerSources;

    private int $foragerScanInterval = 100;

    private string $logFile;

    private array $log = [];

    private int $tick = 0;

    private array $knownLaws = [];

    private ?CorpusVocabulary $corpusVocab = null;

    private ?SentenceRegistry $sentenceRegistry = null;

    private array $foragedTasksGlobal = [];

    private ?int $maxTicks;

    public function __construct(
        ?PlateauDetector $plateau = null,
        ?Forager $forager = null,
        ?int $maxTicks = null,
    ) {
        $this->plateau = $plateau ?? new PlateauDetector(50);
        $this->forager = $forager ?? new Forager();
        $this->maxTicks = $maxTicks;

        $sources = getenv('FORAGER_SOURCES');
        $this->foragerSources = $sources
            ? array_fill_keys(explode(':', $sources), 1)
            : [];

        $logDir = dirname(__DIR__, 2) . '/logs';
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->logFile = $logDir . '/agenda.log';
    }

    // ═══ ЛОГГИРОВАНИЕ ═══

    private function log(string $msg): void
    {
        $line = '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
        echo $line;
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }

    // ═══ ГЛАВНЫЙ ЦИКЛ ═══

    public function run(): int
    {
        $this->bootstrap();

        while (true) {
            $this->tick++;
            $this->doTick();

            if ($this->maxTicks !== null && $this->tick >= $this->maxTicks) {
                break;
            }
        }

        return $this->tick;
    }

    private function bootstrap(): void
    {
        echo "[AGI v4-cloze] Hive. Log: {$this->logFile}\n";

        // Enable held-out validation
        AtomRegistry::setHeldoutEnabled(true);

        // Preload known laws
        $rows = Database::get()->query('SELECT name, formula, domain FROM laws')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $this->knownLaws[($r['domain'] ?? 'unknown') . '::' . $r['name'] . '::' . $r['formula']] = true;
        }
        $this->log('Preloaded ' . count($this->knownLaws) . ' known laws from DB');

        // Forager startup scan
        if (! empty($this->foragerSources)) {
            $foraged = $this->forager->scan($this->foragerSources);
            if (! empty($foraged)) {
                $this->foragedTasksGlobal = $foraged;
                $this->log('Forager startup: ' . count($foraged) . ' tasks');
            }
        }

        // Retrospective validation
        $allTasks = $this->getTasks();
        if (! empty($allTasks)) {
            $retro = AtomRegistry::retrospectiveValidate($allTasks);
            if (count($retro['overfit']) > 0) {
                $this->log('RETRO_OVERFIT: removed ' . count($retro['overfit']) . ' laws');
            }
            $this->log('Retrospective: ' . count($retro['passed']) . ' passed, '
                . count($retro['overfit']) . ' overfit removed');
        }

        // Corpus
        $lairDir = getenv('HOME') . '/Documents/the_lair';
        if (is_dir($lairDir)) {
            $this->corpusVocab = new CorpusVocabulary([$lairDir]);
            $this->sentenceRegistry = new SentenceRegistry([$lairDir], $this->corpusVocab);
            $this->log("Corpus: {$this->corpusVocab->size()} words, {$this->sentenceRegistry->count()} sentences");
        }
    }

    // ═══ ОДИН ТИК ═══

    private function doTick(): void
    {
        // CPU guard
        $load = sys_getloadavg();
        $nproc = max(1, (int) (shell_exec('nproc 2>/dev/null') ?: 1));
        if ($load[0] / $nproc > 0.7) {
            usleep(2_000_000);
            return;
        }

        // Forager scan
        if (
            ! empty($this->foragerSources)
            && ($this->tick % $this->foragerScanInterval === 0 || $this->plateau->justEnteredPlateau())
        ) {
            $foraged = $this->forager->scan($this->foragerSources);
            if (! empty($foraged)) {
                $this->foragedTasksGlobal = array_merge($this->foragedTasksGlobal, $foraged);
                if ($this->forager->hasNewContent()) {
                    $this->log('FORAGER_NEW_TASK: ' . $this->forager->getNewTaskCount()
                        . ' tasks, ' . $this->forager->getNewDomainCount() . ' domains');
                    $this->plateau->wakeup();
                    $this->forager->markContentConsumed();
                }
            }
        }

        $tasks = $this->getTasks();
        if (empty($tasks)) {
            usleep(1_000_000);
            return;
        }

        // Wakeup on new task count
        static $lastTaskCount = 0;
        $currentTaskCount = count($tasks);
        if ($currentTaskCount !== $lastTaskCount) {
            $this->plateau->wakeup();
            $lastTaskCount = $currentTaskCount;
        }

        $task = $tasks[array_rand($tasks)];
        $data = $task['data'];
        if (count($data) > 30) {
            $keys = array_rand($data, 30);
            $data = array_map(fn ($k) => $data[$k], $keys);
            $data = array_values($data);
        }
        $X = array_map(fn ($r) => array_slice($r, 0, -1), $data);
        $y = array_column($data, count($data[0]) - 1);
        $domain = $task['domain'] ?? 'unknown';

        $foundAny = false;

        // Cloze
        if ($domain === 'cloze' && $this->sentenceRegistry) {
            $this->doClozeTick($task, $data, $domain, $foundAny);
        }

        // Discover
        if (! $foundAny && $domain !== 'cloze') {
            $this->doDiscoverTick($task, $X, $y, $domain, $foundAny);
        }

        // Compose
        if ($this->plateau->shouldRunCompose() && $foundAny && $domain !== 'cloze') {
            $this->doComposeTick($X, $y, $domain, $foundAny);
        }

        if (! $foundAny) {
            usleep(500_000);
        }

        if (count($this->log) > 200) {
            $this->log = array_slice($this->log, -100);
        }
        $this->plateau->tick($foundAny);
        if ($this->plateau->justEnteredPlateau()) {
            $this->log('🏔️ PLATEAU');
        }
        usleep($this->plateau->getSleepUs());
    }

    private function doClozeTick(array $task, array $data, string $domain, bool &$foundAny): void
    {
        $g = new Grammar();
        $grammarOps = $g->all();
        $bestAtom = null;
        $bestError = 1.0;
        $opIndex = 0;

        foreach ($grammarOps as $op) {
            $errors = 0;
            $total = count($data);
            $radius = 1 + ($opIndex % 3);

            foreach ($data as $row) {
                [$sId, $maskPos, $targetId, $expected] = $row;
                $sentence = $this->sentenceRegistry->get((int) $sId);
                if (! $sentence) {
                    $errors++;
                    continue;
                }
                $ids = $sentence['token_ids'];

                $window = [];
                for ($i = max(0, $maskPos - $radius); $i <= min(count($ids) - 1, $maskPos + $radius); $i++) {
                    if ($i !== $maskPos) {
                        $window[] = $ids[$i];
                    }
                }

                $pred = in_array((int) $targetId, $window) ? 1.0 : 0.0;
                if (abs($pred - $expected) > 0.01) {
                    $errors++;
                }
            }

            $er = $errors / max(1, $total);
            if ($er < $bestError) {
                $bestError = $er;
                $bestAtom = $op;
            }
            $opIndex++;
        }

        if ($bestAtom && $bestError < 0.5) {
            $key = $domain . '::' . $task['name'] . '::' . $bestAtom;
            if (! isset($this->knownLaws[$key])) {
                $this->knownLaws[$key] = true;
                $foundAny = true;
                Database::get()->prepare('INSERT OR IGNORE INTO laws (name,formula,cv,domain) VALUES (?,?,?,?)')
                    ->execute([$task['name'], $bestAtom, $bestError, $domain]);
                $this->log("📖 {$task['name']} -> {$bestAtom} (err=" . round($bestError, 3) . ')');
                $this->plateau->tick(true);
            }
        }
    }

    private function doDiscoverTick(array $task, array $X, array $y, string $domain, bool &$foundAny): void
    {
        // Statistical sufficiency (HONEST_CRITERIA §1.2)
        $nFeat = count($X[0] ?? []);
        $tMin = max(10, $nFeat * 5);
        if (count($y) < $tMin) {
            return; // недостаточно данных
        }

        if (AtomRegistry::isHeldoutEnabled()) {
            foreach (AtomRegistry::discoverHeldout($X, $y) as $d) {
                $this->recordDiscovery($d, $task, $domain, $foundAny);
            }
        } else {
            foreach (AtomRegistry::discover($X, $y) as $d) {
                $this->recordDiscovery($d, $task, $domain, $foundAny);
            }
        }
    }

    private function recordDiscovery(array $d, array $task, string $domain, bool &$foundAny): void
    {
        $key = $domain . '::' . $task['name'] . '::' . $d['atom'];
        if (isset($this->knownLaws[$key])) {
            $this->log("DUPLICATE: {$d['atom']} [{$domain}]");
            return;
        }
        $this->knownLaws[$key] = true;
        $foundAny = true;
        $g = new Grammar();
        if (! in_array($d['atom'], $g->all())) {
            $g->add($d['atom'], 'auto-discover');
        }
        Database::get()->prepare('INSERT OR IGNORE INTO laws (name,formula,cv,domain) VALUES (?,?,?,?)')
            ->execute([$task['name'], $d['atom'], $d['cv'], $domain]);
        $this->log("🔍 {$task['name']} -> {$d['atom']} (CV=0) [{$domain}]");
        $this->plateau->tick(true);
    }

    private function doComposeTick(array $X, array $y, string $domain, bool &$foundAny): void
    {
        $g = new Grammar();
        $grammarOps = $g->all();
        if (count($grammarOps) < 2) {
            return;
        }

        // Statistical sufficiency (HONEST_CRITERIA §1.2)
        $nFeat = count($X[0] ?? []);
        $tMin = max(10, $nFeat * 5);
        if (count($y) < $tMin) {
            return;
        }

        $candidates = AtomRegistry::discoverCompose($X, $y, $grammarOps);
        if (! empty($candidates)) {
            $validated = \BeeSwarm\Validation\LawValidator::validate($candidates, $X, $y);
            foreach ($validated as $c) {
                $this->recordDiscovery($c, ['name' => $c['atom']], $domain, $foundAny);
                if (! in_array($c['atom'], $grammarOps)) {
                    $g->add($c['atom'], 'auto-compose');
                }
            }
        }
    }

    // ═══ ЗАДАЧИ ═══

    private function getTasks(): array
    {
        static $tasks = null;
        static $lastRegen = 0;

        if ($tasks !== null && ($this->tick - $lastRegen) < 100) {
            return array_merge($tasks, $this->foragedTasksGlobal);
        }
        $lastRegen = $this->tick;

        $tasks = [];

        // Metrics
        $gen = new DataSelfGenerator();
        $tasks = array_merge($tasks, $gen->fromMetrics());

        // Base tasks
        $base = [
            [
                'name' => 'AND',
                'domain' => 'logic',
                'data' => [[0, 0, 0], [0, 1, 0], [1, 0, 0], [1, 1, 1], [0, 0, 0], [1, 0, 0], [0, 1, 0], [1, 1, 1], [0, 1, 0], [1, 0, 0]],
            ],
            [
                'name' => 'ADD',
                'domain' => 'arithmetic',
                'data' => [[1, 2, 3], [3, 4, 7], [5, 6, 11], [7, 8, 15], [9, 10, 19], [2, 5, 7], [4, 1, 5], [6, 3, 9], [8, 7, 15], [10, 0, 10]],
            ],
            [
                'name' => 'MUL',
                'domain' => 'arithmetic',
                'data' => [[1, 2, 2], [2, 3, 6], [3, 4, 12], [4, 5, 20], [5, 6, 30], [1, 3, 3], [2, 4, 8], [3, 2, 6], [4, 1, 4], [5, 0, 0]],
            ],
            [
                'name' => 'OR',
                'domain' => 'logic',
                'data' => [[0, 0, 0], [0, 1, 1], [1, 0, 1], [1, 1, 1], [0, 0, 0], [1, 0, 1], [0, 1, 1], [1, 1, 1], [0, 1, 1], [1, 0, 1]],
            ],
            [
                'name' => 'XOR',
                'domain' => 'logic',
                'data' => [[0, 0, 0], [0, 1, 1], [1, 0, 1], [1, 1, 0], [0, 0, 0], [1, 0, 1], [0, 1, 1], [1, 1, 0], [0, 1, 1], [1, 0, 1]],
            ],
            [
                'name' => 'SQUARE',
                'domain' => 'arithmetic',
                'data' => [[1, 1], [2, 4], [3, 9], [4, 16], [5, 25], [6, 36], [7, 49], [8, 64], [9, 81], [10, 100]],
            ],
            [
                'name' => 'SQRT',
                'domain' => 'arithmetic',
                'data' => [[0, 0], [1, 1], [4, 2], [9, 3], [16, 4], [25, 5], [36, 6], [49, 7], [64, 8], [81, 9]],
            ],
            [
                'name' => 'MAX',
                'domain' => 'arithmetic',
                'data' => [[0, 0, 0], [2, 3, 3], [5, 1, 5], [4, 4, 4], [1, 0, 1], [3, 2, 3], [0, 5, 5], [6, 0, 6], [2, 2, 2], [7, 3, 7]],
            ],
            [
                'name' => 'DIV',
                'domain' => 'arithmetic',
                'data' => [[6, 2, 3], [12, 3, 4], [20, 4, 5], [10, 2, 5], [8, 4, 2], [15, 3, 5], [24, 6, 4], [9, 3, 3], [16, 2, 8], [30, 5, 6]],
            ],
        ];
        $tasks = array_merge($tasks, $base);

        // Generated compose tasks
        $g = new Grammar();
        $grammarOps = $g->all();
        if (count($grammarOps) >= 2) {
            $count = 0;
            foreach ($grammarOps as $outer) {
                foreach ($grammarOps as $inner) {
                    if ($outer === $inner || $count >= 10) {
                        break 2;
                    }
                    if (! AtomRegistry::isUnary($outer)) {
                        continue;
                    }
                    $data = [];
                    for ($i = 0; $i < 6; $i++) {
                        $x = mt_rand(-10, 10);
                        $y = mt_rand(-10, 10);
                        $v1 = AtomRegistry::isBinary($inner)
                            ? AtomRegistry::apply($inner, (float) $x, (float) $y)
                            : AtomRegistry::apply($inner, (float) $x);
                        if ($v1 === null || is_nan($v1) || is_infinite($v1)) {
                            continue;
                        }
                        $v2 = AtomRegistry::apply($outer, $v1);
                        if ($v2 === null || is_nan($v2) || is_infinite($v2)) {
                            continue;
                        }
                        $data[] = [(float) $x, (float) $y, $v2];
                    }
                    if (count($data) >= 3) {
                        $tasks[] = [
                            'name' => "GEN_{$outer}_{$inner}",
                            'data' => $data,
                            'domain' => 'generated',
                        ];
                        $count++;
                    }
                }
            }
        }

        // Cloze tasks
        if ($this->sentenceRegistry && $this->corpusVocab && count($tasks) < 40) {
            $n = min($this->sentenceRegistry->count(), 50);
            for ($i = 0; $i < $n; $i++) {
                $s = $this->sentenceRegistry->get($i);
                if (! $s || count($s['token_ids']) < 3) {
                    continue;
                }
                foreach ($s['token_ids'] as $pos => $tid) {
                    $w = $this->corpusVocab->word($tid);
                    if (! $w || in_array($w, ['i', 'v', 'na', 's', 'ne', 'ili', 'no', 'a'])) {
                        continue;
                    }
                    $d = [[$i, $pos, $tid, 1.0]];
                    for ($j = 0; $j < 3; $j++) {
                        $r = mt_rand(1, $this->corpusVocab->size());
                        if ($r !== $tid) {
                            $d[] = [$i, $pos, $r, 0.0];
                        }
                    }
                    $tasks[] = [
                        'name' => "cloze_{$i}_{$pos}",
                        'data' => $d,
                        'domain' => 'cloze',
                    ];
                    break;
                }
            }
        }

        return array_merge($tasks, $this->foragedTasksGlobal);
    }

    // ═══ ТЕСТОВЫЕ МЕТОДЫ ═══

    public function tick(): array
    {
        return [
            'tasks_processed' => count($this->getTasks()),
            'discoveries' => 0,
        ];
    }
}
