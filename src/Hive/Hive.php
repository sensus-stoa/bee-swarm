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
use BeeSwarm\Validation\LawValidator;
use BeeSwarm\Validation\NullCalibrator;

/**
 * Hive — главный цикл роя.
 * agenda.php → (new Hive(...))->run()
 */
class Hive
{
    private PlateauDetector $plateau;
    private RecordKeeper $recordKeeper;
    private SpawnManager $spawnManager;

    private Forager $forager;

    private array $foragerSources;

    private int $foragerScanInterval = 100;

    private string $logFile;

    private array $log = [];

    private int $tick = 0;


    private const MIN_DATA_POINTS = 10;

    /**
     * @var array<string, float> fingerprint to epsilon_null (V0: Runtime Null-Calibration)
     */
    private array $epsilonCache = [];

    private ?CorpusVocabulary $corpusVocab = null;

    private ?SentenceRegistry $sentenceRegistry = null;

    private array $foragedTasksGlobal = [];

    private ?int $maxTicks;

    /**
     * @var Bee[]
     */
    private array $bees = [];

    private ?TaskRouter $taskRouter = null;

    private ?Bee $routedBee = null;

    private ?OverlapTracker $overlapTracker = null;

    /** Последний ответ (формула) для overlap-трекинга. */
    private ?string $lastAnswerFormula = null;




    public function __construct(
        ?PlateauDetector $plateau = null,
        ?Forager $forager = null,
        ?int $maxTicks = null,
        ?string $logFile = null,
    ) {
        $this->plateau = $plateau ?? new PlateauDetector(50);
        $this->forager = $forager ?? new Forager();
        $this->recordKeeper = new RecordKeeper();
        $this->spawnManager = new SpawnManager();
        $this->maxTicks = $maxTicks;

        $sources = getenv('FORAGER_SOURCES');
        if ($sources) {
            $this->foragerSources = array_fill_keys(explode(':', $sources), 1);
        } else {
            // Universal fallback: scan user's home directory
            $home = getenv('HOME') ?: '/home/' . get_current_user();
            $this->foragerSources = [];
            foreach (['Documents', 'Desktop', 'Downloads'] as $dir) {
                $path = $home . '/' . $dir;
                if (is_dir($path)) {
                    $this->foragerSources[$path] = 1;
                }
            }
            if (empty($this->foragerSources)) {
                $this->foragerSources = [
                    $home => 1,
                ];
            }
        }

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
        if (count($alive) < 2) {
            return 0.0;
        }

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
        if (empty($alive)) {
            return 0.0;
        }
        $sum = array_sum(array_map(fn (Bee $b) => count($b->grammar()), $alive));
        return $sum / count($alive);
    }

    // ═══ ГЛАВНЫЙ ЦИКЛ ═══

    public function run(): int
    {
        $this->bootstrap();

        // maxTicks=0: bootstrap только, без тиков (детерминированная проверка E₀)
        if ($this->maxTicks === 0) {
            return 0;
        }

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

        $this->log('SESSION_START: ' . date('Y-m-d H:i:s') . ' bees=' . count($this->bees ?: []) . ' grammar=' . (new Grammar())->count() . ' ops');

        // §0.6-бис: Data Bootstrap Acknowledgment
        $this->log('DATA_BOOTSTRAP_CORPUS: metrics.jsonl, Obsidian vault');
        $this->log('DATA_BOOTSTRAP_GRAMMAR: BASE_OPS + SEMANTIC_OPS');

        // §0.6: Bootstrap Phase — cold start with seed population
        if (empty($this->bees)) {
            $bm = new BootstrapManager();
            $this->bees = $bm->createSeedBees();
            $this->log('BOOTSTRAP: 3 seed bees created');
        }

        // Overlap tracker (§1.8)
        if ($this->overlapTracker === null) {
            $this->overlapTracker = new OverlapTracker();
        }

        // Create TaskRouter with the population
        if ($this->taskRouter === null && ! empty($this->bees)) {
            $this->taskRouter = new TaskRouter($this->bees, 10);
        }

        // §2.5: Log initial generation 0
        if ($this->spawnManager->getGeneration() === 0 && ! empty($this->bees)) {
            $this->log('GEN: 0 pop=' . count($this->bees) . ' (bootstrap)');
        }

        // Enable held-out validation
        AtomRegistry::setHeldoutEnabled(true);

        // Preload known laws
        $known = $this->recordKeeper->preloadKnown();
        $this->log('Preloaded ' . $known . ' known laws from DB');

        // Forager startup scan
        if (! empty($this->foragerSources)) {
            if (! getenv('FORAGER_SOURCES')) {
                $this->log('WARNING: FORAGER_SOURCES not set, using default directories');
            }
            $foraged = $this->forager->scanWithAccumulator($this->foragerSources);
            if (! empty($foraged)) {
                $this->foragedTasksGlobal = $foraged;
                $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
                $peak = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
                $this->log("Forager startup: " . count($foraged) . " tasks, mem={$mem}MB peak={$peak}MB");
            }
        }

        // Retrospective validation
        $this->log("MEM_PRE_RETRO: mem=" . round(memory_get_usage(true)/1024/1024, 1) . "MB peak=" . round(memory_get_peak_usage(true)/1024/1024, 1) . "MB");
        $allTasks = $this->getTasks(skipGenerated: true);
        $this->log("MEM_POST_RETRO: mem=" . round(memory_get_usage(true)/1024/1024, 1) . "MB peak=" . round(memory_get_peak_usage(true)/1024/1024, 1) . "MB tasks=" . count($allTasks));
        if (! empty($allTasks)) {
            $retro = AtomRegistry::retrospectiveValidate($allTasks);
            if (count($retro['overfit']) > 0) {
                $this->log('RETRO_OVERFIT: removed ' . count($retro['overfit']) . ' laws');
            }
            $this->log('Retrospective: ' . count($retro['passed']) . ' passed, '
                . count($retro['overfit']) . ' overfit removed');
        }

        // Corpus
        $corpusDirs = getenv('CORPUS_DIRS');
        if ($corpusDirs) {
            $dirs = explode(':', $corpusDirs);
        } else {
            // Fallback: домашняя директория пользователя
            $dirs = [getenv('HOME') ?: '/home/' . get_current_user()];
        }
        $dirs = array_filter($dirs, 'is_dir');
        if (! empty($dirs)) {
            $this->corpusVocab = new CorpusVocabulary($dirs);
            $this->sentenceRegistry = new SentenceRegistry($dirs, $this->corpusVocab);
            $this->log("Corpus: {$this->corpusVocab->size()} words, {$this->sentenceRegistry->count()} sentences");
        }
    }

    // ═══ ОДИН ТИК ═══

    private function doTick(): void
    {
        $this->lastAnswerFormula = null;

        // Memory monitor every 100 ticks
        if ($this->tick % 100 === 0) {
            $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
            $peak = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
            $this->log("MEM: tick={$this->tick} mem={$mem}MB peak={$peak}MB tasks=" . count($this->foragedTasksGlobal));
        }

        // CPU guard
        $load = sys_getloadavg();
        $nproc = max(1, (int) (shell_exec('nproc 2>/dev/null') ?: 1));
        if ($load[0] / $nproc > 0.7) {
            usleep(2_000_000);
            return;
        }

        // Forager scan
        $hasNewForagerData = false;
        if (
            ! empty($this->foragerSources)
            && ($this->tick % $this->foragerScanInterval === 0 || $this->plateau->justEnteredPlateau())
        ) {
            $foraged = $this->forager->scanWithAccumulator($this->foragerSources);
            if (! empty($foraged)) {
                $this->foragedTasksGlobal = array_merge($this->foragedTasksGlobal, $foraged);
                if ($this->forager->hasNewContent()) {
                    $hasNewForagerData = true;
                    $this->log('FORAGER_NEW_TASK: ' . $this->forager->getNewTaskCount()
                        . ' tasks, ' . $this->forager->getNewDomainCount() . ' domains');
                    $this->plateau->wakeup();
                    $this->forager->markContentConsumed();
                }
            }
        }

        $tasks = $this->getTasks();

        // One-time log after first task generation (cross-pair happens here)
        static $firstTasksLogged = false;
        if (! $firstTasksLogged && ! empty($tasks)) {
            $firstTasksLogged = true;
            $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
            $peak = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
            $this->log("MEM_FIRST_TASKS: " . count($tasks) . " tasks, mem={$mem}MB peak={$peak}MB");
        }

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
        $task = $this->weightedPick($tasks);
        if ($this->taskRouter && ! empty($this->bees)) {
            $this->routedBee = $this->taskRouter->route($task);
        }

        if ($this->routedBee) {
            $beeIdx = array_search($this->routedBee, $this->bees, true);
            $this->log("ROUTE: task -> bee#{$beeIdx}");

            // §S1.7-NOVELTY: reward bee for exploring new fingerprint
            static $seenFingerprints = [];
            $fp = $this->taskRouter->fingerprint($task);
            if (! isset($seenFingerprints[$fp])) {
                $seenFingerprints[$fp] = true;
                $this->routedBee->rewardNovelty();
                $this->log("NOVELTY: bee#{$beeIdx} new fingerprint");
            }
        }

        // §2.1: Energy loop — tick all bees, log deaths
        foreach ($this->bees as $i => $bee) {
            if (! $bee->isAlive()) {
                continue;
            }
            $bee->tick();
            // §2.1: energy must not go negative — floor at 0
            if ($bee->energy() < 0.0) {
                $ref = new \ReflectionProperty(Bee::class, 'energy');
                $ref->setValue($bee, 0.0);
            }
            // §S1.5-HUNGER: голодная мутация при E<5
            if ($bee->isAlive() && $bee->energy() < 5.0) {
                $allOps = array_keys(Grammar::BASE_OPS);
                $semOps = Grammar::SEMANTIC_OPS;
                $available = array_merge($allOps, $semOps);
                $oldGrammar = $bee->grammar();
                $bee->hungerMutate($available);
                if ($bee->grammar() !== $oldGrammar) {
                    $this->log("HUNGER_MUTATE: bee#{$i} E={$bee->energy()}");
                }
            }
            if (! $bee->isAlive()) {
                $this->log("DEATH: bee#{$i} energy={$bee->energy()}");
            }
        }

        // D17: SpawnManager handles spawning + generation tracking
        $allOps = array_merge(array_keys(Grammar::BASE_OPS), Grammar::SEMANTIC_OPS);
        $spawned = $this->spawnManager->trySpawn($this->bees, $allOps);

        // S1.2 Phase 4: Gap-Triggered Spawn — размножение при долгом PLATEAU
        $gapSpawned = $this->spawnManager->tryGapSpawn(
            $this->bees, $allOps,
            $this->plateau->isPlateau(),
            $this->plateau->getConsecutiveNoDiscovery(),
            $hasNewForagerData,
            $this->plateau->getThreshold(),
        );
        if ($gapSpawned > 0) {
            $trigger = $hasNewForagerData ? 'new_data' : 'fallback';
            $this->log("GAP_SPAWN: pop=" . count($this->bees) . " trigger={$trigger}");
            $spawned += $gapSpawned;
        }

        if ($spawned > 0) {
            $diversity = SpawnManager::computeDiversity($this->bees);
            $avgG = SpawnManager::avgGrammarSize($this->bees);
            $uniqueCount = count(array_unique(array_map(
                fn (Bee $b) => implode(',', $b->grammar()),
                array_filter($this->bees, fn (Bee $b) => $b->isAlive())
            )));
            $this->log("GEN: {$this->spawnManager->getGeneration()} pop=" . count($this->bees)
                . " unique={$uniqueCount} diversity={$diversity} avg|G|={$avgG}");
        }

        $data = $task['data'] ?? [];
        $domain = $task['domain'] ?? 'unknown';

        // Skip semantic tasks early — they don't have numeric data
        if ($domain === 'foraged_semantic' || empty($data)) {
            if ($domain === 'foraged_semantic' && ! empty($data[0]) && count($data[0]) >= 3) {
                [$s, $p, $o] = $data[0];
                try {
                    Database::get()->prepare('INSERT OR IGNORE INTO knowledge_graph (subject,predicate,object,confidence) VALUES (?,?,?,0.3)')
                        ->execute([$s, $p, $o]);
                } catch (\PDOException) {
                }
            }
            usleep(500_000);
            return;
        }

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
                        $this->lastAnswerFormula = $composed;
                        $foundAny = true;
                    }
                }
            }
        }

        // D14: Compose now handled by DiscoveryEngine
        if (! $foundAny) {
            // §2.5-децим: idle dreaming — cross-domain compose вместо пассивного сна
            $this->idleDreamTick();
        }

        if (count($this->log) > 200) {
            $this->log = array_slice($this->log, -100);
        }
        $this->plateau->tick($foundAny);
        if ($this->plateau->justEnteredPlateau()) {
            $this->log('🏔️ PLATEAU');
        }

        // §1.8 Overlap: записать попытку пчелы на задаче
        if ($this->overlapTracker && $this->routedBee) {
            $beeIdx = array_search($this->routedBee, $this->bees, true);
            if ($beeIdx !== false) {
                $taskName = $task['name'] ?? 'unknown';
                $this->overlapTracker->recordTaskAttempt($taskName, $beeIdx, $this->lastAnswerFormula);
            }
        }

        usleep($this->plateau->getSleepUs());
    }

    private function doClozeTick(array $task, array $data, string $domain, bool &$foundAny): void
    {
        $engine = new ClozeEngine();
        $engine->setSentenceRegistry($this->sentenceRegistry);
        $allOps = (new Grammar())->all();
        $best = $engine->findBestAtom($data, $allOps);
        if ($best !== null) {
            $result = $this->recordKeeper->record(['atom' => $best['atom'], 'cv' => $best['error'], 'mode' => 'cloze'], $task, $domain);
            if ($result['inserted']) {
                $foundAny = true;
                $this->log("📖 {$task['name']} -> {$best['atom']} (err=" . round($best['error'], 3) . ')');
            }
        }
    }

    private function doDiscoverTick(array $task, array $X, array $y, string $domain, bool &$foundAny): void
    {
        if ($this->routedBee === null || ! $this->routedBee->isAlive()) {
            return;
        }

        // Sufficiency check
        $nFeat = count($X[0] ?? []);
        $tMin = max(10, $nFeat * 5);
        if (count($y) < $tMin) {
            $this->log("INSUFFICIENT_DATA: {$task['name']} t=" . count($y) . " < tMin={$tMin}");
            return;
        }

        // Null-calibration
        $fp = $this->taskRouter
            ? $this->taskRouter->fingerprint($task)
            : ($domain . ':' . $nFeat . 'c:' . count($y));
        if ($this->getEpsilon($fp) === null) {
            try {
                $calibGrammar = new Grammar();
                $calibGrammar->restrictTo(array_keys(Grammar::BASE_OPS));
                $this->calibrateEpsilon($fp, $X, $y, $calibGrammar);
            } catch (\Throwable $e) {
                $this->log("CALIBRATE_FAILED: fp={$fp} " . $e->getMessage());
            }
        }
        $cvTrainMax = $this->getEpsilon($fp) ?? 0.01;

        // D14 Wiring: engine-based discovery (replaces inline Search + Heldout + Compose)
        if ($this->routedBee) {
            $this->routedBee->chargeSearch();
        }
        $grammarOps = array_merge(Grammar::baseOpNames(), $this->routedBee->grammar());
        $colLabels = $task['col_labels'] ?? null;
        $engine = new DiscoveryEngine();
        [$candidates, $bestCv] = $engine->discover($X, $y, $grammarOps, $cvTrainMax, $colLabels);

        foreach ($candidates as $d) {
            $this->recordDiscovery($d, $task, $domain, $foundAny);
        }

        // Information reward — intrinsic value of search
        $this->routedBee->rewardInformation();

        // S1.6-GRADIENT: signal reward when no discovery but best CV in signal zone
        if (! $foundAny && $bestCv > $cvTrainMax && $bestCv < 9.0) {
            $nullFloor = NullCalibrator::getNullFloor($X, $y, Grammar::fromOps($grammarOps));
            if ($bestCv <= $nullFloor) {
                $this->routedBee->rewardSignal();
                $this->log("SIGNAL: best_CV=" . round($bestCv, 4) . " zone=({$cvTrainMax}..{$nullFloor}]");
            }
        }
    }

    private function recordDiscovery(array $d, array $task, string $domain, bool &$foundAny): void
    {
        $result = $this->recordKeeper->record($d, $task, $domain);
        if (! $result['inserted']) { $this->log("DUPLICATE: {$d['atom']} [{$domain}]"); return; }
        if (! empty($result['cross_domains'])) { $this->log("CROSS_DOMAIN: {$d['atom']}"); }
        $foundAny = true;
        if ($this->routedBee && $this->routedBee->isAlive()) $this->routedBee->addToGrammar($d['atom']);
        if ($this->taskRouter && $this->routedBee) {
            $this->taskRouter->recordOutcome($task, $this->routedBee, true);
        }
        $this->lastAnswerFormula = $d['atom'];
        if ($this->routedBee) {
            $this->routedBee->rewardDiscovery();
        }
        $this->plateau->tick(true);

        $cvFmt = number_format($d['cv'], 4);
        $srcHint = isset($task['source_path']) ? ' src=' . basename($task['source_path']) : '';
        $this->log("🔍 {$task['name']} -> {$d['atom']} (CV={$cvFmt}) [{$domain}]{$srcHint}");
    }

    /**
     * §2.5-децим: Idle-Time Dreaming — кросс-доменный compose в простое.
     *
     * Вызывается когда foundAny=false за тик. Пытается найти закон через
     * расширенный compose (все grammar ops) на всех доступных задачах.
     */
    private function idleDreamTick(): void
    {
        // Требуется живая пчела для вознаграждения
        if ($this->routedBee === null || ! $this->routedBee->isAlive()) {
            usleep(100_000);
            return;
        }
        $tasks = $this->getTasks(skipGenerated: true);
        $grammarOps = array_merge(Grammar::baseOpNames(), $this->routedBee->grammar());
        $fp = $this->taskRouter ? $this->taskRouter->fingerprint($tasks[0] ?? []) : 'idle';
        $epsilon = $this->getEpsilon($fp) ?? 0.01;
        $result = IdleDreamer::tick($tasks, $grammarOps, $epsilon);
        if ($result !== null) {
            $foundAny = false;
            $this->recordDiscovery($result, ['name' => $result['task_name'] ?? $result['atom']], $result['domain'] ?? 'dream', $foundAny);
            if ($foundAny) $this->log("DREAM: {$result['atom']} [{$result['domain']}]");
        } else {
            usleep(100_000);
        }
    }

    private function getTasks(bool $skipGenerated = false): array
    {
        $tasks = [];
        // D15: base + metrics + compose tasks generated by TaskGenerator
        $gen = new TaskGenerator();
        $genTasks = $gen->createComposeTasks();
        $tasks = array_merge($tasks, $genTasks);


        // D15: cross-pair now handled by TaskGenerator
        $crossTasks = [];

        // Generated compose tasks (disabled on plateau — HONEST_CRITERIA §1.5)
        if ($skipGenerated || $this->plateau->isPlateau()) {
            $generator = new TaskGenerator();
            return $this->filterInsufficient($generator->generate($this->foragedTasksGlobal, $crossTasks));
        }

        // Save & restore RNG — srand(42) for GEN_ must not poison array_rand()
        $rngGuard = \BeeSwarm\Infra\RngIsolation::deterministicSeed(42);
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
        $rngGuard->restore(); // Restore random RNG for array_rand at line 333

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

        // D15: делегировать генерацию TaskGenerator
        $generator = new TaskGenerator();
        $tasks = $generator->generate($this->foragedTasksGlobal, $crossTasks);

        return $this->filterInsufficient($tasks);
    }

    /**
     * D12: Отфильтровать таски с недостаточными данными.
     * Вычисляет tMin = max(10, nFeat × 5) для каждого таска.
     * Таски без ключа 'data' (text/semantic) пропускаются.
     */
    /**
     * S1.6: Weighted task selection — nFeat=1 чаще nFeat=N.
     */
    private function weightedPick(array $tasks): array
    {
        if (empty($tasks)) {
            throw new \InvalidArgumentException('No tasks');
        }
        if (count($tasks) === 1) {
            return $tasks[0];
        }

        $weights = [];
        $total = 0.0;
        foreach ($tasks as $i => $t) {
            $nFeat = isset($t['data'][0]) && is_array($t['data'][0])
                ? max(1, count($t['data'][0]) - 1) : 1;
            $w = 1.0 / $nFeat;
            $weights[] = $total + $w;
            $total += $w;
        }

        $r = mt_rand() / mt_getrandmax() * $total;
        foreach ($weights as $i => $boundary) {
            if ($r <= $boundary) {
                return $tasks[$i];
            }
        }

        return $tasks[array_key_last($tasks)];
    }

    private function filterInsufficient(array $tasks): array
    {
        // Дедупликация: для каждого имени таска — версия с максимумом данных
        $best = [];
        foreach ($tasks as $t) {
            $name = $t['name'] ?? '';
            if ($name === '') {
                continue;
            }
            $cnt = isset($t['data']) ? count($t['data']) : PHP_INT_MAX;
            if (!isset($best[$name]) || $cnt > $best[$name][1]) {
                $best[$name] = [$t, $cnt];
            }
        }

        $filtered = [];
        $skipped = 0;
        $passedCounts = [];
        foreach ($best as $name => [$t, $cnt]) {
            if (!isset($t['data'])) {
                $filtered[] = $t;  // text/semantic — не фильтруем
                continue;
            }
            $nFeat = is_array($t['data'][0] ?? null) ? max(1, count($t['data'][0]) - 1) : 1;
            $tMin = max(self::MIN_DATA_POINTS, $nFeat * 5);
            if ($cnt >= $tMin) {
                $filtered[] = $t;
                $passedCounts[] = "{$name}({$cnt})";
            } else {
                $this->log("INSUFFICIENT_FILTERED: {$name} t={$cnt} < tMin={$tMin}");
                $skipped++;
            }
        }
        if ($skipped > 0) {
            $this->log("PRE_FILTER: skipped {$skipped} insufficient, passed " . count($passedCounts));
        }

        // E1-FIX Phase 4b: узкие задачи (меньше колонок) → выше шанс открытия → в начало
        usort($filtered, function (array $a, array $b): int {
            $aFeat = isset($a['data'][0]) && is_array($a['data'][0]) ? count($a['data'][0]) - 1 : 999;
            $bFeat = isset($b['data'][0]) && is_array($b['data'][0]) ? count($b['data'][0]) - 1 : 999;
            return $aFeat <=> $bFeat;
        });

        return $filtered;
    }

    // ═══ ТЕСТОВЫЕ МЕТОДЫ ═══

    public function tick(): array
    {
        $tasks = $this->getTasks();
        return [
            'tasks_processed' => count($tasks),
            'discoveries' => 0,
        ];
    }

    // ═══ V0: NULL-CALIBRATION ═══

    /**
     * Calibrate epsilon_null for a structural task fingerprint.
     */
    public function calibrateEpsilon(string $fp, array $X, array $y, Grammar $grammar, int $nPerms = 100): float
    {
        if (! isset($this->epsilonCache[$fp])) {
            $this->epsilonCache[$fp] = NullCalibrator::calibrate($X, $y, $grammar, $nPerms);
            $this->log("CALIBRATE: fp={$fp} epsilon={$this->epsilonCache[$fp]}");
        }
        return $this->epsilonCache[$fp];
    }

    /**
     * Retrieve cached epsilon_null for fingerprint, or null if not calibrated.
     */
    public function getEpsilon(string $fp): ?float
    {
        return $this->epsilonCache[$fp] ?? null;
    }

    /**
     * S1.11 Phase 2: Запрос законов с source-метаданными.
     * @return array<int, array{name: string, formula: string, cv: float, domain: string, source_path: string, content_sample: string}>
     */
    public function lawsWithSources(string $domain): array
    {
        $stmt = Database::get()->prepare(
            'SELECT name, formula, cv, domain, source_path, content_sample FROM laws WHERE domain = ? ORDER BY rowid DESC LIMIT 50'
        );
        $stmt->execute([$domain]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
