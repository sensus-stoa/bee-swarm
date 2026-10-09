# Bee Swarm — CV→0 Autonomous Evolution Protocol (Implementation)

> PHP implementation of the CV→0 Autonomous Evolution Protocol: a population of
> bees (autonomous agents) that discover mathematical invariants through
> evolutionary search, evolve their own grammar bottom-up, refuse to
> hallucinate — and **reuse their discoveries as building blocks across domains**
> (cultural transfer, statistically proven).

## The refusal showcase

The system's defining property is not what it finds — it is what it refuses to
certify. Two measured cases:

**260 market laws, all rejected.** We pointed the swarm at MOEX stock data
(8 tickers, 2018–2024 train, 2025–2026 out-of-sample). It surfaced 260 foraged
"laws" — every single one passed the internal held-out check (CV 0.03–0.15).
Then the null-calibration layer ran the same formulas against shuffled
targets: identical scores. **Verdict: zero of the 260 have predictive power.**
94% were constant artifacts of the grammar (hardcoded `K1≡1.0`-class
constants dressed up by affine normalization), the rest horizon-overlap
tautologies (`ret1 ⊂ ret5`). The swarm was not fooled — the question was
wrong, and the verification layer caught it. Honest refusal *is* a result.

**AUTO-MPG: approximation ≠ invariant.** On the classic regression benchmark,
PySR (SOTA symbolic regression) finds genuine predictive structure
(R² = 0.708, below the null band). Bee Swarm **refuses** to certify it: the
pre-registered invariant criterion (CV→0 on held-out) is not met. Approximation
and invariant discovery are different tasks; conflating them is how discovery
systems hallucinate.

## Protocol

**DOI: [10.5281/zenodo.23212766](https://doi.org/10.5281/zenodo.23212766)** —
CV→0 Autonomous Evolution Protocol **v1.9** (published Oct 2026; four stages,
every criterion falsifiable by script; dissipation ladder, blind transition
detection, contradiction classifier). Concept line:
[10.5281/zenodo.21810055](https://doi.org/10.5281/zenodo.21810055).

Experiment journal: `Benchmarks/experiments-log.md` (EXP-001..039, honest logs
including null results and retracted methodology).

## What the system does

- **CV→0 criterion:** an expression is a law iff the coefficient of variation
  of `expression/target` on held-out data → 0. Not approximation — invariance.
- **Structural refusal:** diagnoses WHY it cannot find a law:
  `GRAMMAR` / `DATA` / `NOISE` / `DEPTH`. It never guesses.
- **Null-calibration:** thresholds calibrated against shuffled permutations;
  measured FPR = 0 on noise (0/100) and the 260-law case above.
- **Two-part code certification (§1.14):** a law must compress held-out data
  better than its own description costs; degenerate "laws" fail byte economics.
- **GRAMMAR-BIRTH:** successful composite formulas are elevated to grammar
  operators (`B{hash} => definition`). Grammar evolves bottom-up from verified
  discoveries.
- **CULTURAL TRANSFER (proven, EXP-022o/r/t):** operators born in domain A are
  systematically reused in domain B — 67% of A-atoms reused (180 reuse
  events), random-matched controls: 0/30. Fisher exact p ≈ 1.08×10⁻⁵. Reuse is
  registered at the point of application (touchAtom), rewarded in the energy
  economy (REUSE-REWARD ×1.5 reuse, ×2.0 cross-domain transfer), candidates
  are forgotten if unused (24h TTL).
- **Language emergence, formalized (§3.8, VERIFY-2-8):** the compressor
  distills isomorphic laws from two domains into one atom (max−min over the
  heat/diffusion molecule); the atom then solves a third domain that raw
  search of the same budget cannot solve at all (raw depth-2 structurally
  impossible vs with-atom depth-3 exact, cv≈0).
- **Dissipation ladder (§1.19):** the death threshold d* of a law is measured
  — how much of the signal may be replaced by noise before the structure dies
  (pilot: d* = 0.40, byte margin decays linearly). Law↔noise symmetry is
  byte-measurable in both directions (chaotizer: XOR-encrypted laws read as
  noise, key restores them bit-for-bit).
- **Blind transition detection (§1.20):** a three-channel consensus detector
  (frozen-law bias / signflip collapse / replication consistency) flags entry
  into an unknown regime without knowing its law. Validated on 8 years of
  published French nuclear grid events (46,141 rows): the 2023 output
  halving detected at −4.5σ, zero false alarms in quiet-year controls;
  per-unit records confirm the curtailment mechanics the national aggregate
  masks.
- **Honesty filters on input (S1.5):** position-artifact features
  (|corr(x, row-index)| > 0.99 — "laws about row numbers") and duplicate
  columns are excluded before search.
- **Population dynamics:** energy lifecycle (tick/search costs, discovery
  rewards, heritable params), spawn with mutated grammar, hunger mutations at
  E<5, gap-spawn on plateau, escrow-based delayed rewards (anti-grazing:
  rewards settle only after independent verification), generation snapshots +
  monoculture alarm.

## Stage status (09.10.2026)

| Stage | Status |
|-------|--------|
| 0 — Reliable invariant extraction | ✅ 9/9 verify PASS, FPR=0 on noise, honest NOISE refusals; two-part code gate + dissipation ladder + blind transition detection shipped (v1.9) |
| 1 — Living population | ✅ verify_1_* suite passed 13.08 (escrow economy, ensemble certification, verifier-eps parity); continuous prod run paused, resumable |
| 2 — Understanding | 🔧 ladder in progress: §3.8 language emergence formalized as measurement (6 tests, closed 09.10); next: form invariance, self-model of ignorance |
| 3 — Autonomy | specification ready (gated: verification machinery is immutable to the swarm — proposals never touch the referee) |

Test suite: **1183/1183** two-pass (fast 1174 parallel + slow 9 serial),
psalm clean.

## Quick start

```bash
php scripts/verify/verify_all.php --stage=0 --log=logs/agenda.log
```

Tests (TDD, in-memory DB isolation, two-pass: fast parallel + slow serial):

```bash
bash scripts/run_two_pass.sh   # 1183 tests
```

Daemon:

```bash
php agenda.php   # see DEPLOY.md
```

## Comparison with gplearn (symbolic regression baseline)

Same Stage-0 tasks, same data, CV→0 criterion vs gplearn (MSE-optimized GP):

| Metric | Bee Swarm | gplearn |
|--------|-----------|---------|
| Narrow tasks (single law) | **×2 faster** to target CV | baseline |
| Wide tasks (law among noise) | **×1.6 faster** | baseline |
| Target-metric alignment | optimizes CV directly | optimizes MSE (proxy!) |
| TSP (permutation class) | **solves** (≥ greedy+2-opt) | not applicable (0/9 valid tours) |

Methodology and full series: EXP-008..011 in the experiment journal.

## Comparison with PySR (SOTA symbolic regression, Julia)

EXP-027/036 (Aug–Sep 2026): same data, same frozen splits (60/40, seed 1..20),
same grammar (+, −, ×, /, sq, sqrt), same metrics (CV_train/CV_holdout),
PySR at default strength (populations=31 — an earlier weakened-rival run was
retracted):

| Dataset | PySR (20 seeds) | Bee Swarm (20 seeds) |
|---------|-----------------|----------------------|
| WINE | CV_H median 0.0485 (20/20) | CV_H median **0.047** (20/20) — parity |
| heat | 19/20 | **20/20** — parity (1 discordant, McNemar n.s.) |
| gravity | 0/20 | **6/20** — Bee win |
| relmass | 16/20 | **20/20** — Bee advantage |
| dot, kinetic | **18/20, 16/20** | 0/20 — PySR win (SUM-composition gap, mapped to stories) |
| AUTO-MPG | CV_H 0.175, R²=0.708 (approximation) | **refusal** (0/100 null accepted, FPR=0) |
| null control (100 noise sets) | — | **0 laws** (FPR=0) |

Interpretation: approximation and invariant discovery are distinct tasks.
PySR finds genuine predictive structure on MPG (below null q05=0.346), but it
does not satisfy the pre-registered invariant criterion (CV_H≤0.10). CV→0
refuses to promote an approximation to an invariant. Losses are reported
symmetrically: the SUM-composition gap is an open engineering item, not a
hidden weakness.

Full series: EXP-027..028, EXP-036 in the experiment journal.

## Why PHP (and why not)

The honest answer: PHP is the author's production stack, and the protocol is
deliberately language-agnostic (§0.2 — "does not prescribe a specific
implementation"). Every criterion is a portable script.

The measured part: on the task class this system targets (small-N physical
data, depth ≤ 3 grammar, verification-heavy workload), the search is not the
bottleneck — verification is. On the shared benchmark, Bee Swarm ran ~37 s
per seed at 20/20 while PySR took 60 s at 19/20 (EXP-036). For billion-row
datasets, a Julia/C++ port of the *search layer* would indeed win — the
protocol text welcomes exactly that; the criteria, gates, and null machinery
are what make results trustworthy, and they are portable by design.

This is a boundary statement, not a denial: for 10⁷-row raw throughput, use
PySR. For deciding whether what anything found is an invariant or an
artifact — that is the part that does not care about the language.

## This is not a refusal machine

A fair worry: requiring CV→0 on stochastic real-world data might produce a
system that answers NOISE to everything outside school physics. Measured
evidence says otherwise:

- **Dissipation ladder (§1.19):** the system does not just refuse noise —
  it *measures how much noise a law tolerates* before dying (pilot d* = 0.40,
  byte margin decaying linearly). Tolerance is a number, not a dogma.
- **Blind transition detection on real noisy data (§1.20):** validated on
  8 years of published French nuclear grid events (46,141 rows) — the 2023
  output halving was detected at −4.5σ with zero false alarms in quiet-year
  controls. The machine distinguishes regime change from noise on messy
  real-world data.
- **AUTO-MPG:** PySR's R² = 0.708 predictive structure is reported as genuine
  — the refusal is about *certification* (invariant vs approximation), not
  about pretending structure does not exist.

## Terminology map

The metaphors name mechanisms; every poetic term maps to a protocol section
and a standard concept:

| Term used here | Standard concept | Protocol |
|----------------|------------------|----------|
| bees, hive | island-model population of autonomous agents | §2.1–2.4 |
| hunger mutations | energy-budget-driven mutation rate | §2.1, §2.5.14 |
| grammar birth / atoms | automatically defined functions (Koza ADF) | §1.4.1, §3.8 |
| cultural transfer | cross-domain operator reuse | §3.2, §3.8 |
| refusal taxonomy | structured rejection with diagnosis | §2.5.15 |
| escrow / delayed reward | verification-gated reward settlement | §2.2, V0.14 |
| dissipation ladder | measured noise tolerance / death threshold | §1.19 |
| two-part code gate | MDL / minimum description length certification | §1.14 |
| monoculture alarm | population diversity monitoring | §2.5.8 |

The maps go one way only: the poetic names are the API of the running system
(log lines, DB tables, test names), so the code and the protocol speak the
same language. Nothing is hidden behind the metaphors — each one resolves to
a numbered, script-verifiable section.

## Architecture (v4)

```
agenda.php → Hive::run()
├── BootstrapManager (3 seed bees, §0.6)
├── Forager (files → tasks, DataSource abstraction)
├── TaskRouter (density-based routing, fingerprint)
├── Bee (energy lifecycle §2.1, heritable energy params)
├── Search::find (CV→0, held-out, affine-shift, honesty gates S1.5)
├── DiscoveryEngine (candidate pipeline)
├── SeasonScheduler (phase-based budget allocation, EXP-039)
├── GrammarMutator (spawn mutation + propagation weights)
├── Grammar (BASE_OPS + dynamic ops + B-atoms birth + reuse tracking)
├── NullCalibrator (permutation null-calibration)
├── Certification/ (EnsembleCertifier: structural certificates, §1.9)
├── VerificationExecutor (independent law verification, escrow-settled)
├── OverlapTracker (§1.8)
├── PlateauDetector + SpawnManager (gap-spawn, generation snapshots)
├── RecordKeeper (laws DB, dedup, cross-domain)
└── ClozeEngine / IdleDreamer / CorpusVocabulary (text layer)
```

## License

This repository contains two distinct types of content, each with its own license:

| Content | License | File |
|---------|---------|------|
| **Source code** (PHP, scripts, tests) | **MIT** — free to use, modify, distribute, commercial use allowed | `LICENSE-CODE` |
| **Protocol & scientific texts** (docs/protocol.md, README, ARCHITECTURE) | **CC BY 4.0** — free to share and adapt with attribution | `LICENSE-PROTOCOL` |

**Summary:** The code is MIT-licensed (standard open-source permissive). The protocol
documentation is CC BY 4.0 (attribution required when sharing/adapting). If you use
both, both licenses apply to their respective parts. If you use only code, MIT applies.
If you use only the protocol text, CC BY applies.

Full texts: [LICENSE-CODE](LICENSE-CODE) · [LICENSE-PROTOCOL](LICENSE-PROTOCOL)
