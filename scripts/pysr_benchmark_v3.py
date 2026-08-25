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

def load_uci(path):
    X, y = [], []
    for line in open(path):
        parts = line.strip().split(',')
        if len(parts) < 2:
            continue
        vals = [float(p) for p in parts]
        X.append(vals[:-1])
        y.append(vals[-1])
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

def make_model(time_limit=30, niter=20):
    return PySRRegressor(
        niterations=niter,
        populations=10,
        binary_operators=["+", "*", "-", "/"],
        unary_operators=["sqrt", "square"],
        maxsize=20,
        maxdepth=5,
        model_selection="best",
        parsimony=0.001,
        timeout_in_seconds=time_limit,
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

def run_seeds(X, y, n_seeds=20, null=False, time_limit=30):
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
            model = make_model(time_limit)
            model.fit(X_tr, y_tr_s)
            res = evaluate_formula(model, X_tr, y_tr_s, X_te, y_te if not null else rng.permutation(y_te))
            res["seed"] = s
            results.append(res)
        except Exception as e:
            print(f"  seed {s} failed: {e}", file=sys.stderr)
    return results

def main():
    out = {}
    datasets = {
        "wine": (load_wine("wine.data"), "uci"),
        "auto-mpg": (load_mpg("auto-mpg.data"), "uci"),
        "feynman_gravity": (load_feynman("data/feynman_gravity.csv"), "feyn"),
        "feynman_kinetic": (load_feynman("data/feynman_kinetic_energy.csv"), "feyn"),
        "feynman_dot": (load_feynman("data/feynman_dot_product.csv"), "feyn"),
        "feynman_heat": (load_feynman("data/feynman_heat_conduction.csv"), "feyn"),
        "feynman_relmass": (load_feynman("data/feynman_relativistic_mass.csv"), "feyn"),
        "feynman_kinetic_noise5": (load_feynman("data/feynman_kinetic_energy_noise5.csv"), "feyn"),
        "feynman_coulomb_noise15": (load_feynman("data/feynman_coulomb_noise15.csv"), "feyn"),
        "concrete": (load_uci("data/concrete_strength.csv"), "uci"),
        "airfoil": (load_uci("data/airfoil_selfnoise.csv"), "uci"),
        "energy": (load_uci("data/energy_efficiency.csv"), "uci"),
    }

    # ── ВСЕ 12 задач (WINE уже замерен отдельно): 20 seeds, общая грамматика ──
    for name, (data, kind) in datasets.items():
        X, y = data
        print(f"\n=== {name}: X={X.shape} ===")
        tl = 20 if kind == "feyn" else 30
        results = run_seeds(X, y, n_seeds=20, time_limit=tl)
        cvs = [r["cv_holdout"] for r in results]
        r2s = [r["r2_holdout"] for r in results]
        pass_rate = sum(1 for cv in cvs if cv <= 0.10) / len(cvs)
        entry = {
            "n": len(results),
            "cv_holdout_median": float(np.median(cvs)),
            "cv_holdout_q05": float(np.percentile(cvs, 5)),
            "cv_holdout_q95": float(np.percentile(cvs, 95)),
            "r2_holdout_median": float(np.median(r2s)),
            "pass_rate_cv<=0.10": pass_rate,
        }
        if results:
            entry["best"] = min(results, key=lambda r: r["cv_holdout"])
        out[name] = entry
        print(f"  CV_H med={np.median(cvs):.4f} q05={np.percentile(cvs,5):.4f} "
              f"q95={np.percentile(cvs,95):.4f} pass={pass_rate:.2f} R2={np.median(r2s):.3f}")

    print("\n=== JSON ===")
    print(json.dumps(out, indent=2, ensure_ascii=False, default=str))


if __name__ == "__main__":
    main()
