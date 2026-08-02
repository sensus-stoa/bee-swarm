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

    private int $generation = 0;

    private int $spawnCount = 0;

    private int $generationStartPop = 0;

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
        if ($this->generation === 0 && ! empty($this->bees)) {
            $this->generationStartPop = count($this->bees);
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
            if (! getenv('FORAGER_SOURCES')) {
                $this->log('WARNING: FORAGER_SOURCES not set, using default directories');
            }
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

        // §2.2: Spawn loop — E≥15 → new bee with mutated grammar
        $allOps = array_keys(Grammar::BASE_OPS);
        $semOps = Grammar::SEMANTIC_OPS;
        $available = array_merge($allOps, $semOps);
        foreach ($this->bees as $parent) {
            if (! $parent->isAlive()) {
                continue;
            }
            $child = $parent->spawn($available);
            if ($child) {
                $this->bees[] = $child;
                $idx = count($this->bees) - 1;
                $this->spawnCount++;
                $this->log("SPAWN: bee#{$idx} from parent E={$parent->energy()}");
                // §2.3: логировать грамматики для verify_1_3
                $this->log('GRAMMAR_SPAWN parent=' . array_search($parent, $this->bees, true)
                    . ' child=' . $idx
                    . ' parent_size=' . count($parent->grammar())
                    . ' child_size=' . count($child->grammar()));

                // §2.5: Generation tracking — spawn_events ≥ generation_start_population
                if ($this->spawnCount >= $this->generationStartPop) {
                    $this->generation++;
                    $this->spawnCount = 0;
                    $this->generationStartPop = count($this->bees);
                    $diversity = $this->computeDiversity();
                    $avgGrammarSize = $this->avgGrammarSize();
                    $uniqueGrammars = count(array_unique(array_map(
                        fn (Bee $b) => implode(',', $b->grammar()),
                        array_filter($this->bees, fn (Bee $b) => $b->isAlive())
                    )));
                    $this->log("GEN: {$this->generation} pop=" . count($this->bees)
                        . " unique={$uniqueGrammars} diversity={$diversity} avg|G|={$avgGrammarSize}");
                }
            }
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
                $this->lastAnswerFormula = $bestAtom;
                $this->plateau->tick(true);
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
        $candidates = $engine->discover($X, $y, $grammarOps, $cvTrainMax, $colLabels);

        foreach ($candidates as $d) {
            $this->recordDiscovery($d, $task, $domain, $foundAny);
        }

        // Information reward — intrinsic value of search
        $this->routedBee->rewardInformation();
    }

    private function recordDiscovery(array $d, array $task, string $domain, bool &$foundAny): void
    {
        $result = $this->recordKeeper->record($d, $task, $domain);
        if (! $result['inserted']) { $this->log("DUPLICATE: {$d['atom']} [{$domain}]"); return; }
        if (! empty($result['cross_domains'])) { $this->log("CROSS_DOMAIN: {$d['atom']}"); }
        $foundAny = true;
        if ($this->routedBee && $this->routedBee->isAlive()) $this->routedBee->addToGrammar($d['atom']);
        if ($this->taskRouter && $this->routedBee) '::' . $task['name'] . '::' . $d['atom'];
        if (isset($this->knownLaws[$key])) {
            $this->log("DUPLICATE: {$d['atom']} [{$domain}]");
            return;
        }
        $this->knownLaws[$key] = true;
        $foundAny = true;
        // §2.3 изоляция: добавить атом только в per-bee грамматику.
        // Общая grammar_ops — read-only архив (Phase 5b).
        if ($this->routedBee && $this->routedBee->isAlive()) {
            $this->routedBee->addToGrammar($d['atom']);
        }

        // S1.12 Phase 2: Cross-domain signal — атом на ≥2 доменах
        if (($d['mode'] ?? '') === 'compose') {
            $otherDomains = Database::get()->prepare(
                'SELECT DISTINCT domain FROM laws WHERE formula=? AND domain!=?'
            );
            $otherDomains->execute([$d['atom'], $domain]);
            $crossDomains = $otherDomains->fetchAll(\PDO::FETCH_COLUMN);
            if (count($crossDomains) > 0) {
                $this->log("CROSS_DOMAIN: {$d['atom']} now in [" . implode(',', array_merge([$domain], $crossDomains)) . ']');
            }
        }
        Database::get()->prepare(
            'INSERT OR IGNORE INTO laws (name,formula,cv,domain,source_path,content_sample,col_labels) VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $task['name'], $d['atom'], $d['cv'], $domain,
            $task['source_path'] ?? '',
            mb_substr($task['content'] ?? '', 0, 200),
            json_encode($task['col_labels'] ?? []),
        ]);
        // Record success on TaskRouter
        if ($this->taskRouter && $this->routedBee) {
            $this->taskRouter->recordOutcome($task, $this->routedBee, true);
        }
        $cvFmt = number_format($d['cv'], 4);
        $srcHint = isset($task['source_path']) ? ' src=' . basename($task['source_path']) : '';
        $this->log("🔍 {$task['name']} -> {$d['atom']} (CV={$cvFmt}) [{$domain}]{$srcHint}");
        // Сохраняем формулу для overlap-трекинга
        $this->lastAnswerFormula = $d['atom'];
        if ($this->routedBee) {
            $this->routedBee->rewardDiscovery();
        }
        $this->plateau->tick(true);
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
        $dreamTasks = IdleDreamer::prepareTasks($tasks);

        if (empty($dreamTasks)) {
            usleep(100_000);
            return;
        }

        $dreamer = new IdleDreamer();
        $fp = $this->taskRouter ? $this->taskRouter->fingerprint($tasks[0]) : 'idle';
        $epsilon = $this->getEpsilon($fp) ?? 0.01;
        // §2.3: per-bee грамматика + BASE_OPS для idle dreaming
        $grammarOps = array_merge(Grammar::baseOpNames(), $this->routedBee->grammar());
        $result = $dreamer->dream($dreamTasks, $epsilon, $grammarOps);

        if ($result !== null) {
            $foundAny = false; // локальный флаг для recordDiscovery
            $task = [
                'name' => $result['task_name'] ?? $result['atom'],
            ];
            $domain = $result['domain'] ?? 'dream';
            $this->recordDiscovery($result, $task, $domain, $foundAny);
            if ($foundAny) {
                $this->log("DREAM: {$result['atom']} [{$domain}]");
            }
        } else {
            usleep(100_000); // короткий сон после безрезультатного dreaming
        }
    }

    private function getTasks(bool $skipGenerated = false): array
    {
        static $tasks = null;
        static $lastRegen = 0;

        if ($tasks !== null && ($this->tick - $lastRegen) < 100) {
            return $this->filterInsufficient(array_merge($tasks, $this->foragedTasksGlobal));
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

        // D15: cross-pair now handled by TaskGenerator
        $crossTasks = [];

        // Generated compose tasks (disabled on plateau — HONEST_CRITERIA §1.5)
        if ($skipGenerated || $this->plateau->isPlateau()) {
            $generator = new TaskGenerator();
            return $this->filterInsufficient($generator->generate($this->foragedTasksGlobal, $crossTasks));
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
        return $filtered;
    }

    // ═══ ТЕСТОВЫЕ МЕТОДЫ ═══

    public function tick(): array
    {
        return [
            'tasks_processed' => count($this->getTasks()),
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
