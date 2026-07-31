# Bee Swarm Stories

## In Progress

| # | What | Status |
|---|------|--------|
| V0 | Runtime Null-Calibration | ✅ Phase 1-3 complete (0800f7c) |

## Stage 0 — COMPLETE ✅

| # | Criterion | What | Status |
|---|-----------|------|--------|
| 01 | 1.6 Deduplication | AtomRegistry split | ✅ |
| 02 | 1.5 Plateau Honesty | wakeup + forager | ✅ |
| 03 | 1.1 Held-Out Validation | retrospective + held-out | ✅ |
| 04 | 1.2 Statistical Sufficiency | t ≥ t_min | ✅ |
| 05 | 1.7 Compression Superiority | MDL cost vs baseline | ✅ |
| 06 | 1.4 Non-Triviality | Алгебраическая редукция | ✅ |
| 07 | 1.3 Parsimony | complexity(e) | ✅ |

## Bugfixes — COMPLETE ✅

| # | What | Status |
|---|------|--------|
| B1 | Compose held-out | ✅ |
| B2 | isTrivial regex + compose + reductions | ✅ |
| B3 | Dedup ключ с domain + DUPLICATE log | ✅ |
| B4 | Compose sufficiency | ✅ |
| B5 | Plateau синтетика + off-by-one | ✅ |
| B6 | OVERFIT logging | ✅ |
| B7 | Search train-only (closed — already train-only) | ✅ |
| L1 | CV tolerance unified 0.0001 | ✅ |
| L2 | skipGenerated flag + srand(42) | ✅ |
| L3 | DATA log + full audit PASS | ✅ |

## Technical Debt — Done ✅

| # | What | Status |
|---|------|--------|
| D1 | SOLID: Modular Architecture (36 файлов) | ✅ |
| D2 | Hive class (agenda.php → OOP) | ✅ |
| D3 | Psalm level 5 — 0 errors | ✅ |
| D4-6 | ECS: PSR-12 + Common + Clean Code — 0 errors | ✅ |
| D8 | Forager caps (maxTotal, dedup, chunking) | ✅ |
| D9 | ExpressionTree::nodeCount() | ✅ |
| D10 | Forager Decomposition (614→310, 4 classes extracted) | ✅ |

## E1 — Text Atoms ✅

| # | What | Status |
|---|------|--------|
| E1.1 | Text atom definitions (AtomRegistry) | ✅ |
| E1.2 | Text-aware task format | ✅ |
| E1.3 | CV→0 over text atoms | ✅ |
| E1.4 | Feedback loop (atoms → strategies) | ✅ |
| E1.5 | Integration (476 files) | ✅ |
| E1.6 | Data → law pipeline | ✅ |

## Stage 1 — Population (Backlog)

| # | Criterion | What | Сложность |
|---|-----------|------|-----------|
| S1.1 | 2.1 Bee death | Energy model + starvation | ⭐ |
| S1.2 | 2.2 Bee birth | Spawn + grammar mutation | ⭐⭐⭐ |
| S1.3 | 2.3 Grammar isolation | Per-bee grammar (no shared state) | ⭐⭐ |
| S1.4 | 2.4 Task routing | Density-based: fingerprint + outcome history, domains emerge | ✅ |
| S1-WIRE | — Hive population wiring | Bee+TaskRouter+RoadRunner → живая популяция | ⭐⭐⭐⭐ |
| S1.5 | 2.5 Evolutionary dynamics | Grammar compression + diversity | ⭐⭐⭐ |
| S1.5-FCI | 2.5+ Functional Complexity Index | Grammar coverage > size, нейро-inspired | ⭐⭐⭐ |
| SX-Compose | Emergent compose | Нелинейная композиция ops, топология grammar | ⭐⭐⭐⭐ |

## Stage 2 — Understanding (Backlog)

| # | Criterion | What | Сложность |
|---|-----------|------|-----------|
| S2.1 | 2.5-bis | Generational capability growth | ⭐⭐⭐⭐⭐ |
| S2.2 | 2.5-ter | Grammar ceiling break (NESTED) | ⭐⭐⭐⭐⭐ |
| S2.3 | 2.5-quater | Contradiction → paradigm | ⭐⭐⭐⭐⭐ |

## Other Backlog

| # | What | Сложность |
|---|------|-----------|
| D11 | Search perf — grammar cap + pruning | ⭐⭐ |
| F1 | RoadRunner worker for bee | ⭐⭐ |
