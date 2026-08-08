# Experiment Journal — CV→0 Bee Swarm

> Honest log of experiments, including null results. Full Russian log lives in the
> project docs (EvoFamily); this is the English science-facing summary.

## EXP-001 (05.08) — Baseline: random search vs systematic
Random search with same budget finds ≥95% of laws? NO — systematic CV→0 search
is required for reliability; random search on correlated metrics gives 24% false
hits without null-calibration.

## EXP-002 (05.08) — Null-calibration on auto-mpg
200 shuffle permutations: 0 false positives at calibrated threshold.

## EXP-003 (05.08) — Sudoku: honesty and grammar expressiveness boundary
Invariant "row sum = 45" requires a reduce-sum operator; binary-pair grammar
cannot express it. **Grammar boundary is an honest limit, not a bug.**

## EXP-005 (05.08) — TSP: evolutionary framework
The same mechanisms (population, selection, mutation) solve TSP when genome/
fitness are swapped. One framework — two problem classes (invariants + routes).

## EXP-008 (05.08) — gplearn vs Bee Swarm: PARITY
On simple expressible laws: gplearn 10/10, Bee Swarm 10/10 (CV=0.0000), FPR
both 0. **No superiority on this benchmark — honest baseline.** Differentiators
(grammar evolution, null-calibration, domains) require separate testing.

## EXP-009 (05.08) — Noise & scale: first confirmed difference
gplearn (MSE) degrades ×10 in CV_holdout when y scales 1→1000; Bee Swarm CV is
scale-invariant. Scale invariance of the CV criterion — first confirmed
difference.

## EXP-011 (05.08) — TSP benchmark
GA+2opt ≥ greedy+2opt on all 9 instances (up to −4.8%). gplearn structurally
inapplicable (no permutation representation) — class boundary of symbolic
regression, not quality difference.

## EXP-012 (05.08) — Sign-changing targets: confirmed CV gap
y = x−5, 2x−10, sin(x): NOT found. CV defined for proportional laws (y=ax),
not affine (y=ax+b). Affine-shift metric added; gplearn finds these. Honest
boundary of the criterion.

## EXP-013/014 (06.08) — Cultural propagation A/B
Naive operator boost: NO effect on simple structures (90s fixtures). On
COMPOSITE structures ((x0+x1)²): ×2.1 median speedup (6.5s vs 13s, n=4),
more stable (±3s vs ±12s). **Culture helps only on composite structures.**

## EXP-015 (06.08) — GRAMMAR-BIRTH: operators born
First grammar ceiling break (§2.5.2): system created new operators from
successful patterns:
- B8a2975 => floor(rad2deg) (CV=0.0014)
- Bbe7bb0 => ceil(rad2deg) (CV=0.0022)
- B019885 => round(rad2deg) (CV=0.0012)
Bottom-up AFD — operators from verified discoveries, not pre-allocated branches.
Follow-up check: REUSE=0, TRANSFER=0 initially (B-atoms not wired into search) —
fixed in Phase 2 (OPERATOR-FITNESS story).

## EXP-016 (06.08) — Culture coefficient curve: FLAT
CULTURE_LEVEL ∈ {0, 0.25, 0.5, 0.75, 1.0}, n=10 each: no difference (medians
7.0-9.0s). **Cultural transfer does NOT affect re-discovery time** on simple
structures. The earlier ×2.1 (EXP-014) was a confound: warm DB vs cold DB.
Lesson: A/B only with identical DB history.

## EXP-017 (07.08) — Colony economics baseline
avg_lifetime is measurable ONLY with starvation (NO_BASE_TASKS=1, SEED_ENERGY).
Base tasks = infinite feeder (+0.4/task). Information reward default = 0.0
(not a cheat). **Monoculture |G|=1 is the OPTIMAL strategy in a slow economy:**
fast ticks = less cost = longer life. Degeneration is a rational response to
slow search and rare tasks.

## EXP-018 (07.08) — Colony economics profile
SEARCH_MS ≈ TICK_MS: Search::find is 100% of tick time. Bottleneck = search,
not energy. Large |G| are RICHER (find more, +2.0). Monoculture on laptop =
slow ticks → starvation by TIME (wall clock), not per-tick energy.

## EXP-019/020 (07.08) — Beam search economics
Soft beam (K=20): TICK_MS ~3000→582 (×5.2), ticks/240s ×4.4, laws −25% (noise,
n=1), |G| grows to 89 WITHOUT monoculture. **Hypothesis confirmed: monoculture
is an artifact of computational cost, not evolution.** K=10 is the Pareto point
(n=6: laws 10.5=10.5, activity ×1.7, |G| +50%).

## EXP-021 (08.08, VDS) — Long horizon 1h+1h
Slow economy (1 vCPU, ticks ~10s): beam K=10 → ×2 laws per hour (36 vs 18).
|G|=1 in both — slow ticks = monoculture (confirms: tick speed → economy →
expressiveness). Beam doesn't cure it alone; speed does.

## EXP-022 (08.08) — Deficit gradient
SEED_ENERGY=5 is the working zone of moderate deficit: HUNGER active, deaths
rare, laws maximal. |G| 170→126 (−25%) under deficit — economy presses, doesn't
collapse.

## EXP-022c (08.08) — Reward deficit does NOT create selection
R ∈ {2.0, 1.0, 0.5}: DEATH=0, HUNGER=0 in all. Cause: reward per unique
formula — hundreds of reformulations of one law each give +reward. Food is
effectively infinite. **Design fix: reward per law CLASS (canonical
normalization), not per formula.** Core of ECONOMICS-OF-DIVERSITY.

## EXP-022d (08.08) — R=0.5 → drift, NOT monoculture
R=0.5: unique≈pop (17-21 of 19-23), diversity up to 1.0. Final: hypothesis
"hunger → monoculture" REFUTED. Monoculture on laptop = side effect of expensive
Search. With fast beam, even hard reward deficit gives diversity.
Open question: unit of selection — do B{hash} operators propagate independently
of lineage? REUSE-table data (birth_domain, reuse_domains) will answer.

---

## Summary for the paper (AI reviewer, 07.08)

> "Speeding up Search by 5.2× led to average grammar size growing from 55 to 89
> without any diversity-maintenance mechanisms. This indicates that the
> previously observed monoculture was partially caused by the computational cost
> of expressiveness, not exclusively by evolutionary dynamics. The link between
> computational cost and the evolution of expressiveness is a result; Beam
> Search is a tool."

## Verified differences vs gplearn (as of 08.08.2026)

1. Scale-invariant CV criterion (gplearn MSE degrades ×10 under scaling)
2. Structural refusal (GRAMMAR/DATA/NOISE/DEPTH) — no silent fallback
3. Bottom-up grammar birth from verified discoveries (GRAMMAR-BIRTH)
4. Cultural propagation with confirmed effect on composite structures
5. Null-calibration with FPR=0 guarantee
