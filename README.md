# Bee Swarm — CV→0 Autonomous Evolution Protocol (Implementation)

> PHP implementation of the CV→0 Autonomous Evolution Protocol: a population of
> bees (autonomous agents) that discover mathematical invariants through
> evolutionary search, evolve their own grammar bottom-up, refuse to
> hallucinate — and **reuse their discoveries as building blocks across domains**
> (cultural transfer, statistically proven).

## Protocol

**DOI: [10.5281/zenodo.21810056](https://doi.org/10.5281/zenodo.21810056)** — CV→0 Autonomous Evolution Protocol v1.4 (four stages, every criterion falsifiable by script).

Experiment journal: `Benchmarks/experiments-log.md` (EXP-001..026, honest logs including null results).

## What the system does

- **CV→0 criterion:** an expression is a law iff the coefficient of variation of `expression/target` on held-out data → 0. Not approximation — invariance.
- **Structural refusal:** diagnoses WHY it cannot find a law: `GRAMMAR` / `DATA` / `NOISE` / `DEPTH`. It never guesses.
- **Null-calibration:** thresholds calibrated against shuffled permutations until FPR = 0 (empirically: 0/100 on noise).
- **GRAMMAR-BIRTH:** successful composite formulas are elevated to grammar operators (`B{hash} => definition`). Grammar evolves bottom-up from verified discoveries.
- **CULTURAL TRANSFER (proven, EXP-022o/r/t):** operators born in domain A are systematically reused in domain B — 67% of A-atoms reused (180 reuse events), random-matched controls: 0/30. Fisher exact p ≈ 1.08×10⁻⁵. Reuse is registered at the point of application (touchAtom), rewarded in the energy economy (REUSE-REWARD ×1.5 reuse, ×2.0 cross-domain transfer), candidates are forgotten if unused (24h TTL).
- **Honesty filters on input (S1.5):** position-artifact features (|corr(x, row-index)| > 0.99 — "laws about row numbers") and duplicate columns (|corr| > 0.99) are excluded before search.
- **Population dynamics:** energy lifecycle (tick/search costs, discovery rewards, heritable params), spawn with mutated grammar, hunger mutations at E<5, gap-spawn on plateau, population persistence, generation snapshots + monoculture alarm.

## Stage status (11.08.2026)

| Stage | Status |
|-------|--------|
| 0 — Reliable invariant extraction | ✅ 9/9 verify PASS, FPR=0/100 (shuffle), honest NOISE refusals (soduku, MOEX: 260/260 pseudo-laws caught by null-filter) |
| 1 — Living population | 🔧 prod running 7 days (GEN 2726+, 514 discoveries, plateau cycles), verify_1_* on 13.08 after deploy |
| 2 — Understanding | 🟡 transfer proven (Fisher 1e-5); boundaries/hierarchy mapped to stories |
| 3 — Autonomy | specification ready |

## Quick start

```bash
php scripts/verify/verify_all.php --stage=0 --log=logs/agenda.log
```

Tests (TDD, in-memory DB isolation, paratest -p3):

```bash
vendor/bin/paratest -p3 tests/   # 710 tests, ~7 min
```

Daemon:

```bash
php agenda.php   # see DEPLOY.md
```

## Comparison with gplearn (symbolic regression baseline)

Same 13 Stage-0 tasks, same data, CV→0 criterion vs gplearn (MSE-optimized GP):

| Metric | Bee Swarm | gplearn |
|--------|-----------|---------|
| Narrow tasks (single law) | **×2 faster** to target CV | baseline |
| Wide tasks (law among noise) | **×1.6 faster** | baseline |
| Target-metric alignment | optimizes CV directly | optimizes MSE (proxy!) |
| TSP (permutation class) | **solves** (≥ greedy+2-opt) | not applicable (0/9 valid tours) |

Methodology and full series: EXP-008..011 in the experiment journal.

## Architecture (v4)

```
agenda.php → Hive::run()
├── BootstrapManager (3 seed bees, §0.6)
├── Forager (files → tasks, DataSource abstraction)
├── TaskRouter (density-based routing, fingerprint)
├── Bee (energy lifecycle §2.1, heritable energy params)
├── Search::find (CV→0, held-out, affine-shift, honesty gates S1.5)
├── DiscoveryEngine (candidate pipeline)
├── GrammarMutator (spawn mutation + propagation weights)
├── Grammar (BASE_OPS + dynamic ops + B-atoms birth + reuse tracking)
├── NullCalibrator (permutation null-calibration)
├── OverlapTracker (§1.8)
├── PlateauDetector + SpawnManager (gap-spawn, generation snapshots)
├── RecordKeeper (laws DB, dedup, cross-domain)
└── ClozeEngine / IdleDreamer / CorpusVocabulary (text layer)
```

## License

CC BY 4.0 (protocol texts), MIT (code). See LICENSE.
