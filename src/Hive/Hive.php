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

    /** @var Bee[] */
    private array $bees = [];

    private ?TaskRouter $taskRouter = null;

    private ?Bee $routedBee = null;

    private int $generation = 0;

    private int $spawnCount = 0;

    public function __construct(
        ?PlateauDetector $plateau = null,
        ?Forager $forager = null,
        ?int $maxTicks = null,
        ?string $logFile = null,
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
        $this->logFile = $logFile ?? $logDir . '/agenda.log';
    }

    // ═══ ЛОГГИРОВАНИЕ ═══

    private function log(string $msg): void
    {
        $line = '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
        echo $line;
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }

    public function getBees(): array
    {
        return $this->bees;
    }

    private static function jaccard(array $a, array $b): float
    {
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        return $union > 0 ? $intersection / $union : 0.0;
    }

    private function computeDiversity(): float
    {
        $alive = array_filter($this->bees, fn (Bee $b) => $b->isAlive());
        if (count($alive) < 2) return 0.0;

        $grammars = array_map(fn (Bee $b) => $b->grammar(), array_values($alive));
        $sum = 0.0;
        $pairs = 0;
        for ($i = 0; $i < count($grammars); $i++) {
            for ($j = $i + 1; $j < count($grammars); $j++) {
                $sum += self::jaccard($grammars[$i], $grammars[$j]);
                $pairs++;
            }
        }
        return $pairs > 0 ? 1.0 - ($sum / $pairs) : 0.0;
    }

    private function avgGrammarSize(): float
    {
        $alive = array_filter($this->bees, fn (Bee $b) => $b->isAlive());
        if (empty($alive)) return 0.0;
        $sum = array_sum(array_map(fn (Bee $b) => count($b->grammar()), $alive));
        return $sum / count($alive);
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

        // §0.6-бис: Data Bootstrap Acknowledgment
        $this->log('DATA_BOOTSTRAP_CORPUS: metrics.jsonl, Obsidian vault');
        $this->log('DATA_BOOTSTRAP_GRAMMAR: BASE_OPS + SEMANTIC_OPS');

        // §0.6: Bootstrap Phase — cold start with seed population
        if (empty($this->bees)) {
        $allOps = array_keys(Grammar::BASE_OPS);
        $semOps = Grammar::SEMANTIC_OPS;
        $available = array_merge($allOps, $semOps);

        // G₁ = baseline grammar B
        $g1 = $allOps;
        // G₂ = mutate(B) — retry until Jaccard < 1.0 with G₁
        $g2 = $g1;
        for ($retry = 0; $retry < 10; $retry++) {
            $g2 = GrammarMutator::mutate($allOps, $available);
            if (self::jaccard($g1, $g2) < 1.0) {
                break;
            }
        }
        if (self::jaccard($g1, $g2) >= 1.0) {
            throw new \RuntimeException('BOOTSTRAP: G₂ identical to G₁ after 10 retries');
        }
        // G₃ = mutate(mutate(B)) — pairwise Jaccard < 1.0 with both
        $g3 = $g2;
        for ($retry = 0; $retry < 10; $retry++) {
            $g3 = GrammarMutator::mutate($g2, $available);
            if (self::jaccard($g1, $g3) < 1.0 && self::jaccard($g2, $g3) < 1.0) {
                break;
            }
        }
        if (self::jaccard($g1, $g3) >= 1.0 || self::jaccard($g2, $g3) >= 1.0) {
            throw new \RuntimeException('BOOTSTRAP: G₃ not distinct from G₁/G₂ after 10 retries');
        }

        $this->bees = [
            new Bee($g1, 10.0),
            new Bee($g2, 10.0),
            new Bee($g3, 10.0),
        ];
        $this->log('BOOTSTRAP: 3 seed bees created');
        }

        // Create TaskRouter with the population
        if ($this->taskRouter === null && ! empty($this->bees)) {
            $this->taskRouter = new TaskRouter($this->bees, 10);
        }

        // §2.5: Log initial generation 0
        if ($this->generation === 0 && ! empty($this->bees)) {
            $this->log('GEN: 0 pop=' . count($this->bees) . ' (bootstrap)');
        }

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
            $foraged = $this->forager->scanWithAccumulator($this->foragerSources);
            if (! empty($foraged)) {
                $this->foragedTasksGlobal = $foraged;
                $this->log('Forager startup: ' . count($foraged) . ' tasks');
            }
        }

        // Retrospective validation
        $allTasks = $this->getTasks(skipGenerated: true);
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
            $foraged = $this->forager->scanWithAccumulator($this->foragerSources);
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

        // Route via TaskRouter if population exists, else random
        $this->routedBee = null;
        $task = $tasks[array_rand($tasks)];
        if ($this->taskRouter && ! empty($this->bees)) {
            $this->routedBee = $this->taskRouter->route($task);
        }

        if ($this->routedBee) {
            $beeIdx = array_search($this->routedBee, $this->bees, true);
            $this->log("ROUTE: task -> bee#{$beeIdx}");
        }

        // §2.1: Energy loop — tick all bees, log deaths
        foreach ($this->bees as $i => $bee) {
            if (! $bee->isAlive()) continue;
            $bee->tick();
            // §2.1: energy must not go negative — floor at 0
            if ($bee->energy() < 0.0) {
                $ref = new \ReflectionProperty(Bee::class, 'energy');
                $ref->setValue($bee, 0.0);
            }
            if (! $bee->isAlive()) {
                $this->log("DEATH: bee#{$i} energy={$bee->energy()}");
            }
        }

        // §2.2: Spawn loop — E≥15 → new bee with mutated grammar
        $allOps = array_keys(Grammar::BASE_OPS);
        $semOps = Grammar::SEMANTIC_OPS;
        $available = array_merge($allOps, $semOps);
        foreach ($this->bees as $parent) {
            if (! $parent->isAlive()) continue;
            $child = $parent->spawn($available);
            if ($child) {
                $this->bees[] = $child;
                $idx = count($this->bees) - 1;
                $this->spawnCount++;
                $this->log("SPAWN: bee#{$idx} from parent E={$parent->energy()}");

                // §2.5: Generation tracking — spawn_events ≥ N → new generation
                if ($this->spawnCount >= count($this->bees)) {
                    $this->generation++;
                    $this->spawnCount = 0;
                    $diversity = $this->computeDiversity();
                    $avgGrammarSize = $this->avgGrammarSize();
                    $this->log("GEN: {$this->generation} pop=" . count($this->bees)
                        . " diversity={$diversity} avg|G|={$avgGrammarSize}");
                }
            }
        }

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

        // Discover (skip semantic — KG-only)
        if (! $foundAny && $domain !== 'cloze' && $domain !== 'foraged_semantic') {
            $this->doDiscoverTick($task, $X, $y, $domain, $foundAny);
        }

        // Semantic facts → KG directly
        if ($domain === 'foraged_semantic' && ! empty($data[0]) && count($data[0]) >= 3) {
            [$s, $p, $o] = $data[0];
            try {
                Database::get()->prepare('INSERT OR IGNORE INTO knowledge_graph (subject,predicate,object,confidence) VALUES (?,?,?,0.3)')
                    ->execute([$s, $p, $o]);
            } catch (\PDOException) {
            }
        }

        // Text atom discovery from raw content (E1 feedback loop)
        if (! $foundAny && ! empty($task['content'])) {
            foreach (['match_label', 'preg_match'] as $textAtom) {
                $content = $task['content'];
                // Try common labels from content
                preg_match_all('/\b(\w+):/u', $content, $labels);
                foreach (array_unique($labels[1]) as $label) {
                    if (mb_strlen($label) < 2) {
                        continue;
                    }
                    $result = AtomRegistry::applyTextAtom($textAtom, $content, $label);
                    if ($result !== null && ! empty($result)) {
                        $composed = "{$textAtom}({$label})";
                        AtomRegistry::addDiscoveredTextAtom($textAtom, $label);
                        $this->log("🧬 {$composed} (TEXT ATOM)");
                        $foundAny = true;
                    }
                }
            }
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
            $this->log("INSUFFICIENT_DATA: {$task['name']} t=" . count($y) . " < tMin={$tMin}");
            return;
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
        // Record success on TaskRouter
        if ($this->taskRouter && $this->routedBee) {
            $this->taskRouter->recordOutcome($task, $this->routedBee, true);
        }
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
            $this->log('INSUFFICIENT_DATA: compose t=' . count($y) . " < tMin={$tMin}");
            return;
        }

        $candidates = AtomRegistry::discoverCompose($X, $y, $grammarOps);
        if (! empty($candidates)) {
            $validated = \BeeSwarm\Validation\LawValidator::validate($candidates, $X, $y);
            foreach ($validated as $c) {
                $this->recordDiscovery($c, [
                    'name' => $c['atom'],
                ], $domain, $foundAny);
                if (! in_array($c['atom'], $grammarOps)) {
                    $g->add($c['atom'], 'auto-compose');
                }
            }
        }
    }

    // ═══ ЗАДАЧИ ═══

    private function getTasks(bool $skipGenerated = false): array
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

        // Generated compose tasks (disabled on plateau — HONEST_CRITERIA §1.5)
        if ($skipGenerated || $this->plateau->isPlateau()) {
            return array_merge($tasks, $this->foragedTasksGlobal);
        }

        srand(42); // deterministic seed for reproducible GEN_ data
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
