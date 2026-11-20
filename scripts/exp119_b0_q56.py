#!/usr/bin/env python3
import json
"""
B0-4: признаки генов + B0-5: преимущество функций над identity.
Данные: Kuzmin 2018 S1 (тройки), Costanzo 2016 (пары, SMF),
SGD GO-аннотации, essentiality.
Признаки гена: essentiality (из сети:essential/Nonessential по частям),
single fitness (SMF), GI-degree (число значимых парных взаимодействий),
доля отрицательных взаимодействий. Всё — независимо от тройного τ.

Модель: предсказать собственный пересчитанный τ_SGA тройки из признаков
участников (a, b, c), holdout = целые query-пары (182 блока, hash-сплит).
Контроли: identity (индикаторы генов), shuffled functions.
"""
import csv, math, statistics, hashlib, random, sys
from collections import defaultdict, Counter
import numpy as np
from sklearn.linear_model import RidgeCV
from sklearn.model_selection import KFold

csv.field_size_limit(10**9)
def norm(a): return a.strip().rstrip('Δ')

# ---------- 1. Пары Costanzo (epsilon, SMF) ----------
pair_eps = {}
pair_smf = {}
pair_neg = defaultdict(lambda: [0, 0])  # gene → [neg, total]
pair_degree = Counter()
for fn in ['SGA_NxN.txt', 'SGA_ExN.txt', 'SGA_ExE.txt', 'SGA_DAmP.txt']:
    path = f'/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/costanzo_pairwise/Data File S1. Raw genetic interaction datasets: Pair-wise interaction format/{fn}'
    with open(path) as f:
        r = csv.DictReader(f, delimiter='\t')
        for row in r:
            q = norm(row['Query allele name']); a = norm(row['Array allele name'])
            key = (q, a) if q < a else (a, q)
            try:
                eps = float(row['Genetic interaction score (ε)'])
            except ValueError:
                continue
            pair_eps[key] = eps
            for g, k in ((q, 'Query single mutant fitness (SMF)'), (a, 'Array SMF')):
                try:
                    v = float(row[k])
                    if g not in pair_smf: pair_smf[g] = v
                except ValueError:
                    pass
            if eps < -0.08:
                pair_neg[q][0] += 1; pair_neg[a][0] += 1
            pair_neg[q][1] += 1; pair_neg[a][1] += 1
            pair_degree[q] += 1; pair_degree[a] += 1
print(f'пары: {len(pair_eps)}, генов с SMF: {len(pair_smf)}')

# ---------- 2. Essentiality: ExE/ExN классы сети ----------
# В Costanzo2016: essential genes — те, что в ExE/ExN как query/DAmP/TSA;
# используем простой признак: ген участвует в ExE (essential-essential).
essential = set()
for fn in ['SGA_ExE.txt', 'SGA_DAmP.txt']:
    path = f'/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/costanzo_pairwise/Data File S1. Raw genetic interaction datasets: Pair-wise interaction format/{fn}'
    with open(path) as f:
        r = csv.DictReader(f, delimiter='\t')
        for row in r:
            essential.add(norm(row['Query allele name']))
            essential.add(norm(row['Array allele name']))
print(f'essential-кандидатов: {len(essential)}')

# ---------- 3. Тройки S1: пересчёт τ_SGA, признаки участников ----------
def genes_of_q(q):
    return [p.strip().rstrip('Δ') for p in q.split('+')]

path = '/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/AdditionalDataS1.tsv'
triples = []
with open(path) as f:
    r = csv.DictReader(f, delimiter='\t')
    for row in r:
        if row['Combined mutant type'] != 'trigenic':
            continue
        a, b = genes_of_q(row['Query allele name'])
        c = norm(row['Array allele name'])
        kac = (a, c) if a < c else (c, a)
        kbc = (b, c) if b < c else (c, b)
        if kac not in pair_eps or kbc not in pair_eps:
            continue
        try:
            w_ab = float(row['Query single/double mutant fitness'])
            w_c = float(row['Array single mutant fitness'])
            w_abc = float(row['Combined mutant fitness'])
            tau_auth = float(row['Adjusted genetic interaction score (epsilon or tau)'])
        except ValueError:
            continue
        w_a, w_b = pair_smf.get(a), pair_smf.get(b)
        if not w_a or not w_b or not w_c: continue
        w_ac = w_a * w_c + pair_eps[kac]
        w_bc = w_b * w_c + pair_eps[kbc]
        vals = (w_ac, w_bc, w_abc, w_ab, w_a, w_b, w_c)
        if any(v <= 0 or v != v for v in vals): continue
        tau_sga = w_abc - w_ab*w_c - w_ac*w_b - w_bc*w_a + 2*w_a*w_b*w_c
        # признаки: SMF каждого гена, essentiality, degree, neg-fraction
        def fvec(g):
            smf = pair_smf.get(g, 1.0)
            ess = 1.0 if g in essential else 0.0
            deg = pair_degree.get(g, 0)
            ng = pair_neg.get(g, [0, 0])
            negfrac = ng[0]/ng[1] if ng[1] > 0 else 0.0
            return [smf, ess, math.log1p(deg), negfrac]
        fa, fb, fc = fvec(a), fvec(b), fvec(c)
        feat = []
        for arr in (fa, fb, fc):
            feat.extend(arr)
        # агрегаты: mean/max/min по трём генам + сумма essentiality
        m = [statistics.mean(x) for x in zip(fa, fb, fc)]
        feat.extend(m)
        feat.append(fa[1] + fb[1] + fc[1])  # число essential в тройке
        feat.append((fa[0] + fb[0]) / 2 - fc[0])  # query SMF − array SMF
        triples.append({'a': a, 'b': b, 'c': c,
                        'qpair': row['Query strain ID'],
                        'tau_auth': tau_auth, 'tau': tau_sga,
                        'feat': feat})
print(f'троек с полным покрытием и признаками: {len(triples)}')

# ---------- 4. сплит по целым query-парам ----------
qpairs = sorted(set(t['qpair'] for t in triples))
def hash_split(q):
    return int(hashlib.md5(q.encode()).hexdigest(), 16) % 1000 < 300
train_q = {q for q in qpairs if not hash_split(q)}
test_q = {q for q in qpairs if hash_split(q)}
tr = [t for t in triples if t['qpair'] in train_q]
ho = [t for t in triples if t['qpair'] in test_q]
print(f'query-пар: train {len(train_q)}, holdout {len(test_q)}; троек: train {len(tr)}, holdout {len(ho)}')

Xtr = np.array([t['feat'] for t in tr]); ytr = np.array([t['tau'] for t in tr])
Xho = np.array([t['feat'] for t in ho]); yho = np.array([t['tau'] for t in ho])

def rmse(y, yp): return float(np.sqrt(np.mean((y - yp) ** 2)))

# ---------- 5. модели ----------
# M1-базовая линия: предсказание tau = медиана train (остаток без признаков)
med = float(np.median(ytr))
rmse_base = rmse(yho, np.full_like(yho, med))
print(f'\nбазовая линия (медиана train): RMSE={rmse_base:.4f}')

def fit_eval(Xtr_, ytr_, Xho_, name):
    m = RidgeCV(alphas=[0.01, 0.1, 1, 10, 100], cv=KFold(5, shuffle=True, random_state=42))
    m.fit(Xtr_, ytr_)
    p = m.predict(Xho_)
    r = rmse(yho, p)
    ss = np.sum((yho - yho.mean())**2)
    r2 = 1 - np.sum((yho - p)**2) / ss
    print(f'{name}: RMSE={r:.4f} (прирост {100*(1-r/rmse_base):.1f}%), R²={r2:+.3f}')
    return r, p

r_func, p_func = fit_eval(Xtr, ytr, Xho, 'функциональные признаки (SMF+ess+degree+neg)')

# identity: индикаторы генов (третий ген = array; query-пара фиксирована в holdout —
# но ген может не встречаться в train query! Используем индикаторы array-гена only)
all_genes = sorted(set([t['c'] for t in triples] + [t['a'] for t in triples] + [t['b'] for t in triples]))
gi = {g: i for i, g in enumerate(all_genes)}
Xtr_id = np.zeros((len(tr), len(all_genes)))
Xho_id = np.zeros((len(ho), len(all_genes)))
for i, t in enumerate(tr): Xtr_id[i, gi[t['c']]] = 1.0
for i, t in enumerate(ho): Xho_id[i, gi[t['c']]] = 1.0
r_id, p_id = fit_eval(Xtr_id, ytr, Xho_id, 'identity (индикатор третьего гена)')

# shuffled functions: перемешиваем SMF/degree/ess между генами
rnd = random.Random(2024)
smf_pool = [pair_smf.get(g, 1.0) for g in all_genes]
ess_pool = [1.0 if g in essential else 0.0 for g in all_genes]
deg_pool = [math.log1p(pair_degree.get(g, 0)) for g in all_genes]
neg_pool = []
for g in all_genes:
    ng = pair_neg.get(g, [0, 0]); neg_pool.append(ng[0]/ng[1] if ng[1] else 0.0)
for pool in (smf_pool, ess_pool, deg_pool, neg_pool):
    rnd.shuffle(pool)
# пулы собираем по всем генам троек
trip_genes = sorted(set([t['c'] for t in triples] + [t['a'] for t in triples] + [t['b'] for t in triples]))
smf_pool = [pair_smf.get(g, 1.0) for g in trip_genes]
ess_pool = [1.0 if g in essential else 0.0 for g in trip_genes]
deg_pool = [math.log1p(pair_degree.get(g, 0)) for g in trip_genes]
neg_pool = []
for g in trip_genes:
    ng = pair_neg.get(g, [0, 0]); neg_pool.append(ng[0]/ng[1] if ng[1] else 0.0)
shuf = dict(zip(trip_genes, zip(smf_pool, ess_pool, deg_pool, neg_pool)))
def fvec_shuf(g): return list(shuf.get(g, [1.0, 0.0, 0.0, 0.0]))
Xtr_s = []
for t in tr:
    fa, fb, fc = fvec_shuf(t['a']), fvec_shuf(t['b']), fvec_shuf(t['c'])
    feat = fa + fb + fc
    m = [statistics.mean(x) for x in zip(fa, fb, fc)]
    feat.extend(m); feat.append(fa[1]+fb[1]+fc[1]); feat.append((fa[0]+fb[0])/2 - fc[0])
    Xtr_s.append(feat)
Xho_s = []
for t in ho:
    fa, fb, fc = fvec_shuf(t['a']), fvec_shuf(t['b']), fvec_shuf(t['c'])
    feat = fa + fb + fc
    m = [statistics.mean(x) for x in zip(fa, fb, fc)]
    feat.extend(m); feat.append(fa[1]+fb[1]+fc[1]); feat.append((fa[0]+fb[0])/2 - fc[0])
    Xho_s.append(feat)
r_sh, p_sh = fit_eval(np.array(Xtr_s), ytr, np.array(Xho_s), 'shuffled functions')

# bootstrap CI прироста (по query-парам holdout, не по строкам!)
ho_pairs = sorted(set(t['qpair'] for t in ho))
rows_by_pair = defaultdict(list)
for t, pf in zip(ho, p_func):
    rows_by_pair[t['qpair']].append((t['tau'], pf))
for i, t in enumerate(ho):
    rows_by_pair[t['qpair']].append((t['tau'], p_id[i]))
rng = np.random.default_rng(2024)
gains = []
for _ in range(1000):
    sel = rng.integers(0, len(ho_pairs), len(ho_pairs))
    ys, ps_f, ps_i = [], [], []
    for s in sel:
        q = ho_pairs[s]
        rec = rows_by_pair[q]
        ys.append(rec[0][0]); ps_f.append(rec[0][1]); ps_i.append(rec[1][1])
    gains.append(rmse(np.array(ys), np.array(ps_i)) - rmse(np.array(ys), np.array(ps_f)))
lo, hi = np.percentile(gains, [2.5, 97.5])
print(f'\nΔRMSE (identity − functions), bootstrap по query-парам: '
      f'медиана {np.median(gains):+.4f}, CI [{lo:+.4f}, {hi:+.4f}]')

json_out = {'rmse_baseline': rmse_base, 'rmse_func': r_func, 'rmse_identity': r_id,
            'rmse_shuffled': r_sh, 'ci': [lo, hi],
            'n_train': len(tr), 'n_holdout': len(ho),
            'train_q': len(train_q), 'test_q': len(test_q)}
json.dump(json_out, open('/tmp/b0_q56.json', 'w'), indent=1)
