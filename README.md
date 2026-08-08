# Bee Swarm — CV→0 Autonomous Evolution Protocol (Implementation)

> PHP implementation of the CV→0 Autonomous Evolution Protocol: a population of
> bees (autonomous agents) that discover mathematical invariants through
> evolutionary search, evolve their own grammar bottom-up, and refuse to
> hallucinate.

## Protocol

**DOI: [10.5281/zenodo.21810056](https://doi.org/10.5281/zenodo.21810056)** — CV→0 Autonomous Evolution Protocol v1.3 (four stages, 38 criteria, every criterion falsifiable by script).

Experiment journal: `Benchmarks/experiments-log.md` (EXP-001..022) — honest logs including null results.

## What the system does

- **CV→0 criterion:** an expression is a law iff the coefficient of variation of `expression/target` on held-out data → 0. Not approximation — invariance.
- **Structural refusal:** the system diagnoses WHY it cannot find a law: `GRAMMAR` (missing operator), `DATA` (insufficient points), `NOISE` (unpredictable), `DEPTH` (needs deeper search). It never guesses.
- **Null-calibration:** every structural fingerprint is calibrated against ≥100 shuffled permutations. If any null-run produces a "discovery," thresholds tighten until FPR_system = 0.
- **GRAMMAR-BIRTH (EXP-015):** successful composite formulas are elevated to new grammar operators (`B{hash} => floor(rad2deg)`). The grammar evolves bottom-up from verified discoveries — not top-down from an architect (unlike Koza ADF).
- **Cultural propagation (EXP-012/013/014):** operators gain weight after successful use; spawn inherits weighted mutations. Knowledge sharing confirmed on composite structures (×2.1 median, EXP-014 final).
- **Population dynamics:** energy-based life cycle (tick cost, search cost, discovery reward, heritable energy params), spawn with mutated grammar, hunger mutations at E<5, gap-triggered spawn on plateau, population persistence across restarts.

## Stage status (08.08.2026)

| Stage | Status |
|-------|--------|
| 0 — Reliable invariant extraction | ~85% (11/13 arithmetic tasks, Auto MPG, Wine, honest NOISE refusals) |
| 1 — Living population | mechanisms wired, 24h integration run (v1.3, 06.08→13.08) |
| 2 — Understanding | stories mapped to academic references (see project docs) |
| 3 — Autonomy | specification ready |

## Quick start

```bash
php scripts/verify/verify_all.php --stage=0 --log=logs/agenda.log
```

Tests (TDD, all in-memory DB isolation):

```bash
vendor/bin/phpunit tests/
```

Daemon:

```bash
php agenda.php   # see DEPLOY.md
```

## Architecture (v4)

```
agenda.php → Hive::run()
├── BootstrapManager (3 seed bees, §0.6)
├── Forager (files → tasks, DataSource abstraction)
├── TaskRouter (density-based routing, fingerprint)
├── Bee (energy lifecycle §2.1, heritable energy params §2.1-evo)
├── Search::find (CV→0, held-out, affine-shift §EXP-012)
├── DiscoveryEngine (candidate pipeline)
├── GrammarMutator (spawn mutation + GRAMMAR-PROPAGATION weights)
├── Grammar (BASE_OPS + dynamic ops + B-atoms birth)
├── NullCalibrator (permutation null-calibration)
├── OverlapTracker (§1.8)
├── PlateauDetector + SpawnManager (gap-spawn)
├── RecordKeeper (laws DB, dedup, cross-domain)
└── ClozeEngine / IdleDreamer / CorpusVocabulary (text layer)
```

## License

CC BY 4.0 (protocol texts), MIT (code). See LICENSE.
