#!/usr/bin/env python3
"""
B7 этап 3: M2/M3 vs M1 на holdout (butyrate, primary outcome).
Всё по B7_PREREG.md: train = 308 rich>=3 комбинаций, holdout = 139
(hash-сплит заморожен), признаки = геномные (независимые от исходов),
агрегаты per-комбинация: mean/std/min/max по членам.

M2: genome_size_mb, gc, total_KO_genes, distinct_KOs (16 агрегатов)
M3: M2 + purine_core, purine_degradation, butyrate, sulfate (32 агрегата)

Контроли:
 1) shuffled functions: 500 перестановок фич-меток между 25 видами
 2) identity control: ridge на индикаторах членов (25 dummy)
 3) phylogeny control: ridge на class-уровне таксономии (из Zenodo
    taxonomy-файла) — если филогенетические группы объясняют столько же,
    функции — суррогат родства

Ridge: alpha по внутреннему CV на train (GridSearchCV, alphas заморожены
[0.01, 0.1, 1, 10, 100, 1000], 5-fold). Оценка на holdout: RMSE M1 vs
M2 vs M3; ΔR² предсказания residM1. Bootstrap по holdout-комбинациям
(1000) — CI.
"""
import json, statistics, itertools, hashlib, random, csv
import numpy as np
from sklearn.linear_model import RidgeCV
from sklearn.model_selection import KFold

DS = json.load(open('/home/ninjacat/.bee_swarm/Benchmarks/b7/clark25_frozen_dataset.json'))
FEATS = json.load(open('/home/ninjacat/.bee_swarm/Benchmarks/b7/clark25_features.json'))

TARGET = 'butyrate_residM1'   # предсказываем остаток M1 (compositional residual)
BASE_M1 = 'butyrate_residM1'  # baseline = 0 (M1 уже в residM1)

FEATURES_M2 = ['genome_size_mb', 'gc', 'total_KO_genes', 'distinct_KOs']
FEATURES_M3 = ['purine_core_count', 'purine_degradation_count', 'butyrate_count',
               'sulfate_count']
AGGS = ['mean', 'std', 'min', 'max']

def species_feat(code):
    f = FEATS[code]
    return {k: f[k] for k in FEATURES_M2 + FEATURES_M3}

def combo_features(members, feat_species):
    """feat_species: {code: {feature: val}} — позволяет подставлять перетасованные фичи."""
    vals = {f: [] for f in FEATURES_M2 + FEATURES_M3}
    for m in members:
        sf = feat_species[m]
        for f in vals:
            if sf[f] is not None:
                vals[f].append(sf[f])
    out = {}
    for f, xs in vals.items():
        if not xs:
            for a in AGGS:
                out[f'{f}_{a}'] = 0.0
            continue
        out[f'{f}_mean'] = statistics.mean(xs)
        out[f'{f}_std'] = statistics.pstdev(xs) if len(xs) > 1 else 0.0
        out[f'{f}_min'] = min(xs)
        out[f'{f}_max'] = max(xs)
    return out

ALL_SPECIES = sorted(FEATS.keys())

def build_xy(combos, feat_species, feature_list):
    X, y, meta = [], [], []
    for c in combos:
        if c.get(TARGET) is None:
            continue
        cf = combo_features(c['members'], feat_species)
        X.append([cf[f'{f}_{a}'] for f in feature_list for a in AGGS])
        y.append(c[TARGET])
        meta.append(c)
    return np.array(X), np.array(y), meta

train = [c for c in DS['combos'] if c['rich'] >= 3 and not c['hash_holdout']]
hold = [c for c in DS['combos'] if c['rich'] >= 3 and c['hash_holdout']]
print(f'train={len(train)} holdout={len(hold)}')

Xtr, ytr, mtr = build_xy(train, FEATS, FEATURES_M2)
Xho, yho, mho = build_xy(hold, FEATS, FEATURES_M2)
print(f'M2 матрица: train {Xtr.shape}, holdout {Xho.shape}')

def rmse(y, yp): return float(np.sqrt(np.mean((y - yp) ** 2)))

def fit_ridge(X, y):
    alphas = [0.01, 0.1, 1, 10, 100, 1000]
    model = RidgeCV(alphas=alphas, cv=KFold(5, shuffle=True, random_state=42))
    model.fit(X, y)
    return model

def eval_model(name, Xtr_, ytr_, Xho_, yho_):
    model = fit_ridge(Xtr_, ytr_)
    pred = model.predict(Xho_)
    rm = rmse(yho_, pred)
    ss = np.sum((yho_ - yho_.mean()) ** 2)
    r2 = 1 - np.sum((yho_ - pred) ** 2) / ss if ss > 0 else float('nan')
    return {'rmse': rm, 'r2_resid': r2, 'model': model, 'pred': pred}

# baseline M1: предсказание residM1 нулём → RMSE = std(residM1 на holdout)
rmse_m1 = rmse(yho, np.zeros_like(yho))
print(f'\nM1 (ноль в residM1): RMSE={rmse_m1:.2f} mM')

# --- M2, M3 ---
res = {'M1': {'rmse': rmse_m1}}
m2 = eval_model('M2', Xtr, ytr, Xho, yho)
res['M2'] = {'rmse': m2['rmse'], 'r2': m2['r2_resid'], 'rmse_gain_pct': 100*(1-m2['rmse']/rmse_m1)}
print(f"M2: RMSE={m2['rmse']:.2f} (прирост {100*(1-m2['rmse']/rmse_m1):.1f}%), "
      f"R² residM1={m2['r2_resid']:+.3f}")

Xtr3, ytr3, _ = build_xy(train, FEATS, FEATURES_M2 + FEATURES_M3)
Xho3, yho3, _ = build_xy(hold, FEATS, FEATURES_M2 + FEATURES_M3)
m3 = eval_model('M3', Xtr3, ytr3, Xho3, yho3)
res['M3'] = {'rmse': m3['rmse'], 'r2': m3['r2_resid'], 'rmse_gain_pct': 100*(1-m3['rmse']/rmse_m1)}
print(f"M3: RMSE={m3['rmse']:.2f} (прирост {100*(1-m3['rmse']/rmse_m1):.1f}%), "
      f"R² residM1={m3['r2_resid']:+.3f}")

# --- bootstrap CI по holdout-комбинациям ---
rng = np.random.default_rng(2024)
def bootstrap_ci(y, pred1, pred2, n=1000):
    n_h = len(y)
    gains = []
    for _ in range(n):
        idx = rng.integers(0, n_h, n_h)
        g = rmse(y[idx], pred1[idx]) - rmse(y[idx], pred2[idx])
        gains.append(g)
    lo, hi = np.percentile(gains, [2.5, 97.5])
    return lo, hi
lo2, hi2 = bootstrap_ci(yho, np.zeros_like(yho), m2['pred'])
lo3, hi3 = bootstrap_ci(yho, np.zeros_like(yho), m3['pred'])
print(f"\nbootstrap ΔRMSE (M1−M2): {rmse_m1-m2['rmse']:+.2f} mM, CI [{lo2:+.2f}, {hi2:+.2f}]")
print(f"bootstrap ΔRMSE (M1−M3): {rmse_m1-m3['rmse']:+.2f} mM, CI [{lo3:+.2f}, {hi3:+.2f}]")
res['bootstrap'] = {'M2_ci': [lo2, hi2], 'M3_ci': [lo3, hi3]}

# --- контроль 1: shuffled functions (500 перестановок фич-меток между видами) ---
perm_gains2, perm_gains3 = [], []
for it in range(500):
    perm = list(ALL_SPECIES)
    random.Random(1000+it).shuffle(perm)
    shuffled = {orig: FEATS[newcode] for orig, newcode in zip(ALL_SPECIES, perm)}
    Xp, yp, _ = build_xy(train, shuffled, FEATURES_M2)
    Xph, yph, _ = build_xy(hold, shuffled, FEATURES_M2)
    mp = eval_model('shuf', Xp, yp, Xph, yph)
    perm_gains2.append(1 - mp['rmse'] / rmse_m1)
    Xp3, yp3, _ = build_xy(train, shuffled, FEATURES_M2 + FEATURES_M3)
    Xph3, yph3, _ = build_xy(hold, shuffled, FEATURES_M2 + FEATURES_M3)
    mp3 = eval_model('shuf3', Xp3, yp3, Xph3, yph3)
    perm_gains3.append(1 - mp3['rmse'] / rmse_m1)
real_gain2 = 1 - m2['rmse'] / rmse_m1
real_gain3 = 1 - m3['rmse'] / rmse_m1
p2 = sum(1 for g in perm_gains2 if g >= real_gain2) / len(perm_gains2)
p3 = sum(1 for g in perm_gains3 if g >= real_gain3) / len(perm_gains3)
print(f"\nshuffled functions: M2 прирост {real_gain2*100:.1f}% vs null "
      f"[{min(perm_gains2)*100:.1f}..{max(perm_gains2)*100:.1f}%], p={p2:.3f}")
print(f"shuffled functions: M3 прирост {real_gain3*100:.1f}% vs null "
      f"[{min(perm_gains3)*100:.1f}..{max(perm_gains3)*100:.1f}%], p={p3:.3f}")
res['shuffled'] = {'M2_real': real_gain2, 'M2_p': p2, 'M3_real': real_gain3, 'M3_p': p3}

# --- контроль 2: identity (индикаторы членов) ---
def build_identity(combos):
    idx = {s: i for i, s in enumerate(ALL_SPECIES)}
    X = np.zeros((len(combos), len(ALL_SPECIES)))
    for i, c in enumerate(combos):
        for m in c['members']:
            X[i, idx[m]] = 1.0 / c['rich']  # доля члена
    return X
Xi_tr, yi_tr, _ = build_identity(train), ytr, None
Xi_ho, yi_ho, _ = build_identity(hold), yho, None
mi = eval_model('identity', Xi_tr, ytr, Xi_ho, yho)
gain_i = 1 - mi['rmse'] / rmse_m1
print(f"\nidentity control: RMSE={mi['rmse']:.2f}, прирост {gain_i*100:.1f}% "
      f"(vs M2 {real_gain2*100:.1f}%, M3 {real_gain3*100:.1f}%)")
res['identity'] = {'rmse': mi['rmse'], 'gain_pct': gain_i}

# --- контроль 3: phylogeny (class-уровень таксономии) ---
tax = {}
for ln in open('/tmp/clark_taxonomy.txt'):
    p = ln.rstrip().split('\t')
    if len(p) < 2:
        continue
    code = p[0].split('_')[0].split('.')[0]
    ranks = p[1].split(';')
    if len(ranks) >= 3:
        tax[code] = ranks[2]
class_groups = sorted(set(tax.get(s, '?') for s in ALL_SPECIES))
print(f'филогенетические группы (class): {class_groups}')
def build_phylo(combos):
    X = np.zeros((len(combos), len(class_groups)))
    ci = {g: i for i, g in enumerate(class_groups)}
    for i, c in enumerate(combos):
        for m in c['members']:
            g = tax.get(m, '?')
            X[i, ci[g]] += 1.0 / c['rich']
    return X
Xp_tr = build_phylo(train); Xp_ho = build_phylo(hold)
mp = eval_model('phylo', Xp_tr, ytr, Xp_ho, yho)
gain_p = 1 - mp['rmse'] / rmse_m1
print(f"phylogeny control (class dummies): RMSE={mp['rmse']:.2f}, прирост {gain_p*100:.1f}%")
res['phylo'] = {'rmse': mp['rmse'], 'gain_pct': gain_p,
                'classes': {s: tax.get(s, '?') for s in ALL_SPECIES}}

json.dump(res, open('/tmp/b7_phase3_results.json', 'w'), default=float, indent=1)
print('\nсохранено /tmp/b7_phase3_results.json')
