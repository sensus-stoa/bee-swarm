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
    private array $lastCandidates = []; // REUSE-TRACKING (08.08)
    private int $lifetimeAccum = 0; // LIFETIME-METRIC (07.08)
    private int $lifetimeCount = 0;


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
    /** MEMORY-GUARD (аудит 05.08): порог MB, default 256. 0 = выключен. */
    private int $memoryGuardMb = 256;
    /** D_RATIO телеметрия (аудит 05.08 §2.5.8): интервал тиков, default 500 */
    private int $dRatioInterval = 500;
    /** D_ACT кольцевой буфер (аудит 05.08): окно, default 500; zero-allocation */
    private int $dActWindow = 500;
    private int $dActInterval = 100;
    private \SplFixedArray $dActBuffer;
    private int $dActHead = 0;
    /** Последний размер пула задач (wakeup-детектор, только РОСТ будит плато) */
    private int $lastTaskCount = 0;

    private ?TaskRouter $taskRouter = null;

    private ?Bee $routedBee = null;

    private ?OverlapTracker $overlapTracker = null;

    /** SPAWN-POOL (27.08): пул рецептов-потомков (genotype). */
    private DormantPool $dormantPool;

    /** SPAWN-POOL Фаза C: сколько поколений линия прожила без прогресса. */
    private array $lineageProgress = [];

    /** Фаза C: стартовая энергия линии при рождении (бонус ≠ прогресс). */
    private array $lineageEnergyBaseline = [];

    /** Фаза C (rev): монотонный счётчик линий — защита от коллапса lineageId. */
    private int $lineageSeq = 0;

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

    /**
     * MEMORY-GUARD (аудит 05.08): инъекция порога для тестов.
     */
    public function setMemoryGuardMb(int $mb): self
    {
        $this->memoryGuardMb = $mb;

        return $this;
    }

    /**
     * D_RATIO телеметрия (аудит 05.08 §2.5.8): инъекция интервала для тестов.
     */
    public function setDRatioInterval(int $ticks): self
    {
        $this->dRatioInterval = $ticks;

        return $this;
    }

    /**
     * D_ACT (аудит 05.08): инъекция окна буфера для тестов.
     */
    public function setDActivityWindow(int $window): self
    {
        $this->dActWindow = $window;

        return $this;
    }

    /**
     * D_ACT (аудит 05.08): инъекция интервала лога для тестов.
     */
    public function setDActivityInterval(int $ticks): self
    {
        $this->dActInterval = $ticks;

        return $this;
    }

    /**
     * D_ACT: запись тика в кольцевой буфер (zero-allocation, head-указатель).
     * Событие = mutation (спавн) | overfit (held-out провал) | plateau_exit.
     */
    private function recordDActivity(bool $event): void
    {
        if ($this->dActWindow < 1) {
            return; // CONCERNS (deleg_294903fa): window<1 → DivisionByZeroError
        }

        if (! isset($this->dActBuffer)) {
            $this->dActBuffer = new \SplFixedArray($this->dActWindow);
        } elseif ($this->dActBuffer->getSize() !== $this->dActWindow) {
            // CONCERNS 05.08: SplFixedArray не resize — пересоздаём при смене окна
            $this->dActBuffer = new \SplFixedArray($this->dActWindow);
            $this->dActHead = 0;
        }
        $this->dActBuffer[$this->dActHead] = $event ? 1 : 0;
        $this->dActHead = ($this->dActHead + 1) % $this->dActWindow;
    }

    /**
     * D_ACT: лог скользящей доли активных тиков.
     */
    private function logDActivity(): void
    {
        $events = 0;
        $n = min($this->dActWindow, $this->tick);
        for ($i = 0; $i < $n; $i++) {
            $events += $this->dActBuffer[$i] ?? 0;
        }
        $d = $n > 0 ? $events / $n : 0.0;
        $this->log("D_ACT: win={$this->dActWindow} events={$events} D=" . number_format($d, 3));
    }

    /**
     * D_RATIO: лог разнообразия грамматик (термометр §2.5.8).
     * Коридор: D ∈ [0.1, 0.5] здоровье; <0.1 кристалл; >0.5 хаос.
     */
    private function logDRatioSnapshot(): void
    {
        $alive = array_filter($this->bees, fn (Bee $b): bool => $b->isAlive());
        $pop = count($alive);
        if ($pop === 0) {
            $this->log("D_RATIO: win={$this->dRatioInterval} D=0.000 pop=0 zone=CRYSTAL");

            return;
        }
        $d = SpawnManager::computeDiversity(array_values($alive));
        // CONCERNS 05.08: коридор [0.1,0.5] валиден при N≥10 (протокол §2.5.8).
        // При pop<10 min(D)=1/pop>0.1 — CRYSTAL математически недостижим,
        // pop=1 → D=1.0 = кристалл, не хаос. Зоны — только при достаточной популяции.
        $zone = $pop < 10
            ? 'NA (pop<10)'
            : ($d < 0.1 ? 'CRYSTAL' : ($d > 0.5 ? 'CHAOS' : 'OK'));
        $this->log("D_RATIO: win={$this->dRatioInterval} D=" . number_format($d, 3) . " pop={$pop} zone={$zone}");
    }

    /**
     * MEMORY-GUARD: при memory_get_usage > порога → gc_collect_cycles + лог.
     * Предохранитель после OOM 04.08 (7710MB при лимите 8G).
     */
    private function checkMemoryGuard(): void
    {
        if ($this->memoryGuardMb <= 0) {
            return;
        }
        $usage = memory_get_usage(true);
        $limit = $this->memoryGuardMb * 1024 * 1024;
        if ($usage > $limit) {
            $freed = gc_collect_cycles();
            $after = round(memory_get_usage(true) / 1024 / 1024, 1);
            $this->log(
                "MEM_GUARD: usage=" . round($usage / 1024 / 1024, 1)
                . "MB > limit={$this->memoryGuardMb}MB freed_cycles={$freed} after={$after}MB"
            );
        }
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

    public function savePopulation(): void
    {
        $db = \BeeSwarm\Infra\Database::get();
        try {
            // CONCERNS deleg_3777bd82: (1) purge мёртвых (иначе append-only
            // рост — 864×P строк/сутки!); (2) ВСЁ в ОДНОЙ транзакции
            // (pkill -9 между UPDATE и INSERT не оставит «все мертвы»!).
            $db->beginTransaction();
            $db->exec('DELETE FROM bee_persistence');
            $stmt = $db->prepare(
                'INSERT INTO bee_persistence (grammar, energy, is_alive) VALUES (?, ?, 1)'
            );
            foreach ($this->bees as $bee) {
                if ($bee->isAlive()) {
                    $stmt->execute([json_encode($bee->grammar()), $bee->energy()]);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[bee_swarm] savePopulation failed: ' . $e->getMessage());
        }
    }

    private function loadPopulation(): ?array
    {
        $db = \BeeSwarm\Infra\Database::get();
        $rows = $db->query('SELECT grammar, energy FROM bee_persistence WHERE is_alive = 1')->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($rows)) return null;
        $bees = [];
        foreach ($rows as $row) {
            $g = json_decode($row['grammar'], true);
            if (is_array($g)) $bees[] = new \BeeSwarm\Hive\Bee($g, (float) $row['energy']);
        }
        return $bees ?: null;
    }

    public function run(): int
    {
        $this->bootstrap();
        // POPULATION-PERSISTENCE: сохранить популяцию при shutdown
        register_shutdown_function(function (): void {
            $this->savePopulation();
        });

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

        // POPULATION-PERSISTENCE (P0, 06.08): восстановление популяции
        $restored = $this->loadPopulation();
        if ($restored !== null) {
            $this->bees = $restored;
            $this->log('RESTORE: ' . count($this->bees) . ' bees loaded from DB');
            return;
        }

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

        // SPAWN-POOL (27.08): пул рецептов. Лимит из env (OOM-защита).
        $maxDormant = max(1000, (int) (getenv('DORMANT_POOL_MAX') ?: '50000'));
        $this->dormantPool = new DormantPool(300);

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

    /** SPAWN-POOL: доступ к пулу рецептов (lazy init — безопасно до bootstrap). */
    public function dormantPool(): DormantPool
    {
        if (! isset($this->dormantPool)) {
            $maxDormant = max(1000, (int) (getenv('DORMANT_POOL_MAX') ?: '50000'));
            $this->dormantPool = new DormantPool(300);
        }
        return $this->dormantPool;
    }

    /** Фаза C: статистика линий (для телеметрии и карты маршрутов). */
    public function lineageStats(): array
    {
        $lines = [];
        foreach ($this->bees as $b) {
            if ($b->isAlive() && $b->lineageId() !== '') {
                $lines[$b->lineageId()] = true;
            }
        }
        return ['lines' => count($lines), 'stale' => count($this->lineageProgress)];
    }

    /** Фаза C: отметить прогресс линии (discovery или рост энергии). */
    public function noteLineageProgress(string $lineageId): void
    {
        if ($lineageId === '') {
            return;
        }
        $this->lineageProgress[$lineageId] = 0;
        // Rev: если записи baseline нет (сирота) — ставим по текущим пчёлам линии
        if (! isset($this->lineageEnergyBaseline[$lineageId])) {
            $maxE = 0.0;
            foreach ($this->bees as $b) {
                if ($b->lineageId() === $lineageId && $b->isAlive()) {
                    $maxE = max($maxE, $b->energy());
                }
            }
            $this->lineageEnergyBaseline[$lineageId] = $maxE;
        }
    }

    /**
     * Фаза C: подрезка линий без прогресса. Линия, чьи пчёлы не сделали
     * ни discovery, ни набрали энергии за K поколений, умирает — ресурсы
     * освобождаются для живых линий (resource-bounded).
     *
     * @return int число подрезанных линий
     */
    public function pruneLineages(int $maxStaleGenerations): int
    {
        foreach ($this->lineageProgress as $lid => $stale) {
            $this->lineageProgress[$lid] = $stale + 1;
        }
        // Прогресс = энергия пчелы линии ВЫШЕ baseline (рост ПОСЛЕ рождения;
        // exploration bonus при рождении прогрессом НЕ считается).
        foreach ($this->bees as $b) {
            $lid = $b->lineageId();
            if ($b->isAlive() && $lid !== '' && isset($this->lineageEnergyBaseline[$lid])) {
                if ($b->energy() > $this->lineageEnergyBaseline[$lid] + 0.5) {
                    $this->lineageProgress[$lid] = 0;
                    $this->lineageEnergyBaseline[$lid] = $b->energy();
                }
            }
        }
        $pruned = 0;
        foreach ($this->lineageProgress as $lid => $stale) {
            if ($stale > $maxStaleGenerations) {
                unset($this->lineageProgress[$lid]);
                // Пчёлы мёртвой линии: лишаем энергии (естественная смерть)
                foreach ($this->bees as $b) {
                    if ($b->lineageId() === $lid && $b->isAlive()) {
                        $ref = new \ReflectionProperty(Bee::class, 'energy');
                        $ref->setAccessible(true);
                        $ref->setValue($b, 0.0);
                    }
                }
                $pruned++;
            }
        }
        if ($pruned > 0) {
            $this->log("SPAWN-POOL: pruned={$pruned} lineages (stale>{$maxStaleGenerations})");
        }
        return $pruned;
    }

    /**
     * SPAWN-POOL Фаза B (27.08): материализация top-K рецептов из пула.
     *
     * Рецепт → Bee (phenotype): грамматика потомка = грамматика родителя
     * (базовые ops) + op рецепта. Энергия — из бюджета роя (отнимаем у
     * самых энергичных пчёл — resource-bounded буквально).
     *
     * @return int сколько пчёл материализовано
     */
    public function materializeFromPool(int $k): int
    {
        $scheduler = new ResourceScheduler(maxMaterialized: $k);
        $quotas = $scheduler->computeQuotas($this->dormantPool->size());
        $awakened = $this->dormantPool->awaken($k, $quotas);

        $added = 0;
        foreach ($awakened as $entry) {
            $recipe = $entry['recipe'];
            $op = (string) ($recipe['op'] ?? '');
            $sector = (string) ($entry['sector'] ?? 'unknown');
            if ($op === '') {
                $this->dormantPool->remove($entry['id']);
                continue;
            }

            // Родитель: продолжение линии приоритетно (пчёлы линии сектора),
            // иначе seed-пчела → новая линия. Внутри группы — самая энергичная.
            $parents = array_filter($this->bees, fn (Bee $b): bool => $b->isAlive());
            if ($parents === []) {
                break; // рой пуст — некому унаследовать
            }
            $sameLineage = array_filter($parents, fn (Bee $b): bool =>
                $b->lineageId() !== '' && $b->lineageSector() === $sector);
            if ($sameLineage !== []) {
                $parents = $sameLineage;
            }
            usort($parents, fn (Bee $a, Bee $b) => $b->energy() <=> $a->energy());
            // Только живой И платёжеспособный родитель (труп прунед-линии
            // с E=0 блокировал сектор до dead-cleanup)
            $parent = null;
            foreach ($parents as $candidate) {
                if ($candidate->energy() >= Bee::SPAWN_PARENT_COST) {
                    $parent = $candidate;
                    break;
                }
            }
            if ($parent === null) {
                continue; // некому платить — рецепт ждёт следующего тика
            }

            // Phenotype: Bee с грамматикой родителя + op из рецепта
            $grammar = $parent->grammar();
            if (! in_array($op, $grammar, true)) {
                $grammar[] = $op;
            }
            // PARTIAL-HYPOTHESIS-BIRTH фаза 2: наследование активированных
            // birth-атомов (reuse≥1) — культура передаётся потомкам линии
            $birthOps = [];
            try {
                $rows = \BeeSwarm\Infra\Database::get()->query(
                    "SELECT name FROM grammar_ops WHERE source='birth' AND status='active'"
                )->fetchAll();
                foreach ($rows as $row) {
                    $birthOps[] = $row['name'];
                }
            } catch (\Throwable $e) {
                // grammar_ops может не иметь status (старая БД) — не критично
            }
            $child = new Bee($grammar, Bee::SPAWN_CHILD_ENERGY, customGrammarOps: $birthOps);

            // Ресурсный баланс: ребёнку 7.0, родителю -7.0 (SPAWN-константы).
            // Родитель уже проверен на платёжеспособность при выборе.
            $ref = new \ReflectionProperty(Bee::class, 'energy');
            $ref->setAccessible(true);
            $pe = (float) $ref->getValue($parent);
            $ref->setValue($parent, $pe - Bee::SPAWN_PARENT_COST);

            // Фаза C: lineage. Новая линия = сектор, которого ещё нет среди живых.
            $existingSectors = [];
            foreach ($this->bees as $b) {
                if ($b->isAlive() && $b->lineageId() !== '') {
                    $existingSectors[$b->lineageSector()] = true;
                }
            }
            // Линия = по РОДИТЕЛЮ (родословная, не сектор). Ребёнок продолжает
            // линию родителя; новая линия только у потомка seed-пчелы.
            $parentLineage = $parent->lineageId();
            $isNewLineage = ($parentLineage === '');
            $lineageId = $isNewLineage
                ? 'lin_' . $sector . '_' . $this->tick . '_' . (++$this->lineageSeq)
                : $parentLineage;
            $child->setLineage($lineageId, $parentLineage);

            $ref->setValue($child, Bee::SPAWN_CHILD_ENERGY
                + ($isNewLineage ? Bee::EXPLORATION_BONUS : 0.0));

            $this->bees[] = $child;
            $this->dormantPool->remove($entry['id']);
            // Рождение потомка ≠ прогресс: линия должна ПОКАЗАТЬ пользу
            // (discovery/рост энергии), иначе stale-счётчик растёт (prune).
            // Lazy re-init (rev): осиротевшая линия (записи нет после prune,
            // пчёлы живы) — восстанавливаем все три записи.
            if (! isset($this->lineageProgress[$lineageId])) {
                $this->lineageProgress[$lineageId] = 0;
                $this->lineageEnergyBaseline[$lineageId] = $ref->getValue($child);
            }
            $this->lineageEnergyBaseline[$lineageId] = max(
                $this->lineageEnergyBaseline[$lineageId] ?? 0.0,
                $ref->getValue($child)
            );
            $added++;
        }

        if ($added > 0) {
            $this->log("SPAWN-POOL: materialized={$added} pool=" . $this->dormantPool->size());
        }
        return $added;
    }

    /**
     * PARTIAL-HYPOTHESIS-BIRTH (27.08, EXP-035): частичная гипотеза →
     * B-кандидат в grammar_ops. Рождение ТОЛЬКО при выполнении всех гейтов:
     *  1. ≥2 терминала — нетривиальная структура
     *  2. CV < 0.5 — заметно лучше среднего (не мусор)
     *  3. Compression: определение компактно против встраивания
     *  4. Голод линии — stale-счётчик > 0 (ресурсная дисциплина)
     *
     * RCB: candidate-статус, активация после reuse≥1 (existing критерий).
     *
     * @return bool произошли ли рождение/переиспользование записи
     */
    public function partialBirth(string $formula, float $cv, string $domain, float $cvMean): bool
    {
        // Гейт 1: нетривиальность (минимум 2 терминала xN)
        $terminals = preg_match_all('/x\d+/', $formula);
        if ($terminals < 2) {
            return false;
        }

        // Гейт 2: CV существенно лучше среднего
        if ($cv >= 0.5) {
            return false;
        }

        // Гейт 3: компрессия. B-имя при reuse ≈ 'B'+hash (5-6 симв) × 2
        // встраивания. Формула обязана быть короче 2× этого встраивания.
        $birthOverhead = strlen('(xBxx)') + 1; // минимальный контекст встраивания
        if (strlen($formula) > 2 * $birthOverhead) {
            return false;
        }

        // Гейт 4: голод линии — хотя бы одна линия живёт в stale (prune
        // когда-либо отмечал безуспешность). Успешный рой не рождает.
        $hungry = false;
        foreach ($this->lineageProgress as $stale) {
            if ($stale > 0) {
                $hungry = true;
                break;
            }
        }
        if (! $hungry) {
            return false;
        }

        // Рождение: candidate (RCB двухфазность, reuse≥1 → PROMOTED)
        // B-AS-ARGUMENT контракт: definition-атомы обязаны быть x0/x1
        // (x0→первый операнд, x1→второй). Частичная гипотеза (x1−x2)
        // канонизируется в (x0−x1) — иначе def молча возвращает мусор
        // (урок фазы 4: (x1−x2) с row [[l,r]] → x1=row[1]=r, x2=null).
        $termOrder = [];
        $canonical = preg_replace_callback('/x\d+/', function ($m) use (&$termOrder) {
            if (! isset($termOrder[$m[0]])) {
                $termOrder[$m[0]] = 'x' . count($termOrder);
            }
            return $termOrder[$m[0]];
        }, $formula);
        $seq = count($this->lineageEnergyBaseline) + 100; // namespace от lineages
        $name = 'BP' . dechex(crc32($canonical . $domain) & 0xFFFF);
        \BeeSwarm\Core\Grammar::staticAdd($name, 'birth', $canonical, $domain);
        $this->log("PARTIAL-BIRTH: {$name} = {$formula} (cv=" . number_format($cv, 3) . ", domain={$domain})");
        return true;
    }

    private function doTick(): void
    {
        // EXP-034 (27.08): SPAWN-POOL B-ветка. Env SPWN_POOL=1 активирует
        // ресурсно-ограниченный спавн: пчёлы с discovery рожают рецепты,
        // топ-K материализуется по квотам секторов каждые SPAWN_POOL_EVERY тиков.
        if (getenv('SPWN_POOL') === '1' && $this->tick % max(1, (int) (getenv('SPAWN_POOL_EVERY') ?: '25')) === 0) {
            $k = max(1, (int) (getenv('SPAWN_POOL_K') ?: '3'));
            $hive = $this;
            $added = $hive->materializeFromPool($k);
            // Рецепты от пчёл с discovery: упрощённо — каждая живая пчела
            // с энергией выше baseline порождает 2 рецепта в пул
            foreach ($this->bees as $b) {
                if ($b->isAlive() && $b->energy() > 10.5) {
                    foreach ($b->emitRecipes(2) as $recipe) {
                        $this->dormantPool->deposit($recipe, $recipe['sector'], 0.6);
                    }
                }
            }
            $this->dormantPool()->age(50);
            $this->pruneLineages(3);
        }
        // DEAD-CLEANUP (10.08): мёртвые пчёлы накапливались в $this->bees
        // (254 за 5ч — утечка памяти и метрик). Удаление каждые 100 тиков.
        if ($this->tick % 100 === 0 || $this->tick === 1) {
            $alive = array_values(array_filter(
                $this->bees,
                fn (Bee $b): bool => $b->isAlive()
            ));
            if (count($alive) !== count($this->bees)) {
                $this->bees = $alive;
            }
        }
        // POPULATION-PERSISTENCE ПЕРИОДИЧЕСКАЯ (14.08): savePopulation был
        // ТОЛЬКО в shutdown-hook — pkill -9 (SIGKILL) терял популяцию целиком
        // (13 дней на ноуте!). Теперь: каждые 100 тиков сохраняем — теряем ≤100.
        if ($this->tick % 100 === 0 && $this->tick > 0) {
            $this->savePopulation();
        }
        // S1.5 фаза 2 (11.08): MONOCULTURE ALARM — diversity < порога
        // (env MONOCULTURE_ALARM_DIVERSITY, default 0.34): сжатие грамматики.
        if ($this->tick % 100 === 0 || $this->tick === 1) {
            $diversity = SpawnManager::computeDiversity($this->bees);
            $monoThreshold = (float) (getenv('MONOCULTURE_ALARM_DIVERSITY') ?: '0.34');
            if ($diversity > 0.0 && $diversity < $monoThreshold) {
                $this->log("MONOCULTURE: diversity={$diversity} < {$monoThreshold} (env MONOCULTURE_ALARM_DIVERSITY)");
            }
        }
        // REUSE-CRITERION-BIRTH фаза 3 (10.08): ЗАБВЕНИЕ кандидатов.
        // Кандидат без reuse за TTL часов удаляется; активные (reuse>0) —
        // процедурная память, НЕ забываются. Раз в 100 тиков.
        if ($this->tick % 100 === 0 || $this->tick === 1) {
            $ttlHours = max(1, (int) (getenv('CANDIDATE_TTL_HOURS') ?: '24'));
            \BeeSwarm\Infra\Database::get()->prepare(
                "DELETE FROM grammar_ops WHERE source = 'birth' AND status = 'candidate'
                 AND invented_at < datetime('now', ?)"
            )->execute(['-' . $ttlHours . ' hours']);
        }
        // ЭКСП-018: Colony Economics Profile (PROFILE=1)
        $profT0 = microtime(true);
        $profSearchT0 = $profT0;
        $this->lastAnswerFormula = null;

        // Memory monitor every 100 ticks + первый тик (ранний предохранитель)
        if ($this->tick % 100 === 0 || $this->tick === 1) {
            $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
            $peak = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
            $this->log("MEM: tick={$this->tick} mem={$mem}MB peak={$peak}MB tasks=" . count($this->foragedTasksGlobal));
            $this->checkMemoryGuard();
        }

        // D_RATIO телеметрия (аудит 05.08 §2.5.8): скользящее окно
        if ($this->dRatioInterval > 0 && ($this->tick % $this->dRatioInterval === 0 || $this->tick === 1)) {
            $this->logDRatioSnapshot();
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
                // Дедуп: не добавляем задачи, чьи имена уже в пуле
                $existingNames = [];
                foreach ($this->foragedTasksGlobal as $t) {
                    $existingNames[$t['name'] ?? ''] = true;
                }
                $newCount = 0;
                foreach ($foraged as $t) {
                    $name = $t['name'] ?? '';
                    if (isset($existingNames[$name])) {
                        continue;
                    }
                    $existingNames[$name] = true;
                    $this->foragedTasksGlobal[] = $t;
                    $newCount++;
                }
                // Потолок: удерживаем последние 8000 задач (предотвращает OOM)
                if (count($this->foragedTasksGlobal) > 8000) {
                    $this->foragedTasksGlobal = array_slice(
                        $this->foragedTasksGlobal, -8000
                    );
                }
                if ($newCount > 0) {
                    $this->log("FORAGER: {$newCount} new tasks, pool="
                        . count($this->foragedTasksGlobal));
                }
                if ($this->forager->hasNewContent()) {
                    $hasNewForagerData = true;
                    $this->log('FORAGER_NEW_TASK: ' . $this->forager->getNewTaskCount()
                        . ' tasks, ' . $this->forager->getNewDomainCount() . ' domains');
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

        // Wakeup on GROWTH only (CONCERNS A 05.08): consumeTask уменьшает пул
        // почти каждый тик — `!==` будил плато бесконечно, GAP_SPAWN мёртв.
        // Рост задач = новая пища; потребление = не пища, плато не будит.
        $currentTaskCount = count($tasks);
        if ($currentTaskCount > $this->lastTaskCount) {
            $this->plateau->wakeup();
        }
        $this->lastTaskCount = $currentTaskCount;

        // Route via TaskRouter if population exists, else random
        $this->routedBee = null;
        $task = $this->weightedPick($tasks);
        // Потребление: удалить выбранную задачу из пула
        $this->consumeTask($task['name'] ?? '');
        if ($this->taskRouter && ! empty($this->bees)) {
            $this->routedBee = $this->taskRouter->route($task);
        }

        if ($this->routedBee) {
            $beeIdx = array_search($this->routedBee, $this->bees, true);
            $this->log("ROUTE: task -> bee#{$beeIdx}");

            // §S1.7-NOVELTY: reward bee for exploring new fingerprint
            // NO_NOVELTY=1 — тестовый флаг (детерминизм энергетики)
            static $seenFingerprints = [];
            $fp = $this->taskRouter->fingerprint($task);
            if (getenv('NO_NOVELTY') !== '1' && ! isset($seenFingerprints[$fp])) {
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
            // §S1.5-HUNGER: голодная мутация при 3≤E<5 (адаптация ДО голода).
            // SHRINK (08.08): при E<3 — спячка (метаболизм ×0.1), мутации НЕТ —
            // иначе зомби с E≈0 мутировал бы каждый тик 2900 раз (раздувание).
            if ($bee->isAlive() && $bee->energy() >= 3.0 && $bee->energy() < 5.0) {
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
                // LIFETIME-METRIC (07.08): lifespan = tick смерти − tick рождения
                $life = $this->tick - $bee->getBirthTick();
                // IEEE-754: после 1000 тиков E≈1e-13 — смерть логгируем как 0
                $this->log("DEATH: bee#{$i} energy=" . max(0.0, $bee->energy()) . " life={$life}");
                $this->lifetimeAccum += $life;
                $this->lifetimeCount++;
            }
        }

        // D17: SpawnManager handles spawning + generation tracking
        $allOps = array_merge(array_keys(Grammar::BASE_OPS), Grammar::SEMANTIC_OPS);
        [$spawned, $spawnDetails] = $this->spawnManager->trySpawn($this->bees, $allOps);

        // S1.2 Phase 4: Gap-Triggered Spawn — размножение при долгом PLATEAU
        $gapSpawned = $this->spawnManager->tryGapSpawn(
            $this->bees, $allOps,
            $this->plateau->isPlateau(),
            $this->plateau->getConsecutiveNoDiscovery(),
            $hasNewForagerData,
            $this->plateau->getThreshold(),
            $this->tick,
        );
        if ($gapSpawned > 0) {
            $trigger = $hasNewForagerData ? 'new_data' : 'fallback';
            $this->log("GAP_SPAWN: pop=" . count($this->bees) . " trigger={$trigger}");
            $spawned += $gapSpawned;
        }

        // D_ACT (аудит 05.08): событие тика = mutation (спавн) | plateau_exit.
        // EVENT_CONTRADICTION (S1.8) и EVENT_GRAMMAR_DIAGNOSIS — будущие стори.
        $dActEvent = $spawned > 0 || $this->plateau->justExitedPlateau();
        $this->recordDActivity($dActEvent);
        if ($this->dActInterval > 0 && $this->tick % $this->dActInterval === 0) {
            $this->logDActivity();
        }

        if ($spawned > 0) {
            $this->log("SPAWN: +{$spawned} pop=" . count($this->bees));
            // VERIFY_1_2/1_3 (15.08): SPAWN-детали в формате verify-скриптов:
            // SPAWN: bee#N from parent M + GRAMMAR_SPAWN parent=N child=M
            // parent_size=X child_size=Y (изоляция грамматик проверяема!).
            foreach ($spawnDetails as $d) {
                $childIdx = $d['child_key'];
                if ($d['parent'] === null) {
                    $this->log("SPAWN: bee#{$childIdx} from seed");
                    $this->log('GRAMMAR_SPAWN parent=seed child=' . $childIdx
                        . ' parent_size=0 child_size=' . count($d['child_grammar']));
                } else {
                    $this->log("SPAWN: bee#{$childIdx} from parent {$d['parent']}");
                    $this->log('GRAMMAR_SPAWN parent=' . $d['parent'] . ' child=' . $childIdx
                        . ' parent_size=' . count($d['parent_grammar'])
                        . ' child_size=' . count($d['child_grammar']));
                }
            }
            $diversity = SpawnManager::computeDiversity($this->bees);
            $avgG = SpawnManager::avgGrammarSize($this->bees);
            $uniqueCount = count(array_unique(array_map(
                fn (Bee $b) => implode(',', $b->grammar()),
                array_filter($this->bees, fn (Bee $b) => $b->isAlive())
            )));
            $avgLife = $this->lifetimeCount > 0
                ? (int) round($this->lifetimeAccum / $this->lifetimeCount) : 0;
            $this->lifetimeAccum = 0;
            $this->lifetimeCount = 0;
            $this->log("GEN: {$this->spawnManager->getGeneration()} pop=" . count($this->bees)
                . " unique={$uniqueCount} diversity={$diversity} avg|G|={$avgG} avg_lifetime={$avgLife}");

            // ЭКСП-018: энергетический баланс по классам |G| (PROFILE=1)
            if (getenv('PROFILE') === '1') {
                $buckets = [1 => [], 2 => [], 5 => [], 10 => [], 20 => [], 50 => [], 100 => []];
                $keys = array_keys($buckets);
                foreach ($this->bees as $b) {
                    if (! $b->isAlive()) continue;
                    $g = count($b->grammar());
                    $bucket = 100;
                    foreach ($keys as $k) { if ($g <= $k) { $bucket = $k; break; } }
                    $buckets[$bucket][] = $b->energy();
                }
                $parts = [];
                foreach ($buckets as $k => $energies) {
                    if (empty($energies)) continue;
                    $parts[] = "|G|≤{$k}:" . round(array_sum($energies) / count($energies), 2)
                        . "x" . count($energies);
                }
                $this->log('G_BALANCE: ' . implode(' ', $parts));
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
                    // E1.3-fix: только валидные label-кандидаты в метрику
                    // (буквы, ≥3, не римские, не стоп-слова)
                    if (! AtomRegistry::isValidTextAtomLabel($label)) {
                        continue;
                    }
                    $result = AtomRegistry::applyTextAtom($textAtom, $content, $label);
                    if ($result !== null && ! empty($result)) {
                        // E1.3-fix: без реальных данных (числа/захваченные группы) —
                        // это вхождения слова, а не открытие. Спам убивал plateau/gap-spawn.
                        if (! AtomRegistry::hasTextAtomData($result)) {
                            continue;
                        }
                        $composed = "{$textAtom}({$label})";
                        // E1.3-fix: если атом уже в grammar_ops — это не новое
                        // открытие. Повторные foundAny убивали plateau.
                        if (AtomRegistry::isDiscoveredTextAtom($composed)) {
                            continue;
                        }
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

        // ЭКСП-018: Colony Economics Profile (PROFILE=1)
        if (getenv('PROFILE') === '1') {
            $tickMs = (int) round((microtime(true) - $profT0) * 1000);
            $searchMs = (int) round((microtime(true) - $profSearchT0) * 1000);
            $this->log("PROFILE: tick={$this->tick} TICK_MS={$tickMs} SEARCH_MS={$searchMs} task=" . (isset($task) ? 'yes' : 'no'));
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
        $cvTrainMax = $this->getEpsilon($fp) ?? 0.15;

        // D14 Wiring: engine-based discovery (replaces inline Search + Heldout + Compose)
        if ($this->routedBee) {
            $this->routedBee->chargeSearch();
        }
        $grammarOps = array_merge(Grammar::baseOpNames(), $this->routedBee->grammar());
        $colLabels = $task['col_labels'] ?? null;
        $profSearchT0 = microtime(true);
        $engine = new DiscoveryEngine();
        [$candidates, $bestCv, $searchCv] = $engine->discover($X, $y, $grammarOps, $cvTrainMax, $colLabels);
        $this->lastCandidates = $candidates; // REUSE-TRACKING (08.08): все кандидаты

        foreach ($candidates as $d) {
            $this->recordDiscovery($d, $task, $domain, $foundAny, $X, $y);
        }

        // Information reward — intrinsic value of search
        $this->routedBee->rewardInformation();

        // S1.6-GRADIENT: signal reward — uses searchCv (не портится compose)
        if (! $foundAny && $searchCv > $cvTrainMax && $searchCv < 9.0) {
            $nullFloor = NullCalibrator::getNullFloor($X, $y, Grammar::fromOps($grammarOps));
            if ($searchCv <= $nullFloor) {
                $this->routedBee->rewardSignal();
                $this->log("SIGNAL: best_CV=" . round($searchCv, 4) . " zone=({$cvTrainMax}..{$nullFloor}]");
            }
        }
    }

    /**
     * GRAMMAR-BIRTH (ЭКСП-015): составная формула (глубина ≥2, без R-атомов)
     * → новый атом в grammar_ops (source='birth', definition=формула).
     * Рождение = абстракция: пчела «изобретает» оператор из успешного паттерна.
     */
    private function birthOperator(string $formula, string $domain): void
    {
        // ЛАВИНА (06.08): без потолка B-атомы раздувают unary pool —
        // каждый Search::find перебирает их на каждой фиче. Cap 30.
        // static-кэш счётчика: COUNT на каждое открытие дорог (suite 20 мин)
        static $birthCount = -1;
        if ($birthCount === -1) {
            $db = \BeeSwarm\Infra\Database::get();
            $birthCount = (int) $db->query("SELECT COUNT(*) FROM grammar_ops WHERE source = 'birth'")->fetchColumn();
        }
        if ($birthCount >= 30) {
            return;
        }
        $birthCount++;
        // Тривиальные/тавтологии не рождают операторы
        if (str_contains($formula, 'R+') || str_contains($formula, 'R×')
            || str_contains($formula, 'Rmin') || str_contains($formula, 'Rmax')
            || strlen($formula) < 5) {
            return;
        }
        // BIRTH-SOURCE-FILTER (09.08, ЭКСП-022h, CONCERNS deleg_78091319):
        // ALLOW-list (fail-closed): рождение ТОЛЬКО из foraged_* (реальные
        // задачи) и 'text' (реальные текстовые законы). base-домены
        // (arithmetic/logic — синтетические тавтологии ADD/AND) и dream
        // (compose-мусор) НЕ рожают. Новые домены по умолчанию молчат —
        // deny-list рецидивировал бы при каждом добавлении домена.
        $isRealSource = str_starts_with($domain, 'foraged') || $domain === 'text';
        if (! $isRealSource) {
            return;
        }
        // COMPRESSION-CRITERION (09.08): имя атома КОРОЧЕ definition —
        // иначе (x0B7a7aeex1) длиннее (x0addx1) → parsimony выбирает add →
        // B-атом никогда не используется → reuse=0. B1/B2 = сжатие (2 симв).
        $name = 'B' . ($birthCount + 1);
        \BeeSwarm\Core\Grammar::staticAdd($name, 'birth', $formula, $domain);
    }

    /**
     * REUSE-TRACKING (06.08): B-атомы в найденной формуле получают reuse.
     */
    private function registerReuseOps(string $formula, string $domain): void
    {
        // B-AS-ARGUMENT (09.08): имена B1/B2 (короткие) — regex B\d+
        // (старые B7a7aee тоже матчатся: B7a7aee начинается с B\d)
        preg_match_all('/B\d+[0-9a-f]*/', $formula, $m);
        foreach ($m[0] as $born) {
            \BeeSwarm\Core\Grammar::registerReuse($born, $domain);
        }
        // REUSE-TOUCH-ATOM ф3 (10.08): definition-подстрока УДАЛЕНА —
        // touchAtom в Search::find (точка применения) достовернее
        // (подстрока ≠ применение, аудит deleg_0518ec3b: 15 мест отказа).
        // Regex B\d+ остаётся fallback для compose-путей.
    }

    /**
     * GRAMMAR-PROPAGATION (ЭКСП-012): операторы найденной формулы получают вес.
     */
    private function boostFormulaOps(string $formula): void
    {
        // Группа A (контроль, EXP-012): PROPAGATION=0 отключает культурную эволюцию
        if (getenv('PROPAGATION') === '0') {
            return;
        }
        $node = \BeeSwarm\Core\ExpressionNormalizer::parse($formula);
        if ($node === null) {
            return;
        }
        $opMap = [
            '+' => 'add', '−' => 'sub', '×' => 'mul', '/' => 'div',
            'max' => 'max', 'min' => 'min', 'sq' => 'sq',
        ];
        $seen = [];
        $walk = function (array $n) use (&$walk, &$seen, $opMap): void {
            if (isset($n['op'])) {
                $name = $opMap[$n['op']] ?? $n['op'];
                if (! isset($seen[$name])) {
                    $seen[$name] = true;
                    \BeeSwarm\Core\Grammar::staticBoostOp($name);
                }
            }
            if (isset($n['l'])) { $walk($n['l']); }
            if (isset($n['r'])) { $walk($n['r']); }
        };
        $walk($node);
    }

    private function recordDiscovery(array $d, array $task, string $domain, bool &$foundAny, ?array $X = null, ?array $y = null): void
    {
        $result = $this->recordKeeper->record($d, $task, $domain);
        if (! $result['inserted']) {
            // Rate-limit: каждый атом логируется как дубликат только один раз за сессию
            static $duplicateLogged = [];
            if (! isset($duplicateLogged[$d['atom']])) {
                $duplicateLogged[$d['atom']] = true;
                $this->log("DUPLICATE: {$d['atom']} [{$domain}]");
            }
            return;
        }
        if (! empty($result['cross_domains'])) { $this->log("CROSS_DOMAIN: {$d['atom']}"); }
        $foundAny = true;
        // GRAMMAR-PROPAGATION (ЭКСП-012): успех → вес операторов формулы.
        // ТОЛЬКО для реальных законов: тавтологии (CV=0.0000) бустятся
        // не должны — иначе культурная эволюция усиливает мусор (B<A в A/B).
        // РЕАЛЬНЫЙ ЗАКОН (09.08, правка пользователя): структурный фильтр,
        // НЕ CV! Условие cv>0.001 резало ТОЧНЫЕ законы (CV=0.0000 —
        // чистейшие!) как «тавтологии». Тавтология = структурная (нет фич
        // или простой атом). Точные составные законы: рожают атомы,
        // бустят ops (пропаганда), дают классы.
        $realLaw = (bool) preg_match('/[+×−\/(]/', $d['atom'] ?? '')
            && (bool) preg_match('/[xX]\d+/', $d['atom'] ?? '');
        if ($realLaw) {
            $this->boostFormulaOps($d['atom']);
            // NO_BIRTH=1 — диагностика: рождение/реюс не влияют на тик
            if (getenv('NO_BIRTH') !== '1') {
                $this->birthOperator($d['atom'], $domain); // GRAMMAR-BIRTH (ЭКСП-015)
            }
        }
        // REUSE-TRACKING: ВНЕ условия CV>0.001 (09.08, ЭКСП-022n) — точные
        // законы (CV=0.0000) — ЧИСТЕЙШИЕ, не тавтологии! Раньше условие
        // резало их → reuse не регистрировался при фактическом применении
        // атома. Победитель + все кандидаты с B-атомами.
        if (getenv('NO_BIRTH') !== '1') {
            $this->registerReuseOps($d['atom'], $domain);
            foreach ($this->lastCandidates ?? [] as $lc) {
                if (is_string($lc['atom'] ?? null) && $lc['atom'] !== $d['atom']) {
                    $this->registerReuseOps($lc['atom'], $domain);
                }
            }
        }
        if ($this->routedBee && $this->routedBee->isAlive()) $this->routedBee->addToGrammar($d['atom']);
        if ($this->taskRouter && $this->routedBee) {
            $this->taskRouter->recordOutcome($task, $this->routedBee, true);
        }
        $this->lastAnswerFormula = $d['atom'];
        // S2.1 PREREG-лог: статус гипотезы из preregistrations (двухфазная
        // запись — в Search::find: PENDING до heldout, UPDATE после)
        $prSt = Database::get()->prepare(
            'SELECT status, cv_predicted FROM preregistrations WHERE formula = ?
             ORDER BY id DESC LIMIT 1'
        );
        $prSt->execute([$d['atom']]);
        if (($prRow = $prSt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $this->log(sprintf(
                'PREREG: %s cv_train=%.4f -> %s cv_test=%.4f',
                $d['atom'], (float) $prRow['cv_predicted'],
                $prRow['status'], $d['cv_test'] ?? 9.99
            ));
        }
        // LAW-CLASS-REWARD (08.08): награда ТОЛЬКО за первый представитель
        // pred-класса (численно эквивалентные формулы — один класс).
        // Иначе сотни приближений одного закона = бесконечная еда.
        $newClass = true;
        if ($X !== null && $y !== null) {
            $classHash = \BeeSwarm\Core\LawClassifier::hash($d['atom'], $X, $y);
            if ($classHash !== '') {
                $exists = Database::get()->prepare(
                    'SELECT 1 FROM laws WHERE domain = ? AND law_class = ? LIMIT 1'
                );
                $exists->execute([$domain, $classHash]);
                if ($exists->fetchColumn() !== false) {
                    $newClass = false;
                } else {
                    Database::get()->prepare(
                        'UPDATE laws SET law_class = ? WHERE domain = ? AND formula = ?'
                    )->execute([$classHash, $domain, $d['atom']]);
                }
            }
        }
        if ($this->routedBee && $newClass) {
            // DOMAIN-SATIETY (08.08): новый класс → register + множитель
            // (первый класс ×1.5, насыщение ×0.1 — переключение доменов)
            $this->routedBee->registerClass($domain);
            $labels = $task['col_labels'] ?? [];
            $formulaStr = $d['atom'] ?? '';
            $hasFeatures = (bool) preg_match('/[xX]\d+/', $formulaStr);
            foreach ($labels as $lb) {
                if (is_string($lb) && str_contains($formulaStr, $lb)) {
                    $hasFeatures = true;
                    break;
                }
            }
            $this->routedBee->rewardDiscovery(
                $this->routedBee->discoveryMultiplier($domain),
                $formulaStr,
                $hasFeatures
            );
        }
        $this->plateau->tick(true);

        $cvFmt = number_format($d['cv'], 4);
        $srcHint = isset($task['source_path']) ? ' src=' . basename($task['source_path']) : '';
        $colHint = '';
        if (! empty($task['col_labels']) && count($task['col_labels']) >= 2) {
            $labels = $task['col_labels'];
            $colHint = ' [' . ($labels[0] ?? '?') . '→' . ($labels[count($labels)-1] ?? '?') . ']';
        }
        $this->log("🔍 {$task['name']} -> {$d['atom']} (CV={$cvFmt}) [{$domain}]{$srcHint}{$colHint}");
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
        $epsilon = $this->getEpsilon($fp) ?? 0.15;
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
        $crossTasks = [];

        // Plateau / skipGenerated: только данные из корпуса, без compose-генерации
        // (HONEST_CRITERIA §1.5 — на plateau не плодим искусственные задачи)
        if ($skipGenerated || $this->plateau->isPlateau()) {
            $generator = new TaskGenerator();
            return $this->filterInsufficient($generator->generate($this->foragedTasksGlobal, $crossTasks));
        }

        // Не-plateau: compose-генерация + корпус
        $gen = new TaskGenerator();
        $tasks = $gen->createComposeTasks();

        $generator = new TaskGenerator();
        $tasks = array_merge($tasks, $generator->generate($this->foragedTasksGlobal, $crossTasks));

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
     * Потребление задачи из foragedTasksGlobal по имени.
     * Предотвращает бесконечный рост пула (OOM: 325k задач за ночь).
     */
    private function consumeTask(string $name): void
    {
        if ($name === '') {
            return;
        }
        foreach ($this->foragedTasksGlobal as $i => $t) {
            if (($t['name'] ?? '') === $name) {
                unset($this->foragedTasksGlobal[$i]);
                // Компактируем массив каждые 100 удалений
                static $removeCount = 0;
                $removeCount++;
                if ($removeCount % 100 === 0) {
                    $this->foragedTasksGlobal = array_values($this->foragedTasksGlobal);
                }
                return;
            }
        }
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
