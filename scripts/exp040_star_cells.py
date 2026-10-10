#!/usr/bin/env python3
"""
EXP-040: rerun of the 4 starred PySR cells (wine, airfoil, gravity, relmass)
under the frozen EXP-036 methodology. Pre-registration:
/home/ninjacat/EvoFamily/ProductSpecification/stories/exp-040/PREREG.md
(owner-OK 10.10.2026). Null-guard included: 100 shuffled-target runs on wine.
Bee-side cells are NOT recomputed (out of scope, see PREREG section 2).

Run (laptop, unattended): nohup python3 scripts/exp040_star_cells.py > logs/exp040/run.log 2>&1 &
"""
import json, os, sys, time, hashlib, warnings
import numpy as np
from pysr import PySRRegressor

warnings.filterwarnings('ignore')
DATA = '/home/ninjacat/.bee_swarm/data'
OUTDIR = '/home/ninjacat/.bee_swarm/logs/exp040'
SWARM = '/home/ninjacat/.bee_swarm'
SEEDS = 20
TIMEOUT = 60
NULL_RUNS = 100
NULL_FPR_LIMIT = 0.05

def load_feynman(path):
    X, y = [], []
    for line in open(path):
        parts = line.strip().split(',')
        if len(parts) < 2:
            continue
        vals = [float(p) for p in parts]
        X.append(vals[:-1])
        y.append(vals[-1])
    return np.array(X, dtype=float), np.array(y, dtype=float)

def load_wine(path):
    """FIXED per review: y = alcohol (col 1), X = cols 2..13 — the SAME
    continuous task as bee_benchmark_v3.php loadWine and EXP-028 pysr_benchmark.
    The first run mistakenly used y = class (col 0): a 3-level target,
    out of scope for ratio-CV per protocol 1.13."""
    X, y = [], []
    for line in open(path):
        parts = line.strip().split(',')
        if len(parts) < 14:
            continue
        vals = [float(p) for p in parts]
        y.append(vals[1])
        X.append(vals[2:14])
    return np.array(X, dtype=float), np.array(y, dtype=float)

def cv_ratio(pred, y):
    eps = 1e-9
    ratio = np.abs(pred) / (np.abs(y) + eps)
    m = np.mean(ratio)
    if abs(m) < eps:
        return float('inf')
    return float(np.std(ratio) / abs(m))

def split(X, y, seed):
    rng = np.random.RandomState(seed)
    idx = rng.permutation(len(y))
    ntr = int(len(y) * 0.8)
    return X[idx[:ntr]], y[idx[:ntr]], X[idx[ntr:]], y[idx[ntr:]]

def make_model(timeout=TIMEOUT):
    # EXP-036 DEFAULTS: populations=31 (EXP-028 bug was 10), timeout 60s
    return PySRRegressor(
        niterations=40,
        populations=31,
        binary_operators=["+", "*", "-", "/"],
        unary_operators=["sqrt", "square"],
        maxsize=20,
        parsimony=0.001,
        timeout_in_seconds=timeout,
        progress=False,
        verbosity=0,
        tempdir="/tmp/pysr_exp040",
        delete_tempfiles=True,
    )

def run_task(name, X, y, n_seeds=SEEDS, timeout=TIMEOUT, out_path=None):
    results = []
    for seed in range(1, n_seeds + 1):
        Xtr, ytr, Xte, yte = split(X, y, seed)
        t0 = time.time()
        try:
            model = make_model(timeout)
            model.fit(Xtr, ytr)
            pred = model.predict(Xte)
            cvh = cv_ratio(pred, yte)
            cvtr = cv_ratio(model.predict(Xtr), ytr)
        except Exception as e:
            cvh, cvtr = float('inf'), float('inf')
        dt = time.time() - t0
        results.append({
            "seed": seed,
            "cv_holdout": (round(cvh, 6) if np.isfinite(cvh) else 1e6),
            "cv_train": (round(cvtr, 6) if np.isfinite(cvtr) else 1e6),
            "time_s": round(dt, 1),
        })
        print(f"  {name} seed {seed}: cvH={cvh:.4f} ({dt:.0f}s)", flush=True)
    cvs = np.array([r["cv_holdout"] for r in results], dtype=float)
    out = {
        "task": name,
        "exp": "EXP-040",
        "config": "DEFAULT populations=31 timeout=60 (frozen, PREREG)",
        "n_seeds": n_seeds,
        "pass_rate_cv<=0.10": float(np.mean(cvs <= 0.10)),
        "exact_share_cv<0.001": float(np.mean(cvs < 0.001)),
        "cv_median": float(np.median(cvs)),
        "cv_q05": float(np.percentile(cvs, 5)),
        "cv_q95": float(np.percentile(cvs, 95)),
        "seeds": results,
    }
    if out_path:
        json.dump(out, open(out_path, 'w'), indent=2)
    print(f"== {name}: pass={out['pass_rate_cv<=0.10']:.2f} "
          f"exact={out['exact_share_cv<0.001']:.2f} median={out['cv_median']:.4f}", flush=True)
    return out

def run_null_wine(X, y, n_runs=NULL_RUNS):
    """Null-guard: shuffled target; PySR must NOT find 'laws'.
    PREREG section 5: FPR > 0.05 => methodology broken, results void."""
    found = 0
    cvs = []
    rng_master = np.random.RandomState(401)  # dedicated null seed stream
    for i in range(n_runs):
        Xtr, ytr, Xte, yte = split(X, y, (i % SEEDS) + 1)
        shuffled = ytr.copy()
        rng_master.shuffle(shuffled)
        try:
            model = make_model(timeout=30)  # halved: null runs are cheap to reject
            model.fit(Xtr, shuffled)
            pred = model.predict(Xte)
            cvh = cv_ratio(pred, yte)
        except Exception:
            cvh = float('inf')
        if np.isfinite(cvh) and cvh <= 0.10:
            found += 1
            cvs.append(round(cvh, 6))
        if (i + 1) % 25 == 0:
            print(f"  null {i+1}/{n_runs}: found so far {found}", flush=True)
    fpr = found / n_runs
    out = {
        "task": "null_wine_shuffle",
        "exp": "EXP-040",
        "n_runs": n_runs,
        "found": found,
        "fpr": fpr,
        "limit": NULL_FPR_LIMIT,
        "verdict": "METHODOLOGY VOID" if fpr > NULL_FPR_LIMIT else "PASS",
        "cvs_found": cvs,
    }
    json.dump(out, open(f"{OUTDIR}/null_wine.json", 'w'), indent=2)
    print(f"== null: FPR={fpr:.3f} ({found}/{n_runs}) -> {out['verdict']}", flush=True)
    return out

if __name__ == "__main__":
    os.makedirs(OUTDIR, exist_ok=True)
    t_start = time.time()
    print(f"EXP-040 start {time.strftime('%Y-%m-%d %H:%M:%S')}", flush=True)

    tasks = {
        "wine":    load_wine(f"{SWARM}/wine.data"),
        "airfoil": load_feynman(f"{DATA}/airfoil_selfnoise.csv"),
        "gravity": load_feynman(f"{DATA}/feynman_gravity.csv"),
        "relmass": load_feynman(f"{DATA}/feynman_relativistic_mass.csv"),
    }

    # null-guard FIRST: if methodology is broken, do not waste the night
    wine = tasks["wine"]
    null_out = run_null_wine(*wine)
    if null_out["verdict"] == "METHODOLOGY VOID":
        print("NULL GUARD FAILED — aborting per PREREG section 5", flush=True)
        json.dump({"aborted": "null guard failed", "null": null_out},
                  open(f"{OUTDIR}/ABORTED.json", 'w'), indent=2)
        sys.exit(2)

    out = {"null": null_out}
    for name in ("wine", "airfoil", "gravity", "relmass"):
        X, y = tasks[name]
        out[name] = run_task(name, X, y,
                             out_path=f"{OUTDIR}/{name}_exp040.json")

    # Wilson 95% CI per PREREG section 5
    from math import sqrt
    def wilson(k, n, z=1.96):
        p = k / n
        d = 1 + z*z/n
        c = (p + z*z/(2*n)) / d
        h = z*sqrt(p*(1-p)/n + z*z/(4*n*n)) / d
        return [round(max(0, c-h)*100, 1), round(min(1, c+h)*100, 1)]

    bee = {"wine": (20, 20), "airfoil": (20, 20), "gravity": (6, 20), "relmass": (20, 20)}
    summary = {}
    for name in ("wine", "airfoil", "gravity", "relmass"):
        k = int(round(out[name]["pass_rate_cv<=0.10"] * SEEDS))
        ci_p = wilson(k, SEEDS)
        ci_b = wilson(*bee[name])
        overlap = not (ci_p[0] > ci_b[1] or ci_b[0] > ci_p[1])
        summary[name] = {
            "pysr_pass": f"{k}/{SEEDS}", "pysr_ci95": ci_p,
            "bee_pass": f"{bee[name][0]}/{bee[name][1]}", "bee_ci95": ci_b,
            "cis_overlap": overlap,
        }
        print(f"SUMMARY {name}: PySR {k}/{SEEDS} {ci_p} vs Bee "
              f"{bee[name][0]}/{bee[name][1]} {ci_b} overlap={overlap}", flush=True)
    out["wilson_summary"] = summary
    json.dump(out, open(f"{OUTDIR}/exp040_results.json", 'w'), indent=2)
    print(f"EXP-040 DONE in {(time.time()-t_start)/60:.0f} min", flush=True)
