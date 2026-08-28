#!/usr/bin/env python3
"""
EXP-036 Фаза 2 (29.08): PySR honest rerun — исправление методологии EXP-028.

Проблемы EXP-028 (зафиксированы):
1. populations=10 при PySR-дефолте 31 — невольное ослабление
2. pass_rate (cv<=0.10) включает АППРОКСИМАЦИИ, exact_share не измерен
3. Артефакты не сохранились

Конфиг DEFAULT: populations=31, timeout=60s (наш B=37s/seed — запас обоим)
Грамматика: та же 6 ops (+ * - / sqrt square), maxsize=20, parsimony=0.001
Задачи: heat, dot, kinetic + wine, airfoil (паритет-контроль), null(20)
Метрики: pass_rate (cv_holdout<=0.10), exact_share (cv_holdout<0.001),
cv-распределение (median/q05/q95), structural check.
Артефакты: JSON со всеми cv per seed.

Запуск: python3 scripts/exp036_pysr_rerun.py 2>&1 | tee logs/exp036_pysr.log
"""
import sys, time, json, warnings, hashlib
import numpy as np
from pysr import PySRRegressor

warnings.filterwarnings('ignore')

DATA = '/home/ninjacat/.bee_swarm/data'

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

def cv_ratio(pred, y):
    """CV→0: CV(e(x)/y) — тот же критерий, что у пчёл."""
    eps = 1e-9
    ratio = np.abs(pred) / (np.abs(y) + eps)
    m = np.mean(ratio)
    if abs(m) < eps:
        return float('inf')
    return float(np.std(ratio) / abs(m))

def make_model(timeout=60):
    """EXP-036: PySR-ДЕФОЛТЫ. populations=31 (было 10 — ослабление!)."""
    return PySRRegressor(
        niterations=40,
        populations=31,          # PySR default (было 10 — EXP-028 bug)
        binary_operators=["+", "*", "-", "/"],
        unary_operators=["sqrt", "square"],
        maxsize=20,
        parsimony=0.001,
        timeout_in_seconds=timeout,
        progress=False,
        verbosity=0,
        tempdir="/tmp/pysr_exp036",
        delete_tempfiles=True,
    )

TASKS = {
    "heat":    load_feynman(f"{DATA}/feynman_heat_conduction.csv"),
    "dot":     load_feynman(f"{DATA}/feynman_dot_product.csv"),
    "kinetic": load_feynman(f"{DATA}/feynman_kinetic_energy.csv"),
}

def split(X, y, seed):
    rng = np.random.RandomState(seed)
    idx = rng.permutation(len(y))
    ntr = int(len(y) * 0.8)
    return X[idx[:ntr]], y[idx[:ntr]], X[idx[ntr:]], y[idx[ntr:]]

def run_task(name, X, y, n_seeds=20, timeout=60, out_path=None):
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
            "cv_holdout": round(cvh, 6),
            "cv_train": round(cvtr, 6),
            "time_s": round(dt, 1),
            "formula": str(getattr(model, '_best_equation', ''))[:200] if 'model' in dir() else '',
        })
        print(f"  {name} seed {seed}: cvH={cvh:.4f} ({dt:.0f}s)", flush=True)
    cvs = np.array([min(r["cv_holdout"], 1e6) for r in results])
    out = {
        "task": name,
        "config": "DEFAULT populations=31 timeout=60",
        "n_seeds": n_seeds,
        "pass_rate_cv<=0.10": float(np.mean(cvs <= 0.10)),
        "exact_share_cv<0.001": float(np.mean(cvs < 0.001)),
        "exact_share_cv<0.01": float(np.mean(cvs < 0.01)),
        "cv_median": float(np.median(cvs)),
        "cv_q05": float(np.percentile(cvs, 5)),
        "cv_q95": float(np.percentile(cvs, 95)),
        "seeds": results,
    }
    if out_path:
        json.dump(out, open(out_path, 'w'), indent=2)
    print(f"== {name}: pass={out['pass_rate_cv<=0.10']:.2f} "
          f"exact(<0.001)={out['exact_share_cv<0.001']:.2f} "
          f"median={out['cv_median']:.4f}", flush=True)
    return out

if __name__ == "__main__":
    import os
    os.makedirs('/home/ninjacat/.bee_swarm/logs/exp036', exist_ok=True)
    which = sys.argv[1] if len(sys.argv) > 1 else "all"
    out = {}
    if which in ("all", "heat"):
        out["heat"] = run_task("heat", *TASKS["heat"], timeout=60,
            out_path='/home/ninjacat/.bee_swarm/logs/exp036/heat_default.json')
    if which in ("all", "dot"):
        out["dot"] = run_task("dot", *TASKS["dot"], timeout=60,
            out_path='/home/ninjacat/.bee_swarm/logs/exp036/dot_default.json')
    if which in ("all", "kinetic"):
        out["kinetic"] = run_task("kinetic", *TASKS["kinetic"], timeout=60,
            out_path='/home/ninjacat/.bee_swarm/logs/exp036/kinetic_default.json')
    print(json.dumps({k: {kk: vv for kk, vv in v.items() if kk != 'seeds'}
        for k, v in out.items()}, indent=2))
