# External Audits: Public Evidence Portfolio

> Independent audit of numerical datasets against the CV→0 falsifiability protocol.
> Every verdict below was produced by the same pipeline: held-out validation,
> null (permutation) calibration, conventional baselines on an identical split,
> pre-registered expectations. Engine code unmodified by any audit.

**Protocol:** [CV→0 v1.6.1](https://doi.org/10.5281/zenodo.22386642) · **Paper:** [Invariant Discovery with Honest Refusal](https://aixiv.science/paper/aixiv.260907.000002) (aiXiv, official review 7/10) · **Code:** [MIT](https://github.com/sensus-stoa/bee-swarm)

---

## What this system does

Given a numerical dataset and a target variable, the system returns exactly one of four verdicts:

| Verdict | Meaning |
|---------|---------|
| **INVARIANT** | A compact relation that survives held-out validation, null controls, and compression testing |
| **PREDICTIVE APPROXIMATION** | A useful structure that fits, explicitly NOT certified as invariant |
| **REFUSAL** | No certifiable relation found, with a falsifiable diagnosis: DATA, DEPTH, GRAMMAR, or NOISE |

Each refusal diagnosis carries a prescribed falsification procedure. "The right operation is missing" is a test, not a story: adding the operation should make the task solvable. If it doesn't, the diagnosis was wrong and is retracted.

The system is built to **refuse what it cannot claim**. A good-looking formula with unverifiable precision is returned as REFUSAL, not shipped with a footnote.

---

## Audit portfolio

### Audit #1: UCI Concrete Compressive Strength (1030 rows × 8 features)

**Verdict: REFUSAL (class GRAMMAR)**

The engine found a physically sensible candidate (cement, age, water in Abrams directions) and **refused it**: the candidate required fitted affine constants that the parameter-free grammar cannot produce, and absolute precision (R² = −3.8 despite CV 0.44) was nowhere near certification. Null controls at two gate levels confirmed the signal is real but not certifiable: 0/30 permutations at the strict gate, 0/10 at the relaxed gate.

Baselines on the identical split: OLS R² 0.60, PySR R² 0.66, RandomForest 0.91, GradientBoosting 0.93.

Most tools would have shipped that formula with a footnote. The refusal is the product.

<details>
<summary>Full verdict summary</summary>

- Split: train 721 / test 309, seed 42, indices published
- Engine search: depth 3, beam 30, 51,003 expressions, 300 s budget
- Strict gate (CV ≤ 0.15): no candidate passes; diagnosis GRAMMAR (not NOISE)
- Relaxed probe (CV ≤ 0.45): one compact candidate, CV 0.441 train / 0.438 test, rejected on R²
- Null controls strict: 0/30 permutations (95% upper bound ≈ 0.10)
- Null controls relaxed: 0/10 permutations (95% upper bound ≈ 0.30)
- Post-hoc R² cross-check now mandatory: the internal CV metric is scale-invariant and blind to absolute accuracy (documented metric blind spot)

</details>

### Audit #2: UCI Combined Cycle Power Plant (9568 rows × 4 features)

**Verdict: REFUSAL (classes GRAMMAR + METRIC-DOMAIN)**

This audit demonstrates the value of the **audit layer above the engine**: the engine certified a degenerate formula (−65 MW against real outputs of 420 to 495 MW), and the audit pipeline caught it before it could be reported. The degradation mechanism was measured and documented, and the refusal was confirmed across strict and relaxed gates with permutation controls.

The dataset also produced a **PREDICTIVE APPROXIMATION via the contradiction mechanism** (R² = 0.889, MAE 4.56 MW after anchor inversion): a useful predictive structure reported as exactly that, not certified as a law.

<details>
<summary>Full verdict summary</summary>

- Engine search produced a candidate failing physical sanity (negative power output)
- Audit layer flagged METRIC-DOMAIN: the engine's CV metric accepted a form that fails absolute accuracy catastrophically
- Contradiction-driven re-anchoring: median(pred/y) from train only (zero leakage, division not multiplication)
- Post-inversion: R² 0.889 on held-out, reported as PREDICTIVE APPROXIMATION
- The engine result and the audit verdict are both recorded; the audit verdict is authoritative

</details>

### Audit #3: Feynman dot product (synthetic, 25 resampling runs)

**Verdict: INVARIANT (ensemble-certified)**

The first positive certification in the portfolio. The system re-discovered the dot-product law **25/25 times** across independent resampling runs, with **0/50 false acceptances** on null (shuffled-target) permutations. The anchor statistic m̂ = 1.0 (exact), confirming data-derived constants are unnecessary for this law class.

This demonstrates the INVARIANT verdict is achievable when the physics supports it: the system does not refuse everything; it refuses what the evidence does not support.

<details>
<summary>Full verdict summary</summary>

- 25 independent runs, each with fresh resampling of the same underlying law
- LawShape consistency: 25/25 (the re-discovered form is the same compact expression)
- Null calibration: 0/50 false acceptances on shuffled-target permutations
- Anchor statistic: m̂ = 1.0 (no fitted constants needed)
- Ensemble certification: recurrence across runs is the acceptance criterion, not a single lucky run

</details>

### Audit #4: UCI Concrete II (decomposition)

**Verdict: REFUSAL + structural decomposition (8-cluster Abrams table)**

The same concrete dataset, re-audited with a decomposition pass. The system's refusal stands for the dataset as a whole, but the audit localizes where structure lives: an **8-cluster table of Abrams constants** (water/cement ratio K-values per age/cement class), validated on held-out data (R² = 0.785).

This demonstrates REFUSAL with a remedy: the refusal names what would change the verdict (cluster-specific constants), and the audit produces the structural evidence for it.

<details>
<summary>Full verdict summary</summary>

- 8 clusters identified by composition signature (Abrams classes)
- Per-cluster K constants: PE ≈ K(cluster) / (W/C ratio)
- Held-out validation: R² = 0.785 across clusters
- Pre-registered as a target for the engine's next version: the engine should find this cluster pattern itself; if it cannot, the gap is localized

</details>

---

## Portfolio summary

| # | Dataset | Verdict | Key artifact |
|---|---------|---------|--------------|
| 1 | UCI Concrete (1030×8) | REFUSAL (GRAMMAR) | honest refusal at unusable constants |
| 2 | UCI CCPP (9568×4) | REFUSAL (GRAMMAR + METRIC-DOMAIN) | audit caught engine's degenerate formula |
| 3 | Feynman dot product | **INVARIANT (ensemble-cert)** | 25/25 re-discovery, 0/50 nulls |
| 4 | UCI Concrete II | REFUSAL + 8-cluster decomposition | Abrams K-table, R² 0.785 held-out |
| + | CCPP contradiction | PREDICTIVE APPROXIMATION | R² 0.889 via anchor inversion |

**All four verdict classes demonstrated on independent data.** The system finds laws when the evidence supports them (#3), refuses when it cannot certify (#1, #2, #4), distinguishes approximation from invariance (+), and its audit layer overrides the engine when the engine's own metric fails physical sanity (#2).

---

## Method (same for every audit)

1. **Pre-registered expectations**: physical priors written down before search
2. **Deterministic split**: seed and indices published
3. **Engine search**: parameter-free structural grammar, no fitted constants
4. **Held-out validation**: CV_H threshold, task-calibrated
5. **Null calibration**: at least 30 shuffled-target permutations per gate level; reported as observed 0/N with rule-of-three CI, never as "FPR = 0"
6. **Conventional baselines**: OLS, PySR, RandomForest, GradientBoosting on the identical split
7. **Post-hoc R² cross-check**: mandatory; internal CV metric is scale-blind (documented blind spot)
8. **Verdict**: INVARIANT / PREDICTIVE APPROXIMATION / REFUSAL per protocol v1.6.1

---

## Reproduction

Every audit's raw artifacts (split indices, per-run logs, null-run JSONs, baseline results, engine md5) are retained. Full reports available on request. Engine code is unmodified by any audit: the audit layer runs above it.

## Running an audit on your data

If you have a numerical dataset where the distinction between "good fit" and "reliable relation" matters, and where an honest refusal is more valuable than a flattering formula: the audit format is available as a fixed-scope service. Details: [contact](mailto:beattle1984@gmail.com).

---

*Independent researcher, Evgeny Dolgov. This page is a living document: new audits are appended as they complete.*
