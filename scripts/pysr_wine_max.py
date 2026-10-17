#!/usr/bin/env python3
"""A19.5: PySR на wine в максимальном режиме (запрос owner 12.10).

Методология совместима с exp040_star_cells.py (тот же лоадер y=alcohol col1,
тот же split 80/20, та же метрика cv_ratio) — меняется ТОЛЬКО мощность:
populations 31→100, population_size 33→50, timeout 60→900s, maxsize 20→30,
3 сида. Операторы заморожены (как EXP-036: + * - / sqrt square) — честное
сравнение. Для каждого сидa: полный парето-фронт, cv_ratio каждой формы на
holdout, baseline (среднее трейна), OLS-потолок, планка закона A19 (0.5×baseline).

Запуск (laptop): python3 scripts/pysr_wine_max.py
"""
import json, time, warnings
import numpy as np
from pysr import PySRRegressor

warnings.filterwarnings('ignore')
WINE = '/home/ninjacat/.bee_swarm/wine.data'
OUT = '/home/ninjacat/.bee_swarm/logs/exp040/wine_max.json'
SEEDS = [1, 2, 3]
TIMEOUT = 900


def load_wine(path):
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


def ols_cv(Xtr, ytr, Xte, yte):
    A = np.hstack([Xtr, np.ones((len(ytr), 1))])
    coef, *_ = np.linalg.lstsq(A, ytr, rcond=None)
    pred = np.hstack([Xte, np.ones((len(yte), 1))]) @ coef
    return cv_ratio(pred, yte)


def make_model(seed, timeout=TIMEOUT):
    return PySRRegressor(
        niterations=10 ** 9,
        populations=100,
        population_size=50,
        binary_operators=["+", "*", "-", "/"],
        unary_operators=["sqrt", "square"],
        maxsize=30,
        parsimony=0.0005,
        timeout_in_seconds=timeout,
        random_state=seed,
        progress=False,
        verbosity=0,
        tempdir="/tmp/pysr_wine_max",
        delete_tempfiles=True,
        output_jax_format=False,
    )


def main():
    X, y = load_wine(WINE)
    out = {"config": "MAX populations=100 popsize=50 timeout=900s maxsize=30 ops=frozen(+/−/*/sqrt/square)", "seeds": []}
    for seed in SEEDS:
        Xtr, ytr, Xte, yte = split(X, y, seed)
        base_cv = cv_ratio(np.full_like(yte, ytr.mean()), yte)
        ols = ols_cv(Xtr, ytr, Xte, yte)
        law_bar = 0.5 * base_cv
        t0 = time.time()
        model = make_model(seed)
        model.fit(Xtr, ytr)
        dt = time.time() - t0

        eqs = model.equations_
        rows = []
        if eqs is not None and len(eqs) > 0:
            for i, row in eqs.iterrows():
                f = row['lambda_format'] if 'lambda_format' in eqs.columns else None
                try:
                    pred = row['lambda_format'](Xte) if f is not None else model.predict(Xte, index=i)
                except Exception:
                    try:
                        pred = model.predict(Xte, index=i)
                    except Exception:
                        continue
                try:
                    cvh = cv_ratio(np.asarray(pred, dtype=float), yte)
                except Exception:
                    cvh = float('inf')
                rows.append({
                    "complexity": int(row['complexity']),
                    "loss": float(row['loss']),
                    "eq": str(row['sympy_format']) if 'sympy_format' in eqs.columns else str(row['equation']),
                    "cv_holdout": (round(cvh, 6) if np.isfinite(cvh) else 1e6),
                    "beats_baseline": bool(cvh < base_cv),
                    "beats_law_bar": bool(cvh < law_bar),
                })
            rows.sort(key=lambda r: (r['complexity'], r['cv_holdout']))
        seed_out = {
            "seed": seed, "time_s": round(dt, 1),
            "baseline_cv": round(base_cv, 6), "ols_cv": round(ols, 6),
            "law_bar_cv": round(law_bar, 6), "pareto": rows,
        }
        out["seeds"].append(seed_out)
        print(f"== seed {seed}: {dt:.0f}s baseline={base_cv:.4f} OLS={ols:.4f} law_bar={law_bar:.4f}", flush=True)
        for r in rows:
            print("   cpl={complexity:>2} cvH={cv_holdout:.4f} base={beats_baseline} law={beats_law_bar} {eq}".format(**r), flush=True)
        json.dump(out, open(OUT, 'w'), indent=2)
    print("WINE-MAX DONE", flush=True)


if __name__ == '__main__':
    main()
