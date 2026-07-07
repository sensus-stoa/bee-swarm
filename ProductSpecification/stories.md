# Bee Swarm Stories

## In Progress

| # | What | Status |
|---|------|--------|

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

## Backlog

| # | What | Сложность |
|---|------|-----------|
| D11 | Search perf — grammar cap + pruning (551 ops → 8B combos) | ⭐⭐ |
| E1 | CV→0 regex atoms | ⭐⭐⭐ |
| F1 | RoadRunner worker for bee | ⭐⭐ |
| S1.1 | 2.1 Bee death (starvation) | ⭐ |
| S1.2 | 2.2 Bee birth (spawn + mutation) | ⭐⭐⭐ |
| S1.3 | 2.3 Grammar isolation | ⭐⭐ |
| S1.4 | 2.4 Competitive task distribution | ⭐⭐ |
| S1.5 | 2.5 Evolutionary dynamics | ⭐⭐⭐ |
| S2.1 | 2.5-bis Generational growth | ⭐⭐⭐⭐⭐ |
| S2.2 | 2.5-ter Grammar ceiling break (NESTED) | ⭐⭐⭐⭐⭐ |
| S2.3 | 2.5-quater Contradiction → paradigm | ⭐⭐⭐⭐⭐ |
