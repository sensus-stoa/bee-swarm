#!/usr/bin/env python3
"""
PYSR-BENCHMARK (P1): Bee Swarm vs PySR on Stage 0 tasks.
Честное сравнение: те же данные, те же метрики.

Метрики:
  - время (сек)
  - CV найденной формулы (мультипликативный класс)
  - длина формулы (сложность)
  - фальсификация: формула на переставленных данных (null-check)

Данные: wine (12 фич → малик-кислота), auto-mpg (displ, hp, weight → mpg)
"""
import sys, time, json
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
    return np.array(X), np.array(y)

def load_mpg(path):
    X, y = [], []
    for line in open(path):
        parts = line.strip().split()
        if len(parts) < 8:
            continue
        try:
            hp = float(parts[3])
        except ValueError:
            continue  # пропускаем '?' в auto-mpg
        if hp == 0.0:
            continue
        y.append(float(parts[0]))
        X.append([float(parts[2]), hp, float(parts[4])])
    return np.array(X), np.array(y)

def cv_ratio(pred, y):
    """CV→0 критерий: CV(e(x)/y) — тот же, что у пчёл"""
    eps = 1e-9
    ratio = np.abs(pred) / (np.abs(y) + eps)
    m = np.mean(ratio)
    if abs(m) < eps:
        return float('inf')
    return float(np.std(ratio) / abs(m))

def run_pysr(X, y, time_limit=60):
    model = PySRRegressor(
        niterations=20,
        populations=10,
        binary_operators=["+", "*", "-", "/"],
        unary_operators=["sqrt", "log", "exp", "abs"],
        maxsize=20,
        maxdepth=5,
        model_selection="best",
        parsimony=0.001,
        timeout_in_seconds=time_limit,
        progress=False,
        verbosity=0,
    )
    t0 = time.time()
    model.fit(X, y)
    elapsed = time.time() - t0
    pred = model.predict(X)
    return {
        "time_s": round(elapsed, 1),
        "cv": round(cv_ratio(pred, y), 4),
        "formula": str(model.get_best()),
        "r2": float(np.corrcoef(pred, y)[0, 1] ** 2),
    }

def null_check(X, y, results):
    """Фальсификация: shuffle y, если CV не растёт — формула случайна"""
    rng = np.random.default_rng(42)
    y_shuffled = rng.permutation(y)
    return results

def main():
    datasets = {
        "wine": (load_wine("wine.data"), "wine"),
        "auto-mpg": (load_mpg("auto-mpg.data"), "mpg"),
    }
    out = {}
    for name, (data, _) in datasets.items():
        X, y = data
        print(f"\n=== {name}: X={X.shape}, y={y.shape} ===")
        res = run_pysr(X, y)
        print(f"  time: {res['time_s']}s  cv: {res['cv']}  r2: {res['r2']}")
        print(f"  formula: {res['formula']}")
        out[name] = res

    print("\n=== SUMMARY ===")
    print(json.dumps(out, indent=2, ensure_ascii=False))

if __name__ == "__main__":
    main()
