# Bee Swarm Stories

## In Progress

| # | What | Status |
|---|------|--------|
| **V0** | **STAGE-0-VERIFY — формальная верификация Стадии 0** | ✅ 9/9 PASS (production, 24h gate). |
| **V0.8** | **OVERLAP-TRACKING — алгебраическая редукция** | ✅ 4 фазы (включая reduceAnswer). |
| **V0.9** | **24H-PRODUCTION-GATE — непрерывный суточный прогон** | ✅ 24h пройдены, Stage 0 подтверждён. |
| **V1** | **STAGE-1-VERIFY — формальная верификация Стадии 1** | 🔧 2/5 verify. 3 pending (data ceiling). |
| **V0.8.5** | **LAW-CLASSIFICATION — IDENTITY vs EMPIRICAL gate** | ⬜ 4 фазы (2.5h). |
| **V0.10** | **HIVE-WATCHER — структурный мониторинг улья** | ⬜ 4 фазы (3.5h). |
| **E1-FIX** | **TEXT-LAW-PIPELINE — текст-атомы→законы** | 🔧 Phase 1-3 done. Phase 4 ⬜ (Obsidian vault). |
| **S2.7** | **LAZY-CROSSPAIR — генератор вместо O(N²) массива** | ✅ 504/504 PASS, 18MB память (было 9GB). |
| **S1.6** | **WEIGHTED-TASKS — взвешенный отбор (nFeat=1 чаще)** | ⬜ RED. |

## Completed

| # | What | Status |
|---|------|--------|
| V1.3 | GRAMMAR-ISOLATION — per-bee grammar (§2.3) | ✅ 5 фаз |
| D14 | HIVE-DECOMPOSE — extraction + wiring | ✅ 7 фаз (extraction + wiring). Hive 1037→906. |
| S2.1 | BOOTSTRAP-FIX — N=3 seed bees | ✅ |
| S2.2 | IDLE-DREAMING — cross-domain compose | ✅ Phase 1. Phase 2-5 backlog. |
| — | INFORMATION-REWARD — intrinsic value of search | ✅ (Nature Neuroscience 2026) |
| — | ALGEBRAIC-REDUCTION — overlap answer comparison | ✅ (V0.8 Phase 4) |

## Stage 1 — Population (Backlog)

| # | Criterion | What | Сложность |
|---|-----------|------|-----------|
| **S1.1** | 2.1 Bee death | Energy model + starvation | ⭐ |
| **S1.2** | 2.2 Bee birth | Spawn + grammar mutation (SPAWN_THRESHOLD fix) | ⭐⭐⭐ |
| S1.3 | 2.3 Grammar isolation | Per-bee grammar (no shared state) | ✅ |
| S1.4 | 2.4 Task routing | Density-based routing | ✅ |
| **S1-WIRE** | — Hive population wiring | Bee+TaskRouter+RoadRunner → живая популяция | ⭐⭐⭐⭐ |
| **S1.5** | 2.5 Evolutionary dynamics | Grammar compression + diversity | ⭐⭐⭐ |
| **S1.5-FCI** | 2.5+ Functional Complexity Index | Grammar coverage > size | ⭐⭐⭐ |
| **SX-Compose** | Emergent compose | Нелинейная композиция ops | ⭐⭐⭐⭐ |
| **S2.5-септим** | **2.5-септим Bloat Guard** | **MITOSIS: \|G\|>50 → деление** | ⭐⭐ |
| **S2.5-квинкве** | **2.5-квинкве Law Preservation** | **Закон Gen N → жив в Gen N+10** | ⭐⭐⭐ |
| **S2.5-секст** | **2.5-секст Falsification** | **Penalty на источник шума** | ⭐⭐ |
| **S2.5-нона** | **2.5-нона Inference** | **Транзитивность, modus ponens** | ⭐⭐⭐⭐ |
| **S2.6** | **2.6 Env Pressure** | **Drought/flood/shift давление** | ⭐⭐ |
| **S2.7** | **2.7 Resilience** | **Extinction+recovery цикл** | ⭐⭐⭐ |
| S2.5-ундецим | 2.5-ундецим Sovereignty | Право на отказ (опционально) | ⭐⭐ |
| S2.5-ундецим-бис | 2.5-ундецим-бис Groundedness | Защита мета-модели | ⭐⭐⭐ |

## Stage 2 — Understanding (Backlog)

| # | Criterion | What | Сложность |
|---|-----------|------|-----------|
| S2.1 | 2.5-bis | Generational capability growth | ⭐⭐⭐⭐⭐ |
| S2.2 | 2.5-ter | Grammar ceiling break (NESTED) | ⭐⭐⭐⭐⭐ |
| S2.3 | 2.5-quater | Contradiction → paradigm | ⭐⭐⭐⭐⭐ |

## Technical Debt / Other Backlog

| # | What | Hours | Priority |
|---|------|-------|----------|
| D15 | TASK-GENERATOR — getTasks 200→10 | 3h | High |
| D16 | BOOTSTRAP-MANAGER — bootstrap 60→5 | 2h | High |
| D17 | SPAWN-MANAGER — spawn logic из doTick | 2h | Medium |
| D18 | RECORD-KEEPER — recordDiscovery 40→5 | 1.5h | Medium |
| S2.2 Ph.2-5 | IDLE-DREAMING tuning | 4h | Low |
| S2.3 | PARALLEL-ROUTING | 3h | Low |
| S2.4 | SLEEP-TUNING | 2h | Low |
| S3.1 | ASSOCIATION-RULES — market basket analysis | 5.5h | Stage 3 |
| S2.5 | FORAGER-SELF-MOD — extraction ceiling | 8h+ | Stage 3 |
| S2.6 | FORM-DISCOVERY — power law/attractor across domains | 5h | Stage 2 |
| S3.2 | LAW-GROUNDING-AUDIT — 5-criteria check | 3.5h | Stage 2 |
| S3.3 | FALSIFICATION-LOOP — automatic refutation | 3h | Stage 2 |
| S3.4 | PEER-REVIEW — cross-swarm validation | 4h | Stage 3 |
| S3.5 | PARADIGM-SHIFT — ontology migration | 6h | Stage 3 |
| S3.6 | META-KNOWLEDGE — knowing what you don't know | 2h | Stage 2 |
| S3.7 | COMPRESSION-DISCOVERY — image/binary patterns | 20h+ | Stage 3+ |
| S3.8 | AUDIO-PATTERNS — acoustic invariant discovery | 15h+ | Stage 3+ |
| S3.9 | PHYSICAL-PATTERNS — embodied/vibration invariants | 30h+ | Stage 3+ |
| S1.13 | LANDSCAPE | 4h | Low |
