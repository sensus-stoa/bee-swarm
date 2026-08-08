# CV→0 Bee Swarm — Architecture Specification v4

> 08.08.2026 | Architect: Dolgov Evgeniy V. | Co-Architect: Hermes
> Implementation of the CV→0 Autonomous Evolution Protocol (DOI: 10.5281/zenodo.21810056)

---

## 0. Principle

```
ONE mechanism: variation + CV→0 + environment = evolution.
At every layer. No exceptions.
```

CV = σ/μ → 0. A law is an expression that makes ALL data relationships constant.
CV > threshold = chaos, do not spend resource. CV → ∞ = anti-structure, abandon.

**NOT LLM. NOT ML. NOT statistics.** Symbolic search with falsifiability.

The system does not maximize prediction accuracy. It tests structural stability:
an expression is a law iff it survives held-out validation, null-calibration,
and compression tests. And it refuses to guess when it cannot verify.

---

## 1. Core components (v4)

| Component | File | Role |
|-----------|------|------|
| Hive | `src/Hive/Hive.php` | Main loop: bootstrap → tick → route → discover → energy → spawn |
| Bee | `src/Hive/Bee.php` | Autonomous agent: energy lifecycle, heritable energy params, grammar mutation |
| Search | `src/Core/Search.php` | CV→0 search: L0/L1/L2 generation, held-out, affine shift (EXP-012) |
| Grammar | `src/Core/Grammar.php` | BASE_OPS + dynamic ops from DB + B-atoms (GRAMMAR-BIRTH) |
| DiscoveryEngine | `src/Hive/DiscoveryEngine.php` | Candidate pipeline: search → candidates → held-out |
| TaskRouter | `src/Hive/TaskRouter.php` | Density-based routing by task fingerprint |
| TaskGenerator | `src/Hive/TaskGenerator.php` | Compose tasks + foraged tasks |
| Forager | `src/Forager/Forager.php` | Files → tasks (DataSource abstraction, streaming accumulator) |
| NullCalibrator | `src/Validation/NullCalibrator.php` | Permutation null-calibration per fingerprint |
| OverlapTracker | `src/Hive/OverlapTracker.php` | §1.8 pairwise answer overlap |
| PlateauDetector | `src/Infra/PlateauDetector.php` | Plateau detection → gap-spawn trigger |
| SpawnManager | `src/Hive/SpawnManager.php` | Spawn, gap-spawn, generation tracking, diversity |
| GrammarMutator | `src/Hive/GrammarMutator.php` | Spawn mutation + GRAMMAR-PROPAGATION weights |
| RecordKeeper | `src/Hive/RecordKeeper.php` | Laws DB, dedup, cross-domain detection |
| ClozeEngine | `src/Hive/ClozeEngine.php` | Text cloze tasks (mask + target) |
| IdleDreamer | `src/Hive/IdleDreamer.php` | Cross-domain compose in idle |
| CorpusVocabulary | `src/Text/CorpusVocabulary.php` | Word → ID (max 5000, ≥3 chars, no digits) |
| SentenceRegistry | `src/Text/SentenceRegistry.php` | Sentences → ID (max 1000) |
| ResourceGuard | `src/Infra/ResourceGuard.php` | CPU/MEM guards |
| Database | `src/Infra/Database.php` | SQLite singleton (WAL), migrations |

---

## 2. Main loop (Hive::run)

```
bootstrap()
├── loadPopulation() — restore from bee_persistence (survives restarts)
└── if none: BootstrapManager → 3 seed bees (§0.6)
    ├── Forager startup scan
    ├── Retrospective validation of known laws
    └── Corpus (words, sentences)

doTick() — per tick:
├── MemoryGuard (256MB default, gc_collect_cycles)
├── CPU guard (sys_getloadavg > 0.7 → sleep)
├── Forager rescan (every 100 ticks, plateau-triggered)
├── TaskRouter → weightedPick → consumeTask
├── Energy loop: bee->tick(), hungerMutate at E<5, DEATH log
├── SpawnManager: trySpawn (E≥15) + tryGapSpawn (plateau)
├── D_RATIO / D_ACT telemetry
├── doDiscoverTick: Search::find → candidates → recordDiscovery
├── doClozeTick: cloze tasks (text layer)
├── Text atom discovery from raw content
├── idleDreamTick: cross-domain compose when nothing found
└── Overlap tracking
```

## 3. Energy economy (Bee)

| Parameter | Default | Description |
|-----------|---------|-------------|
| tickCost | 0.01 | Base metabolism per tick |
| searchCost | 0.1 | Cost per search attempt |
| discoveryReward | 2.0 | Reward for a discovered law |
| informationReward | 0.0 | Intrinsic value of information (evolvable, §2.1-evo) |
| SPAWN_THRESHOLD | 15.0 | Parent energy required to spawn |
| SPAWN_CHILD_ENERGY | 7.0 | Energy given to child |
| SPAWN_PARENT_COST | 7.0 | Energy taken from parent |

Energy params are **heritable and evolvable**: spawn mutates them ±20% (bounded).
Natural selection optimizes params per environment.

**Key economics findings (EXP-017..022d, 07-08.08.2026):**
- The observed "grammar monoculture" (|G|=1) was an **artifact of expensive Search**
  (slow ticks → starvation by time), NOT a property of evolution.
  With cheap beam search, expressive grammars (|G| up to 89+) survive without
  diversity-maintenance mechanisms (EXP-019/020/022d).
- SEARCH-BEAM K=10: ×5.2 speedup, laws 10.5=10.5 (no loss) — Pareto point.
- Reward per unique formula = infinite food (hundreds of reformulations of one law
  each give +reward). Design fix: reward per **law class** (canonical normalization),
  not per formula candidate — core of ECONOMICS-OF-DIVERSITY.

## 4. GRAMMAR-BIRTH (EXP-015)

Bottom-up operator birth from verified discoveries:

```
discovery (CV>0.001, not R-formula, length ≥5)
    → birthOperator(): formula → B{hash} atom in grammar_ops
    → B8a2975 => floor(rad2deg) (first birth, 06.08.2026)
    → registerReuseOps(): reuse_count, birth_domain, reuse_domains
    → B-atoms enter unary pool (cap 30)
```

In contrast to Koza's ADF (1994) — which allocates function-defining branches
top-down in the architecture — GRAMMAR-BIRTH is bottom-up: operators are born
from expressions that passed held-out validation and null-calibration.
The grammar extends itself from verified data, not from designer decisions.

## 5. Null-calibration (V0)

Every structural task fingerprint is calibrated against ≥100 shuffled
permutations. If any null-run produces a "discovery", thresholds tighten
until FPR_system = 0. Fallback epsilon: 0.15.

## 6. Structural refusal

When no expression achieves CV→0, the system diagnoses the cause:
- `GRAMMAR` — required operator is inexpressible
- `DATA` — insufficient points (tMin = max(10, nFeat×5))
- `NOISE` — target is fundamentally unpredictable
- `DEPTH` — expression exists but requires deeper search

The system does not retreat to a weaker answer. It says "I don't know — and
here is what I need in order to know."

## 7. Text layer

```
Text → CorpusVocabulary (word→ID, max 5000) → SentenceRegistry (max 1000)
     → cloze tasks (mask + target) → CV = error rate
```

Semantic grammar: `is_a`, `has`, `relates_to`, `can` — atoms over knowledge_graph.
Forager → KG: INSERT OR IGNORE (conf=0.3), repeated → confidence +0.25 (cap 1.0).
4+ sources → confidence=1.0 → is_a atom returns 1.0 → CV=0.

## 8. Population persistence

`savePopulation()` on shutdown (register_shutdown_function), `loadPopulation()`
on bootstrap. The swarm survives restarts.

## 9. NOT used

- ❌ Neural networks, gradients, datasets
- ❌ LLM as verifier (LLM proposes, CV→0 decides)
- ❌ Hardcoded curated atom lists (alphabet from environment)
- ❌ User-in-the-loop for modules, search, verification, goals

## 10. Principles

1. CV→0 is the only criterion. Above null floor = chaos.
2. Less is better. Extra modules create noise.
3. Grammar grows bottom-up through verified discoveries + compose. Not MetaInventor.
4. Hunger instead of timers: the system reacts to its OWN state.
5. Penalty for triviality: constant energy 0.03, complex formula 0.15.
6. Popper: a task without negative examples is not falsifiable.
7. TDD: test → code → daemon. Always.
8. Forager → KG → is_a → CV→0: closed semantic loop.
9. Corpus limits: 5000 words, 1000 sentences — else it hangs.
10. Einstein: as simple as possible, but not simpler. 6 components → 3.

## 11. Key numbers (as of 08.08.2026)

```
Architecture:       v4 (Hive monolith + Bee + TaskRouter)
Tests:              500+ TDD tests
Experiments:        EXP-001..022d (experiments-log)
Stage:              0 ~85%, 1 running (v1.3, 06.08→13.08), 2 mapped, 3 specified
Beam search:        K=10, rand=5 (Pareto point)
Memory guard:       256MB default
```
