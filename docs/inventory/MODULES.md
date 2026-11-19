# Модульный обзор (проход 2)

Автогенерация из docs/inventory/INVENTORY.jsonl.

## ЯДРО: поиск законов — Search (конвейер/бимы/потенциал), Grammar (грамматика/атомы), ExpressionEvaluator, CvCalculator и др.

Файлов: 23

- `src/Core/AtomDefinitions.php` — AtomDefinitions — конфигурация атомов (не логика). Вынесено из AtomRegistry для соблюдения SOLID S.
- `src/Core/AtomProvider.php` — AtomProvider — открытие атомов из данных. Вынесено из AtomRegistry (SOLID S).
- `src/Core/AtomRegistry.php` — Стоп-слова для text-atom label'ов (E1.3-fix, 04.08.2026). Label — это кандидат в метрику («GI:», «sleep:»), а 
- `src/Core/ComposeBridge.php` — DREAM-CONGRUENCE (29.09): нотационный мост compose-форм. discoverCompose пишет функциональную нотацию 'outer(i
- `src/Core/CongruenceClassReport.php` — V0.15 WU-5: аудит-секция «Equivalence class». AUDIT_REPORT_TEMPLATE в репо нет (проверено grep 25.09) — по хен
- `src/Core/CongruenceDetector.php` — V0.15 WU-1: CongruenceDetector — предсказательная эквивалентность на домене. Класс эквивалентности C = {формул
- `src/Core/ExpressionEvaluator.php` — SEARCH-TOP-K (05.08.2026): вычисление ВЫРАЖЕНИЙ по данным. Находка: LawValidator::evaluateHeldout использовал 
- `src/Core/ExpressionNormalizer.php` — FORMAL-LAYER Ф1: каноническая нормализация выражений. (x1+x0) ≡ (x0+x1) — один канон → структурная дедупликаци
- `src/Core/ExpressionTree.php` — ExpressionTree: вычисляет деревья выражений без хардкода. Когда рой изобретает операцию, её определение сохран
- `src/Core/FormHash.php` — S2.2-TER (§3.2.2): form hash = LawShape AFTER expansion of birth atoms. A birth word (BW-atom) hides the inter
- `src/Core/Grammar.php` — EXP-045 Route Heredity: checkpoint B prototype — RouteGenome/RouteMutator/RouteExecutor/RouteLedger,
- `src/Core/LawClassifier.php` — LAW-CLASS (08.08): класс закона для второй линии награды. Награда только за первый представитель класса: сотни
- `src/Core/LawShape.php` — T4 (story theorem-level): инвариант формы закона + law-distance. Канон (ExpressionNormalizer) различает законы
- `src/Core/NonConstancyFilter.php` — NON-CONSTANCY (10.08, ЭКСП-026/MOEX; refactor 11.08): константные псевдозаконы (K1≡1.0, K2≡2.0 + фича) проходя
- `src/Core/QueryEngine.php` — QueryEngine — детерминированные read-only запросы к БД системы (Theo-Conjecture T3). Никакого ML, никакой аппр
- `src/Core/Search.php` — PARSIMONY: штраф за символ формулы (C) калибруемый, из gplearn
- `src/Core/SearchProfiler.php` — ЭКСП-018b: микро-профиль Search::find. Аккумулирует время секций; вывод при shutdown (SEARCH_PROFILE=1).
- `src/Core/SlotCascade.php` — A2/A3 (pysr-rematch PLAN-v2): каскад слотов — SUM-каркасы из ×-пар L1. EXP-036: dot 0/20 — beam по CV убивает 
- `src/Core/TextAtomCrossPairer.php` — TextAtomCrossPairer — превращает одиночные значения текстовых атомов в X/y пары. Проблема: forager извлекает о
- `src/Core/TrailAdmission.php` — EXP-045 ROAD V4 (19.10, спека WU_ROAD_V4_SPEC.md §2/§4): классификатор кандидатов admission дорожных меток. Са
- `src/Core/WordCodec.php` — GENOME-001 (15.10.2026): кодек наследуемых исполняемых слов (CHECKPOINT A). Кодек работает НАД деревом Express
- `src/Core/WordCodecException.php` — Отказ кодека GENOME-001: неизвестное слово, циклическая зависимость, несоответствие арности, malformed-геном. 
- `src/Core/YellowBallFilter.php` — YellowBallFilter — детектит кандидатов-«жёлтые мячики». Кандидат с train_CV < 0.01 но held-out_CV > 0.05 — шум

## УЛЕЙ: кампании/пчёлы — RouteExecutor (полёты), driver, маршруты, бюджет, дорога (Trail*), телеметрия

Файлов: 44

- `src/Hive/AtomPenalty.php` — Atom penalty (протокол §2.5.6, диссипативный контур, Phase 5). Атом, входящий в >= falsifyThreshold фальсифици
- `src/Hive/AutophagyEngine.php` — §2.5.14 AUTOPHAGY: селективная деградация грамматики при голодании. Заменяет случайную HUNGER_MUTATE (panic → 
- `src/Hive/Bee.php` — Bee — autonomous search agent with energy-based life cycle. Protocol §2.1 + §2.1-эво (Evolvable Energy Params)
- `src/Hive/BeeWorker.php` — BeeWorker — wraps Bee for RoadRunner HTTP request handling. Each RoadRunner worker holds one BeeWorker → one B
- `src/Hive/BootstrapManager.php` — BootstrapManager — создание seed-популяции (§0.6). Извлечён из Hive::bootstrap(). Создаёт 3 seed-пчелы с G₁=B,
- `src/Hive/BudgetAccountant.php` — STRATEGY-TRANSFER prereg rev.5 (owner edit 1): budget accounting for the strategy campaign. A charges evolutio
- `src/Hive/ClozeEngine.php` — ClozeEngine — поиск законов через cloze-задачи (word prediction). Извлечён из Hive::doClozeTick(). Phase 5: fi
- `src/Hive/ComposeEngine.php` — ComposeEngine — compose-поиск законов через пары grammar-операций. Извлечён из Hive::doComposeTick(). Phase 4:
- `src/Hive/ContradictionDetector.php` — Contradiction detector (протокол §2.5.3, диссипативный контур). Критерий: две пчелы нашли РАЗНЫЕ формулы для о
- `src/Hive/DifficultyGovernor.php` — §2.6 Environmental Pressure — адаптация сложности (boundary of capabilities). Окно последних WINDOW исходов (s
- `src/Hive/DiscoveryEngine.php` — DiscoveryEngine — поиск законов в данных (§1.1-1.4).
- `src/Hive/DormantPool.php` — DORMANT OFFSPRING POOL — genotype/phenotype separation. Рецепты (genotype): дешёвые, хранятся как JSON. Матери
- `src/Hive/EnvPressure.php` — §2.6 Environmental Pressure — конечность и порча задач. Задача, не решённая за K тиков, выбрасывается из пула 
- `src/Hive/EnvPressureVerify.php` — §2.6 Measurement: verify_1_6. Проверяет на логе демона: (a) >=1 задача выброшена TIMEOUT (MISSED_OPPORTUNITY),
- `src/Hive/EscrowOrphanDetector.php` — ESCROW-ORPHAN-DEPOSITS WU-1 (prereg 17.10): наблюдаемость висячих депозитов. Сирота = law_escrow (status='hold
- `src/Hive/EscrowStore.php` — V0.14 WU-3 (verification-economy): эскроу отложенной награды. Спека: «закон получает энергию НЕ сразу, а после
- `src/Hive/FailedAttemptRegistry.php` — S2.3-IGNORANCE (§3.3): failed_attempts — персистентная память отказов. Append-only audit trail (S1.10 дух): пе
- `src/Hive/FormInvarianceVerify.php` — S2.2-TER (§3.2.2) measurement: verify_2_2b. Measures the story criterion (conjunctive, prereg 10.10): (a) >=1 
- `src/Hive/FormRegistry.php` — S2.2-TER (§3.2.2): form_registry - the "law about laws" table. One mathematical FORM recurring across unrelate
- `src/Hive/GrammarMutator.php` — GrammarMutator — random mutation of grammar operations (Protocol §2.2). Three equiprobable mutations: - ADD: o
- `src/Hive/Hive.php` — Hive — главный цикл роя. agenda.php → (new Hive(...))->run()
- `src/Hive/IdleDreamer.php` — IdleDreamer — кросс-доменный поиск в idle-тактах (§2.5-децим). Phase 1: когда foundAny=false, IdleDreamer пыта
- `src/Hive/IgnoranceAccuracyVerify.php` — S2.3-IGNORANCE: verify_2_3 — измерение гейта §3.3 протокола. «Система сообщает категорию неудачи... и категори
- `src/Hive/LanguageVerify28.php` — VERIFY-2-8 (protocol par.3.8(g), Core/CV0_Protocol_v1.6_RU.md:1358-1374): language formalization as a runnable
- `src/Hive/LawIsomorphismCompressor.php` — FLOOR-EMERGENCE M1 (EXP-038): LawCompressor в рантайме. §3.8 протокола CV→0: законы L1..Ln из РАЗНЫХ доменов с
- `src/Hive/LawRegistry.php` — Law registry с preservation-аудитом (протокол §2.5.4, диссипативный контур). Реестр законов по поколениям. Ауд
- `src/Hive/OverlapTracker.php` — OverlapTracker — запись попарных сравнений пчёл на общих задачах (§1.8). Когда задача, ранее назначенная пчеле
- `src/Hive/QCalibrator.php` — V0.14 WU-4 (verification-economy): q-калибровка консенсуса. Спека: «q = ceil(θ · median(подтверждений на нулла
- `src/Hive/RecordKeeper.php` — RecordKeeper — запись открытий в БД. Извлечён из Hive::recordDiscovery(). D18: dedup + DB insert + cross-domai
- `src/Hive/ResourceScheduler.php` — RESOURCE SCHEDULER — распределение ресурсов между секторами. Принцип: каждый сектор имеет минимальную квоту (e
- `src/Hive/RouteExecutor.php` — EXP-045 Route Heredity — исполнитель маршрутов. Маршрут = последовательность вылетов Search::find с knob-набор
- `src/Hive/RouteGenome.php` — EXP-045 Route Heredity — геном исследовательского маршрута. Маршрут = программа из управляемых поисковых дейст
- `src/Hive/RouteLedger.php` — EXP-045 Route Heredity — реестр маршрутов прогона: учёт результатов, отзыв ресурса у неудачников, скоринг для 
- `src/Hive/RouteMutator.php` — EXP-045: наследуемые мутации маршрутов. Ровно 5 типов (спека §Мутации): replace / swap / condition / budget / 
- `src/Hive/SeasonScheduler.php` — EXP-039: SeasonScheduler — фазовые пропорции энергии, ролл операций, бюджетный Governor (multi-attempt top-up 
- `src/Hive/SensorState.php` — STRATEGY-TRANSFER prereg rev.3 R2/R3 + rev.4 F4: sensor buffer for the operator-yield channel. Collection is a
- `src/Hive/SpawnManager.php` — SpawnManager — логика спавна и поколений (§2.2, §2.5). Извлечён из Hive::doTick(). D17.
- `src/Hive/TaskGenerator.php` — TaskGenerator — генерация задач (foraged + базовые + cross-pair). Извлечён из Hive::getTasks(). D15: делегируе
- `src/Hive/TaskManager.php` — TaskManager — генерация и фильтрация задач. Извлечён из Hive::getTasks(). Phase 2: только базовые задачи.
- `src/Hive/TaskRouter.php` — TaskRouter — online density-based routing with emergent domains. No hardcoded domain names. No phase separatio
- `src/Hive/TransferStats.php` — STRATEGY-TRANSFER prereg rev.5 §4/§10: comparison-phase statistics. Primary test = exact McNemar on paired slo
- `src/Hive/VerificationExecutor.php` — V0.14 WU-2 (verification-economy): исполнитель V-задач. resample: срез train (data_json) → Search::find → подт
- `src/Hive/VerificationTaskSource.php` — V0.14 WU-1 (verification-economy): источник верификационных задач. Среда порождает V-задачи из сигналов роя: -
- `src/Hive/driver.php` — Независимый 60/40 сплит по сиду (withheld 40% engine не видит).

## СЕРТИФИКАЦИЯ: EnsembleCertifier (ансамблевые серты, null-gate), ContradictionEngine, MetricPreflight

Файлов: 4

- `src/Certification/ContradictionEngine.php` — V0.13 WU-1 (§1.11 v1.7-draft): Contradiction-Derived Certification. ТРИЗ-канон: устойчивое анти-предсказание —
- `src/Certification/EnsembleCertifier.php` — V0.11 (§1.9 v1.7-draft): perturbed ensemble certification. Поднимает сертификат с точечного на структурный: ис
- `src/Certification/MetricPreflight.php` — V0.12 WU-1 (§1.10 v1.7-draft): Metric-Domain Pre-flight. Декларация домена валидности ДО поиска (паттерн §1.2 
- `src/Certification/ShapeTally.php` — V0.11 WU-1: консенсус-таблица форм ансамбля (§1.9). Сигнатура результата члена = канон (ExpressionNormalizer) 

## ФОРДЖЕР: сканер внешних источников → KnowledgeGraph

Файлов: 6

- `src/Forager/DataSelfGenerator.php` — DataSelfGenerator: рой САМ генерирует данные.
- `src/Forager/Forager.php` — DREAM-HARDENING попутно: 3 PHPUnit-warning CongruenceAbsorption/EscrowTest — filesize() E_WARNING на
- `src/Forager/Scanner.php` — Scanner — extracted scanDir() from Forager (D10 Phase 4). Scans a single directory for extractable data patter
- `src/Forager/SemanticFactInserter.php` — SemanticFactInserter — extracted addSemanticFact() from Forager (D10 Phase 3). Inserts (subject, predicate, ob
- `src/Forager/Strategies.php` — Strategies extracted from Forager (D10 Phase 1). Currently a bridge — closures live in Forager until Phase 2.
- `src/Forager/StreamingAccumulator.php` — StreamingAccumulator — extracted scanWithAccumulator() from Forager (D10 Phase 2). Groups same patterns across

## ИНФРА: Database (SWARM_DB), ResourceGuard, логгеры

Файлов: 6

- `src/Infra/CarryingCapacity.php` — §2.6 Spec Addendum: implicit carrying capacity. Критерий: N_max / N_median ≤ 3 за период наблюдения. Экстинкци
- `src/Infra/Database.php` — TYPED-EXECUTE (09.08, ЭКСП-022o): execute(['...', 7]) — PHP PDO связывает int как TEXT → SQLite: INTEGER >= '7
- `src/Infra/GlobalStateGuard.php` — GlobalStateGuard — ловит утечки глобального состояния PHP. D19.1. Отслеживает: ini_set, error_reporting, date_
- `src/Infra/PlateauDetector.php` — PlateauDetector — счётчик тиков без открытий. HONEST_CRITERIA §1.5: когда consecutive_no_discovery >= P, систе
- `src/Infra/ResourceGuard.php` — ResourceGuard v2: измеряет ПРОЦЕССНЫЙ CPU через /proc/self/stat. v1 использовал sys_getloadavg() — системную н
- `src/Infra/RngIsolation.php` — RNG Isolation — предотвращает state leakage (srand poisoning array_rand). ПРОБЛЕМА: PHP использует глобальное 

## ВАЛИДАЦИЯ (устар.: ValidatorInterface пустой)

Файлов: 4

- `src/Validation/LawValidator.php` — LawValidator — held-out validation (HONEST_CRITERIA §1.1). Вынесено из AtomRegistry (SOLID S).
- `src/Validation/NullCalibrator.php` — NullCalibrator — shuffle-based null-distribution calibration. Story V0: Runtime Null-Calibration. Определяет п
- `src/Validation/RetrospectiveValidator.php` — RetrospectiveValidator — ретроспективная проверка законов. Вынесено из AtomRegistry (SOLID S).
- `src/Validation/ValidatorInterface.php` — ValidatorInterface — контракт для валидаторов законов. SOLID I: мелкие интерфейсы для тестируемости.

## БОКОВЫЕ домены: Trading, Text, Math, Knowledge — вспомогательные

Файлов: 6

- `src/Knowledge/ConceptRegistry.php` — ConceptRegistry: отображение хеш → концепт для семантических атомов. Позволяет is_a(hash_s, hash_o) находить ф
- `src/Math/CvCalculator.php` — CvCalculator — вычисление coefficient of variation. Вынесено из AtomRegistry (SOLID S).
- `src/Text/CorpusVocabulary.php` — feat: D4+D5+D6 — ECS (PSR12 + Common + Clean Code), 0 errors, replaces phpcs+php-cs-fixer
- `src/Text/SentenceRegistry.php` — SentenceRegistry: хранит токенизированные предложения из корпуса. Каждое предложение получает ID для быстрого 
- `src/Trading/LiveExecutor.php` — LiveExecutor — чистые функции боевого исполнителя (тестируемое ядро). Без I/O: сигналы, приоритет плеча, объём
- `src/Trading/TradingHive.php` — FIN-005 TRADING-BEES (v6, 15.08): пчёлы торгуют, без законов. АРСЕНАЛ: 10 атомов (мультитаймфрейм r2-r40, vol,

## scripts/ (111 файлов, по группам)

### bd_* — battery/data-пробники (старый блок B2) (5)

- `bd_probe.php` (2026-11-17) — Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- `bd_seed_sweep.php` (2026-11-17) — V0.17 WU-2b: подбор сида для BD-фикстуры — phpunit-окружение (env как в xml).
- `bd_triage.php` (2026-11-17) — V0.17 WU-2b триаж: воспроизвести clean1-провал в одном процессе:
- `bd_triage2.php` (2026-11-17) — V0.17 WU-2b триаж-2: beam-гипотеза. beam=0 → Search::find на
- `bd_triage3.php` (2026-11-17) — V0.17 WU-2b триаж-3: изолирую носителя. Три ветки:
### exp031 серия (эксперименты EXP-31) (2)

- `exp031_retrieval.php` (2026-10-18) — EXP-031: Top-K Residual Cultural Retrieval (предрегистрирован 26.08). Только feynman_heat. 20 seeds.
- `exp031_v2.php` (2026-10-18) — EXP-031-v2: Contextual Cultural Retrieval — ПОЛНАЯ формула relevance. R(z|e) = ΔCV_inner(z|e) − λ·C(
### exp032 серия (эксперименты EXP-32) (1)

- `exp032_spreading.php` (2026-10-18) — EXP-032: Spreading Activation Proof of Concept Минимальный эксперимент: предзаполненный граф полезны
### exp033 серия (эксперименты EXP-33) (1)

- `exp033_grounded.php` (2026-10-18) — EXP-033: Residual-Guided Grounded Graph — 4 режима сравнения. A. Random symbolic search (baseline) B
### exp035 серия (эксперименты EXP-35) (3)

- `exp035_phase3.php` (2026-10-18) — EXP-035 Фаза 3 unit: b_quad-задача (y=(x0+x1)×x2). Частичная гипотеза (x0+x1) слабой пчелы → B-канди
- `exp035_phase3b.php` (2026-10-18) — EXP-035 фаза 3b v3 (27.08): bornBinary-путь (улья-семантика). Цель: y = (x0+x1)×x2×x3/x4 — сырая глу
- `exp035_phase4_heat.php` (2026-10-18) — EXP-035 Фаза 4 (28.08): E2E heat 20 seeds — честное сравнение с PySR. ЧЕСТНОСТЬ (правило EXP-028): о
### exp037 серия (эксперименты EXP-37) (1)

- `exp037_freeze_vs_explore.php` (2026-10-18) — ЭКСП-037: «Заморозка vs разведка» культурного буста (T5-post-3, 05.09). Pre-registered гипотезы (ДО 
### exp039 серия (эксперименты EXP-39) (1)

- `exp039_run.php` (2026-10-18) — EXP-039: драйвер прогона одной конфигурации. PREREG.md §8 + PREREG_ADDENDUM_BUDGET.md (A2/A3/A6/A8).
### exp054 серия (эксперименты EXP-54) (1)

- `exp054_templates.php` (2026-11-17) — LEVEL B literal rule (prereg section 2): one non-leaf subtree -> '_' at a time + the fully wildcarde
### exp055 серия (эксперименты EXP-55) (2)

- `exp055_pilot.php` (2026-11-17) — Level-B template set of L1 exact terms, instantiated over width columns.
- `exp055_smoke.php` (2026-11-17) — EXP-055 mechanics smoke (pre-build verification): can a probe write rungs
### exp056 серия (эксперименты EXP-56) (2)

- `exp056_pilot.php` (2026-11-17) — EXP-056: progress-reward stigmergy on C_deep (prereg: Benchmarks/EXP056_progress/).
- `exp056_probe.php` (2026-11-17) — ALL Level-B instantiations of the L1 template set over the task width.
### exp057 серия (эксперименты EXP-57) (1)

- `exp057_pilot.php` (2026-11-17) — EXP-057: composition layer (owner ladder level 4; prereg exp057_prereg.md).
### exp058 серия (эксперименты EXP-58) (1)

- `exp058_pilot.php` (2026-11-17) — EXP-058: relevance-gated road admission. Prereg: Benchmarks/EXP058_road_gate/exp058_prereg.md
### exp059 серия (эксперименты EXP-59) (1)

- `exp059_pilot.php` (2026-11-17) — EXP-059: tail budget-response probe. Prereg: Benchmarks/EXP059_tail_budget/exp059_prereg.md
### exp060 серия (эксперименты EXP-60) (2)

- `exp060_pilot.php` (2026-11-17) — EXP-060: residual structure test. Prereg: Benchmarks/EXP060_residual/exp060_prereg.md
- `exp060_test1_forced_merge.php` (2026-11-17) — EXP-060 Test 1: forced merge on seed 404 (Class A - missing x5, x5 on the road).
### exp063 серия (эксперименты EXP-63) (1)

- `exp063_diversity_ab.php` (2026-11-17) — EXP-063: diversity-retrieval A/B. Prereg: tests/TrailReadDiversityTest.php (EXP-063 prereg)
### exp065 серия (эксперименты EXP-65) (2)

- `exp065_b1_chain.php` (2026-11-17) — EXP-065 / B1 rev.2: mark-chain positive control. Prereg FROZEN sha256:0b8d0f88,
- `exp065_b2_pilot.php` (2026-11-18) — Одна ступень дороги за вызов (канон: групповой засев cull'ится капом).
### exp066 серия (эксперименты EXP-66) (2)

- `exp066_feynman.php` (2026-11-18) — EXP-066: семья Feynman20 — независимый бенчмарк (pmlb × Feynman SR DB).
- `exp066_telescope.php` (2026-11-18) — EXP-066 телескоп: где умирает ((x0×x1)+(x2×x3)) в L2-BEAM на I_18_4.
### exp067 серия (эксперименты EXP-67) (1)

- `exp067_probe.php` (2026-11-19) — EXP-067 B4: пробник необходимости (spec: Benchmarks/feynman20/B4_PROBE_SPEC.md). Классификация задач
### probe* — пилоты-пробы (25)

- `probe_bar_e3.php` (2026-11-17) — Диагноз WU BAR-E3 (вариант a): бар = :1980 plausible-потолок.
- `probe_cert_v2.php` (2026-11-17) — WU DRIVER-CERT-V2: probe cert_v2 по driver-канону split_eval.
- `probe_depth_audit.php` (2026-11-17) — EXP-045 ШАГ IV AUDIT (owner: «depth=3 доходит до Search?» — гейт программы genome transfer). Однораз
- `probe_dot4_baseline.php` (2026-11-17) — EXP045-0h (флаг owner): baseline DOT4 в конфигурации driver (2-3 полёта, budget 8s = BASELINE_SHARE 
- `probe_eval_check.php` (2026-10-19) — EXP-045 D4 LOOP PROBE — eval-check harness (xdebug trace target). Один маршрут-лестница на dot; посл
- `probe_fitness_check.php` (2026-11-17) — EXP045 0h fitness-check (флаг owner, прекондиция Stage A): различает ли текущий score (mean_delta + 
- `probe_handseed.php` (2026-11-17) — EXP-045 D4 LOOP PROBE (prereg v2, PREREG_D4_LOOP_PROBE.md) — одноразовый экспериментальный скрипт, Н
- `probe_labels_to_x.php` (2026-11-17) — 0h no_eval: проверка labels_to_x (width-agnostic c→x) на классах форм.
- `probe_loop.php` (2026-11-17) — EXP-045 D4 LOOP PROBE (prereg v2, PREREG_D4_LOOP_PROBE.md) — одноразовый экспериментальный скрипт, Н
- `probe_mutator_reach.php` (2026-11-17) — EXP045 0h-чеки A/C (флаг owner, до E1): A. Reachability: создаёт ли мутатор F2 M F2 из F2F2M (SWAP/R
- `probe_partial_null.php` (2026-11-17) — BUGFIX-верификация 31.10: parse_csv ширина-агностична, labels = ширина X.
- `probe_seed102_cv.php` (2026-10-20) — EXP-045 сид-102 диагноз (owner GO 19.10, вне критериев шага II): гипотеза 1 (данные: ratio-cv канона
- `probe_a19_baseline.php` (2026-10-18) — Проба A19: где именно константа/околоконстанта проходит гейт (EXP-040 wine-класс)
- `probe_a19_gate_live.php` (2026-10-18) — Проба A19-gate на heldout-пути (testRatio=0.2, как DiscoveryEngine): точные законы y=x0+x1, y=x0*x1,
- `probe_autodecomp.php` (2026-11-17) — AUTODECOMPOSITION (owner (3), 05.11): the system splits C itself.
- `probe_c_decompose.php` (2026-11-17) — C-DECOMPOSE PILOT (owner 05.11): does C = x0*x1 + x3*x4^2 + x5 contain
- `probe_c_decompose2.php` (2026-11-17) — C-DECOMPOSE PILOT v2 (owner fallback: hand-crafted C). T1 = term-level
- `probe_cell_one.php` (2026-10-18) — A19-bisect probe: ОДНА клетка, один seed — изоляция виновника (gate vs tolerance vs pre-existing). К
- `probe_chain_heat.php` (2026-11-17) — CHAIN PROBE (owner step-2, 05.11): the DOT4 mechanism on heat@301.
- `probe_gravity_depth5.php` (2026-10-18) — A19.3 acceptance (BUG-EXP040-EXACT-FLOOR.md «Repro»-критерий): php probe_gravity_depth5.php 5 1 600 
- `probe_islands.php` (2026-11-17) — ISLAND-DECOMPOSITION PILOT (owner step-2, 05.11): per-block islands evolve
- `probe_precheck.php` (2026-11-17) — PRE-CHECK (owner): how many DIFFERENT partials on the composite in ONE pass?
- `probe_shape_sweep.php` (2026-11-17) — SHAPE SWEEP (owner 0h-check): does ANY route shape certify heat@301?
- `probe_spurious.php` (2026-11-17) — SPURIOUS-FORMS PILOT (owner 05.11): false certifications under noise.
- `probe_spurious_v2.php` (2026-11-17) — Evolve+certify on ONE island task; return distinct certified X-forms.
### verify/* — верификационные (24)

- `verify_e3.php` (2026-11-17) — WU E3-VERIFY (owner 0h: 4 проверки cert-качества формы ((c0×c1)+(c2×c3))).
- `verify_noise.php` (2026-11-17) — WU CERT-V2-CALIB шаг 1 (reviewer: измерить CV(full+noise) до freeze).
- `verify_0_1.php` (2026-10-18) — verify_0_1.php — Held-Out Validation (§1.1) Протокол требует: count(OVERFIT)=0 И count(DISCOVERY)>0.
- `verify_0_2.php` (2026-10-18) — verify_0_2.php — Statistical Sufficiency (§1.2) Дух критерия: система не должна искать законы на нед
- `verify_0_3.php` (2026-10-18) — verify_0_3.php — Parsimony / Occam's Razor (§1.3) Дух критерия: среди формул с эквивалентным CV для 
- `verify_0_4.php` (2026-10-18) — verify_0_4.php — Non-Triviality (§1.4) Дух критерия: закон не должен быть тавтологией или тривиально
- `verify_0_5.php` (2026-10-18) — verify_0_5.php — Plateau Honesty (§1.5) Проверяет: 1. PLATEAU появляется в логе 2. Нет открытий во в
- `verify_0_6.php` (2026-10-18) — verify_0_6.php — Deduplication (§1.6)
- `verify_0_7.php` (2026-10-18) — verify_0_7.php — Compression Superiority (§1.7) Дух критерия: сложная формула должна окупать свою сл
- `verify_0_8.php` (2026-10-18) — verify_0_8.php — Overlap Awareness (§1.8) Проверяет: overlap_log содержит записи, и хотя бы одна пар
- `verify_0_null.php` (2026-10-18) — verify_0_null.php — System Null-Calibration (§0.7) Генерирует 200 shuffle-прогонов на ≥15 доменах. F
- `verify_1_1.php` (2026-10-18) — verify_1_1.php — Реальная смерть (§2.1) Проверяет: ≥1 смерть за период, все смерти коррелируют с E≤0
- `verify_1_11.php` (2026-10-18) — V0.13 WU-6: verify_1_11 — Contradiction-Derived Certification (§1.11). Проверяет контракт детект→инв
- `verify_1_2.php` (2026-10-18) — verify_1_2.php — Реальное рождение (§2.2) Проверяет: ≥3 spawn за период, родитель≠потомок, грамматик
- `verify_1_3.php` (2026-10-18) — verify_1_3.php — Grammar Isolation (§2.3) Проверяет: грамматики пчёл изолированы. Нет необъяснённых 
- `verify_1_4.php` (2026-10-18) — verify_1_4.php — Конкурентное распределение задач (§2.4) Проверяет: распределение не равномерно (раз
- `verify_1_5.php` (2026-10-18) — verify_1_5.php — Эволюционная динамика (§2.5) Проверяет: generation tracking активен, diversity > 0.
- `verify_1_6.php` (2026-09-13) — verify_1_6.php — Environmental Pressure (§2.6) Проверяет на логе демона (agenda.log): (a) ≥1 задача 
- `verify_1_9.php` (2026-10-18) — verify_1_9.php — WU-5 V0.11: Perturbed Ensemble Certification (§1.9). Калибровочный прогон EnsembleC
- `verify_all.php` (2026-10-18) — verify_all.php — Stage 0 Gate Запускает все verify_0_* скрипты. Stage Gate logic: verify_0_null FAIL
- `verify_2_2b.php` (2026-10-09) — VERIFY-2-2b CLI wrapper (thin, per EnvPressureVerify precedent). S2.2-TER (§3.2.2) form invariance: 
- `verify_2_3.php` (2026-10-10) — S2.3-IGNORANCE: verify_2_3 CLI — thin wrapper (logic in IgnoranceAccuracyVerify).
- `verify_2_8.php` (2026-10-09) — VERIFY-2-8 CLI wrapper (thin, per EnvPressureVerify precedent). Protocol par.3.8(g) v1.5 (non-strict
- `verify_residual.php` (2026-10-18) — verify_residual.php: RESIDUAL LADDER protocol (P4 formalized).
### разовые скрипты (77)

- `DormantPoolCapacityTest.php` (2026-11-17) — DORMANT-CONFIG (24.09, premortem И-5): мёртвый $maxDormant — env DORMANT_POOL_MAX вычислялся «для OO
- `a19_exact_calibration.php` (2026-10-18) — A19.4 — калибровка scale-aware exact tolerance (BUG-EXP040-EXACT-FLOOR). Grid: 6 синтетических закон
- `baseline_random.php` (2026-08-08) — BASELINE: random search vs systematic Search::find Стори: BASELINE-RANDOM-SEARCH Сравниваем на одина
- `bee_benchmark_v3.php` (2026-10-18) — ЭКСП-027 v3: Bee Swarm часть — тот же frozen split (seed 42, 60/40), те же метрики (CV_train/CV_hold
- `congruence_selection.php` (2026-10-18) — T5 (theorem-level): congruence-selection — наш gate vs AIC/BIC паттерн. SSE-бумага (Sukhodolov et al
- `fp_by_layer.php` (2026-10-18) — T3 (theorem-level): FP-таблица по слоям защиты (04.09). Вопрос: какой слой и сколько отсекает ложных
- `benchmark_capability.php` (2026-08-14) — S1.6 CAPABILITY BENCHMARK (2.5-бис, 14.08). 20 выразимых задач (калибровочный класс). Два замера уль
- `boundary_probe.php` (2026-11-17) — BOUNDARY PROBE (07.11): at what sigma does the TRUE form pass cert_v2?
- `culture_2b.php` (2026-10-18) — EXP-029 волна 2b: только heat + dot (культура, inline L3L1) + недостающие concrete/airfoil/energy (в
- `culture_benchmark.php` (2026-10-18) — EXP-029: КУЛЬТУРНЫЙ БЕНЧМАРК (предрегистрация 25.08.2026). Фаза A: простая задача → Search::find нах
- `culture_compress.php` (2026-10-18) — EXP-030: Culture as Semantic Compression (предрегистрирован 26.08). B templates (заморожены): SUB(a,
- `deploy-version.php` (2026-08-08) — scripts/deploy-version.php — защита от scp в обход deploy.sh deploy.sh создаёт .deploy_ok после успе
- `diag_spurious.php` (2026-11-17) — SPURIOUS-PILOT DIAGNOSTIC (07.11): why certs_train = 0 everywhere.
- `diagnose_all_classes.php` (2026-08-08) — Расширенная диагностика ВСЕХ классов задач (01.08.2026). Проверяем не только metrics-пары, но и: 1. 
- `diagnose_metrics.php` (2026-08-08) — Диагностика достижимости CV→0 на реальных метриках (01.08.2026). Эксперты №1/№2/№3: ПЕРЕД расширение
- `escrow_orphans_report.php` (2026-10-18) — ESCROW-ORPHAN-DEPOSITS WU-1 (prereg 17.10): отчёт о висячих депозитах. READ-ONLY: PDO sqlite ?mode=r
- `driver.php` (2026-10-18) — EXP-044 driver v3 (final corrections per owner gate 13.10):
- `gen_families.php` (2026-10-18) — EXP-044 freeze: emits exp044_families.json (train D1-D8 + sealed test H1-H6)
- `RouteGenomeTest.php` (2026-11-17) — EXP-045 S5: схема генома + валидность всех 5 мутаций.
- `RouteLedgerTest.php` (2026-11-17) — EXP-045 S4: quota-account — учёт исходов, revoke неудачников, скоринг.
- `TrailReadersTest.php` (2026-11-17) — AMENDMENT_STAGE_A read-priority quorum (спека §2.3): distinct readers per mark, cert_use bump по сер
- `build_hand_genomes.php` (2026-11-17) — Билдер hand-геномов Stage 2 (контрфактический тест функциональности).
- `cert_forms_multisplit.php` (2026-11-17) — CERT-V2 калибровка: 18 cert_zone-форм из JSON -> 5 независимых сплитов
- `cert_utils.php` (2026-11-17) — Независимый 60/40 сплит по сиду (withheld 40% engine не видит). Копия из driver.php — канон сплит-ло
- `cert_v2_null100.php` (2026-11-17) — WU CERT-V2 FREEZE (01.11, аналитик): null-FPR до 0/100.
- `driver.php` (2026-11-17) — Базовый канал: вылеты с выключенными метками (гигиена baseline).
- `gen_dev_families.php` (2026-11-17) — EXP-045: генератор семейств задач.
- `gen_e2_families.php` (2026-11-17) — EXP-045 E2: жёсткое семейство задач («экологическая ниша», ветка (c)
- `gen_strategy_families.php` (2026-11-17) — STRATEGY-TRANSFER campaign pool (prereg rev.5, class-table freeze).
- `step2_aggregate.php` (2026-10-19) — EXP-045 ШАГ II A/B DOT — агрегатор (prereg DOT_VALUE_PROBE критерии неизменны). CLI: php step2_aggre
- `strategy_driver.php` (2026-11-17) — Arm A: integrator condition generator (frozen params, random class).
- `wip_TrailFixTest.php` (2026-10-20) — СТАТУС 20.10: OOM-Killed при прогоне даже в облегчённом виде (причина не выяснена — инфра-вопрос); ф
- `gate_scan.php` (2026-11-17) — Gate-only scan: cert_v2 of the TRUE island forms across all 15 grid seeds.
- `benchmark.php` (2026-10-18) — GENOME-001 CHECKPOINT B: замороженный compression benchmark. Реализация Benchmarks/genome001/CHECKPO
- `corpus_lib.php` (2026-10-18) — GENOME-001 CHECKPOINT B: библиотека корпуса. Все параметры заморожены в Benchmarks/genome001/CHECKPO
- `decode_execute.php` (2026-10-18) — GENOME-001 CHECKPOINT A: subprocess-раннер чистой линии. Принимает envelope JSON: {db, genome, rows,
- `make_families.php` (2026-10-18) — GENOME-001: генерация артефакта словаря+геномов из РЕАЛЬНЫХ T₁/T₂ (PROBE-1, канон post-MOL-001). Fre
- `plots.php` (2026-10-18) — GENOME-001 CHECKPOINT B: SVG-графики из ЗАМОРОЖЕННЫХ сырых данных (checkpoint_b_raw.json). Пост-обра
- `benchmark.php` (2026-10-18) — GENOME-002: замороженный сравнительный прогон (манифест MANIFEST.md). Один прогон = один артефакт: c
- `evolve_lib.php` (2026-10-18) — GENOME-002 EVOLVABILITY PROBE: библиотека мутаций и измерителя полезности. Гипотеза (owner): наследу
- `pilot.php` (2026-10-18) — GENOME-002 DEV-пилот: работоспособность мутаций и измерителя полезности ДО заморозки (рамка owner). 
- `h2lite_pilot.php` (2026-11-17) — E48-faithful warm chain: 8 ladders, marks accumulate in the process DB. Returns distinct certified f
- `island_fc_one.php` (2026-11-17) — EXP-051-B island-level false-cert scan, ONE (sigma,seed) PER PROCESS
- `island_fc_scan.php` (2026-11-17) — EXP-051-B island-level false-cert scan (07.11): certs exist ONLY at the
- `prec_test.php` (2026-11-17) — Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- `driver.php` (2026-10-18) — PROBE-0: KNOWLEDGE → ENVIRONMENT — драйвер (см. PREREG.md + AMENDMENT.md v2).
- `flight.php` (2026-10-18) — PROBE-0: single-flight subprocess runner (изоляция env + захват подсчёта кандидатов).
- `gen_families.php` (2026-10-18) — PROBE-0: генератор семейств. v5 — класс sdr: y = (c0+c1)/(c0−c1).
- `compress.php` (2026-10-18) — PROBE-1: компрессор в свежем процессе.
- `driver.php` (2026-10-18) — Арность определения (distinct xN).
- `gen_families.php` (2026-10-18) — PROBE-1: генератор семейств (DEV mechanistic gate, v1).
- `repro_roundtrip.php` (2026-10-18) — MOL-001: воспроизведение roundtrip-дефекта (до фикса).
- `smoke.php` (2026-10-18) — scripts/smoke.php — PRODUCTION SMOKE TEST Запускается на production-машине после деплоя (SSH+SCP или
- `tsp_demo.php` (2026-10-18) — TSP DEMO — эволюционный фреймворк (тот же класс, что bee swarm): геном = перестановка городов, фитне
- `tsp_ga_vs_greedy.php` (2026-10-18) — GA vs Greedy+2opt на 10 инстансах TSP (20 городов, разные seed). Цель: найти инстанс, где эволюция (
- `v018_tick_calibration.php` (2026-10-18) — V0.18 WU-2: калибровочная таблица тики ↔ секунды (TICK_BUDGET). Анти-подгонка (буква стори): скрипт 
- `v018_tick_calibration2.php` (2026-10-18) — V0.18 WU-2: калибровочная таблица тики ↔ секунды (боевой профиль). Запуск: php scripts/v018_tick_cal
- `v14_dashboard.php` (2026-10-18) — V0.14 WU-6: дашборд наблюдаемости verification-economy. Query поверх существующих таблиц (никаких за
- `v14_dashboard_smoke.php` (2026-09-14) — Smoke-фикстура WU-6: сеять 3 закона + escrow + tasks в temp-БД, потом дашборд читает.
- `v14_wu5_ccpp_debug.php` (2026-11-17) — WU-5 CCPP дебаг: почему V-задачи resample рефалят закон ((AT/RminAT)−RmaxAT).
- `v14_wu5_concrete_debug.php` (2026-11-17) — WU-5 Concrete дебаг: живой путь GRAMMAR при том, что прямой find находит (cv=0.074).
- `v14_wu5_debug.php` (2026-11-17) — WU-5 дебаг: почему живой путь в прогоне не находит dot на depth 3.
- `v14_wu5_debug2.php` (2026-11-17) — WU-5 дебаг-2: прогон doTick как в драйвере, но с трассировкой depth.
- `v14_wu5_debug3.php` (2026-11-17) — WU-5 дебаг-3: полный прогон одного тика с трассировкой env ВНУТРИ doDiscoverTick через debug_backtra
- `v14_wu5_debug4.php` (2026-11-17) — WU-5 дебаг-4: ВНУТРИ doDiscoverTick перехватываем args DiscoveryEngine::discover.
- `v14_wu5_probe.php` (2026-10-18) — WU-5 probe: что находит Search::find на срезах доменов (до включения экономики). Сложность задачи пр
- `v14_wu5_run.php` (2026-10-18) — V0.14 WU-5: драйвер эмерджентных прогонов verification-economy. Механика: живой Hive (bootstrap → ин
- `vc_bounds.php` (2026-10-18) — VC-BOUNDS: мощность класса выражений и верхняя граница VCdim. Стори: VC-BOUNDS Класс выражений: L0 (
- `vclp_backtest.php` (2026-10-08) — VERIF-COLLABEL-PARITY WU-4: бэктест 270 refuted + 198 pending из sandbox.db. Работает на КОПИИ (sand
- `calibrate_preflight.php` (2026-10-18) — V0.12 WU-5: калибровка FACTOR (параметр (C) §1.10). Sweep CV(y) от 0.2·ε до 5·ε при ε=0.15: для кажд
- `contradiction_ccpp_live.php` (2026-10-18) — V0.13 WU-5: CCPP live-эксперимент (§1.11) — ПРЕ-РЕГИСТРАЦИЯ ожиданий. Пре-зарегистрированные ожидани
- `checkpoint_a.php` (2026-10-18) — WORD-CUM-001 CHECKPOINT A: машинный артефакт сценария (дублирует tests/WordCum001Test.php; тест — ин
- `diag001.php` (2026-10-18) — EVO-DIAG-001 CHECKPOINT B: диагностическая абляция границы W1→W2. Факторный дизайн 2×2 (INHERITED/FR
- `diag002.php` (2026-10-18) — EVO-FIX-001 Часть B: проверка качества отбора после исправлений. Граница W1→W2, 6 парных сидов, 5 по
- `evolution.php` (2026-10-18) — WORD-CUM-001 CHECKPOINT B: минимальный многопоколенный цикл (AMENDMENT v2). Population → Mutation → 
- `transition.php` (2026-10-18) — WORD-CUM-001 / EVO-FIX-001 FIX-1: целостность fitness при смене мира. transitionReevaluate(): при пе
- `worlds.php` (2026-10-18) — WORD-CUM-001 CHECKPOINT B: замороженные миры (AMENDMENT v2 §A1-A3, A6). Истинные формулы существуют 
