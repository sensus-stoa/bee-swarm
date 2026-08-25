#!/usr/bin/env python3
"""
ЭКСП-027 v3: корректное сравнение PySR vs Bee Swarm.

1. Frozen split (60/40, seed фикс) — один для обоих методов
2. 20 seeds на WINE: PySR niterations=20, CV_train/CV_holdout каждого
3. 100 null-runs на AUTO-MPG: shuffle y → где 0.195 относительно null?

Выход: JSON + консольная таблица
"""
import sys, time, json, warnings
warnings.filterwarnings("ignore")

import numpy as np
from pysr import PySRRegressor

def load_wine(path):
    X, y = [], []
    for line in open(path):
        parts = line.strip().split(',')
        if len(parts) < 14:
            continue
        y.append(float(parts[1]))
        X.append([float(p) for p in parts[2:14]])
    return np.array(X, dtype=float), np.array(y, dtype=float)

def load_mpg(path):
    X, y = [], []
    for line in open(path):
        parts = line.strip().split()
        if len(parts) < 8:
            continue
        try:
            hp = float(parts[3])
        except ValueError:
            continue
        if hp == 0.0:
            continue
        y.append(float(parts[0]))
        X.append([float(parts[2]), hp, float(parts[4])])
    return np.array(X, dtype=float), np.array(y, dtype=float)

def cv_ratio(pred, y):
    """CV→0: CV(e(x)/y) — тот же критерий, что у пчёл"""
    eps = 1e-9
    ratio = np.abs(pred) / (np.abs(y) + eps)
    m = np.mean(ratio)
    if abs(m) < eps:
        return float('inf')
    return float(np.std(ratio) / abs(m))

def cv_affine(pred, y):
    """CV с shift (AFFINE-LAWS): CV((pred - shift)/(y - shift))"""
    shift = min(np.min(pred), np.min(y)) - 1.0
    p = pred - shift
    t = y - shift
    eps = 1e-9
    ratio = np.abs(p) / (np.abs(t) + eps)
    m = np.mean(ratio)
    if abs(m) < eps:
        return float('inf')
    return float(np.std(ratio) / abs(m))

def make_model(niter=20):
    return PySRRegressor(
        niterations=niter,
        populations=10,
        binary_operators=["+", "*", "-", "/"],
        unary_operators=["sqrt", "log", "exp", "abs"],
        maxsize=20,
        maxdepth=5,
        model_selection="best",
        parsimony=0.001,
        timeout_in_seconds=30,
        progress=False,
        verbosity=0,
    )

def evaluate_formula(model, X_tr, y_tr, X_te, y_te):
    """Frozen split: формула обучена на train, оценка на train+holdout"""
    pred_tr = model.predict(X_tr)
    pred_te = model.predict(X_te)
    return {
        "cv_train": round(cv_ratio(pred_tr, y_tr), 4),
        "cv_holdout": round(cv_ratio(pred_te, y_te), 4),
        "r2_train": float(np.corrcoef(pred_tr, y_tr)[0, 1] ** 2),
        "r2_holdout": float(np.corrcoef(pred_te, y_te)[0, 1] ** 2),
        "formula": str(model.get_best()),
    }

def run_seeds(X, y, n_seeds=20, null=False):
    """n_seeds независимых запусков PySR на одном frozen split"""
    results = []
    rng = np.random.default_rng(42)
    n = len(y)
    idx = np.arange(n)
    rng.shuffle(idx)
    n_tr = int(n * 0.6)
    tr_idx, te_idx = idx[:n_tr], idx[n_tr:]
    X_tr, y_tr = X[tr_idx], y[tr_idx]
    X_te, y_te = X[te_idx], y[te_idx]

    for s in range(n_seeds):
        if null:
            # shuffle y внутри train (target permutation)
            y_tr_s = rng.permutation(y_tr)
        else:
            y_tr_s = y_tr
        try:
            model = make_model()
            model.fit(X_tr, y_tr_s)
            res = evaluate_formula(model, X_tr, y_tr_s, X_te, y_te if not null else rng.permutation(y_te))
            res["seed"] = s
            results.append(res)
        except Exception as e:
            print(f"  seed {s} failed: {e}", file=sys.stderr)
    return results

def main():
    out = {}

    # ── WINE: 20 seeds ──
    print("=== WINE: 20 seeds (frozen split 60/40) ===")
    Xw, yw = load_wine("wine.data")
    wine_results = run_seeds(Xw, yw, n_seeds=20)
    cvs_h = [r["cv_holdout"] for r in wine_results]
    r2_h = [r["r2_holdout"] for r in wine_results]
    pass_rate = sum(1 for c in cvs_h if c <= 0.10) / len(cvs_h)
    out["wine"] = {
        "n": len(wine_results),
        "cv_holdout_median": float(np.median(cvs_h)),
        "cv_holdout_q05": float(np.percentile(cvs_h, 5)),
        "cv_holdout_q95": float(np.percentile(cvs_h, 95)),
        "r2_holdout_median": float(np.median(r2_h)),
        "pass_rate_cv<=0.10": pass_rate,
        "best": min(wine_results, key=lambda r: r["cv_holdout"]),
    }
    print(f"  CV_holdout: median={np.median(cvs_h):.4f} q05={np.percentile(cvs_h,5):.4f} q95={np.percentile(cvs_h,95):.4f}")
    print(f"  pass rate (CV≤0.10): {pass_rate:.2f}  R² median: {np.median(r2_h):.3f}")

    # ── AUTO-MPG: 100 null-runs ──
    print("\n=== AUTO-MPG: 100 null-runs (shuffled target) ===")
    Xm, ym = load_mpg("auto-mpg.data")
    null_results = run_seeds(Xm, ym, n_seeds=100, null=True)
    null_cvs = [r["cv_holdout"] for r in null_results if r["cv_holdout"] != float('inf')]
    if null_cvs:
        out["mpg_null"] = {
            "n": len(null_cvs),
            "cv_null_median": float(np.median(null_cvs)),
            "cv_null_q05": float(np.percentile(null_cvs, 5)),
            "cv_null_q95": float(np.percentile(null_cvs, 95)),
        }
        print(f"  NULL CV: median={np.median(null_cvs):.4f} q05={np.percentile(null_cvs,5):.4f} q95={np.percentile(null_cvs,95):.4f}")
        # где 0.195?
        real_cv = 0.1952
        frac_below = sum(1 for c in null_cvs if c <= real_cv) / len(null_cvs)
        out["mpg_null"]["frac_null_below_0.195"] = frac_below
        print(f"  Доля null-CV ≤ 0.195: {frac_below:.2f}  → 0.195 {'СИГНАЛ (ниже null)' if frac_below < 0.05 else 'не отличим от шума'}")
    else:
        print("  ВСЕ null-runs вернули inf (пусто)")

    # ── AUTO-MPG: 5 real runs (для сравнения с null) ──
    print("\n=== AUTO-MPG: 5 real runs ===")
    mpg_real = run_seeds(Xm, ym, n_seeds=5)
    real_cvs = [r["cv_holdout"] for r in mpg_real]
    out["mpg_real"] = {
        "n": len(real_cvs),
        "cv_holdout_median": float(np.median(real_cvs)),
        "r2_holdout_median": float(np.median([r["r2_holdout"] for r in mpg_real])),
    }
    print(f"  CV_holdout median: {np.median(real_cvs):.4f}  R² median: {np.median([r['r2_holdout'] for r in mpg_real]):.3f}")

    print("\n=== JSON ===")
    print(json.dumps(out, indent=2, ensure_ascii=False, default=str))

if __name__ == "__main__":
    main()
