#!/usr/bin/env python3
"""
B0-P этапы P2+P3: предсказание τ_SGA из композиции паттернов.
Замороженные признаки (без тавтологии — не используем W_ac/W_bc):
- метки паттернов участников (из графа сходства, exp123)
- same_pattern_{ab,ac,bc}, размеры кластеров
- lookup: среднее τ на train по ячейке (pattern_pair × pattern_c) —
  обучается только на train-тройках
Сравнение: baseline (медиана) / identity (индикатор c) / P-1 / P-2.
Bootstrap по целым query-парам holdout.
"""
import csv, math, json, hashlib, statistics, sys, random
from collections import defaultdict, Counter
import numpy as np
from sklearn.linear_model import RidgeCV
from sklearn.model_selection import KFold

csv.field_size_limit(10**9)
def norm(a): return a.strip().rstrip('Δ')

labels = json.load(open('/tmp/b0p_labels.json'))['labels']
pair_eps_all = {}; pair_smf = {}
for fn in ['SGA_NxN.txt', 'SGA_ExN.txt', 'SGA_ExE.txt', 'SGA_DAmP.txt']:
    path = f'/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/costanzo_pairwise/Data File S1. Raw genetic interaction datasets: Pair-wise interaction format/{fn}'
    with open(path) as f:
        r = csv.DictReader(f, delimiter='\t')
        for row in r:
            q = norm(row['Query allele name']); a = norm(row['Array allele name'])
            key = (q, a) if q < a else (a, q)
            try: pair_eps_all[key] = float(row['Genetic interaction score (ε)'])
            except ValueError: pass
            for g, k in ((q, 'Query single mutant fitness (SMF)'), (a, 'Array SMF')):
                try:
                    v = float(row[k])
                    if g not in pair_smf: pair_smf[g] = v
                except ValueError: pass

def genes_of_q(q): return [p.strip().rstrip('Δ') for p in q.split('+')]
triples = []
with open('/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/AdditionalDataS1.tsv') as f:
    r = csv.DictReader(f, delimiter='\t')
    for row in r:
        if row['Combined mutant type'] != 'trigenic': continue
        a, b = genes_of_q(row['Query allele name'])
        c = norm(row['Array allele name'])
        kac = (a, c) if a < c else (c, a)
        kbc = (b, c) if b < c else (c, b)
        if kac not in pair_eps_all or kbc not in pair_eps_all: continue
        try:
            w_ab = float(row['Query single/double mutant fitness'])
            w_c = float(row['Array single mutant fitness'])
            w_abc = float(row['Combined mutant fitness'])
        except ValueError: continue
        w_a, w_b = pair_smf.get(a), pair_smf.get(b)
        if not w_a or not w_b or not w_c: continue
        w_ac = w_a*w_c + pair_eps_all[kac]
        w_bc = w_b*w_c + pair_eps_all[kbc]
        vals = (w_ac, w_bc, w_abc, w_ab, w_a, w_b, w_c)
        if any(v <= 0 or v != v for v in vals): continue
        triples.append({'qpair': row['Query strain ID'], 'a': a, 'b': b, 'c': c,
                        'tau': w_abc - w_ab*w_c - w_ac*w_b - w_bc*w_a + 2*w_a*w_b*w_c})

qpairs = sorted(set(t['qpair'] for t in triples))
def hs(q): return int(hashlib.md5(q.encode()).hexdigest(), 16) % 1000 < 300
tr = [t for t in triples if not hs(t['qpair'])]
ho = [t for t in triples if hs(t['qpair'])]
print(f'train {len(tr)} / holdout {len(ho)}')

def patt(g): return labels.get(g, -1)   # -1 = нет паттерна
N_PAT = max([v for v in labels.values()] + [0]) + 2

# lookup: (pattern_of_pair, pattern_of_c) → mean tau on train (smoothed)
cell_sum = defaultdict(float); cell_n = defaultdict(int)
for t in tr:
    pa, pb, pc = patt(t['a']), patt(t['b']), patt(t['c'])
    pair_key = (pa, pb) if pa <= pb else (pb, pa)
    cell_sum[(pair_key, pc)] += t['tau']; cell_n[(pair_key, pc)] += 1
global_mean = statistics.mean(t['tau'] for t in tr)
def cell_pred(pa, pb, pc, alpha=5.0):
    pair_key = (pa, pb) if pa <= pb else (pb, pa)
    n = cell_n[(pair_key, pc)]
    if n == 0: return global_mean
    return (cell_sum[(pair_key, pc)] + alpha * global_mean) / (n + alpha)

def feats(t, mode):
    pa, pb, pc = patt(t['a']), patt(t['b']), patt(t['c'])
    same_ab = 1.0 if pa == pb and pa >= 0 else 0.0
    same_ac = 1.0 if pa == pc and pa >= 0 else 0.0
    same_bc = 1.0 if pb == pc and pb >= 0 else 0.0
    if mode == 'P1':
        v = [same_ab, same_ac, same_bc, pc, pa, pb]
        cp = cell_pred(pa, pb, pc)
        v.append(cp)
        return v
    return v

def make_X(rows, mode):
    return np.array([feats(t, mode) for t in rows])

def rmse(y, yp): return float(np.sqrt(np.mean((np.asarray(y) - np.asarray(yp)) ** 2)))
ytr = np.array([t['tau'] for t in tr]); yho = np.array([t['tau'] for t in ho])
med = float(np.median(ytr))
rmse_base = rmse(yho, np.full_like(yho, med))
print(f'baseline (медиана): {rmse_base:.4f}')

# identity (индикатор третьего гена)
all_c = sorted(set(t['c'] for t in triples))
gi = {g: i for i, g in enumerate(all_c)}
Xtr_id = np.zeros((len(tr), len(all_c))); Xho_id = np.zeros((len(ho), len(all_c)))
for i, t in enumerate(tr): Xtr_id[i, gi[t['c']]] = 1.0
for i, t in enumerate(ho): Xho_id[i, gi[t['c']]] = 1.0

results = {}
def fit_eval(name, Xtr_, Xho_):
    m = RidgeCV(alphas=[0.01, 0.1, 1, 10, 100], cv=KFold(5, shuffle=True, random_state=42))
    m.fit(Xtr_, ytr)
    p = m.predict(Xho_)
    r = rmse(yho, p)
    ss = np.sum((yho - yho.mean())**2)
    r2 = 1 - np.sum((yho - p)**2)/ss
    print(f'{name}: RMSE={r:.4f} (Δ {100*(1-r/rmse_base):+.1f}%), R²={r2:+.3f}')
    results[name] = {'rmse': r, 'r2': r2}
    return p

p_id = fit_eval('identity', Xtr_id, Xho_id)
Xtr_p = make_X(tr, 'P1'); Xho_p = make_X(ho, 'P1')
p_pat = fit_eval('P-1 паттерны (ind + lookup)', Xtr_p, Xho_p)

# lookup-only (просто ячейка)
p_lookup = np.array([cell_pred(patt(t['a']), patt(t['b']), patt(t['c'])) for t in ho])
r_lookup = rmse(yho, p_lookup)
print(f'lookup-only (pattern×pattern mean): RMSE={r_lookup:.4f} (Δ {100*(1-r_lookup/rmse_base):+.1f}%)')
results['lookup_only'] = {'rmse': r_lookup}

# --- P2: ролевая эквивалентность — корреляция смещений τ при замене c→c' внутри паттерна
# для каждой query-пары holdout: смещение (τ − медиана по паре) коррелирует
# между генами одного паттерна?
by_pair = defaultdict(dict)
for t in ho:
    by_pair[t['qpair']][t['c']] = t['tau']
sims_path = '/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/costanzo_sim/Data File S3. Genetic interaction profile similarity matrices/Global_Similarity_Network.txt'
p2_num = 0; p2_den = 0
with open(sims_path) as f:
    rdr = csv.reader(f, delimiter='\t')
    hdr = next(rdr); next(rdr)
    genes_row = hdr[1:]
    gidx = {g: i for i, g in enumerate(genes_row)}
    for row in rdr:
        g = row[0]
        if g not in labels: continue
        cg = labels[g]
        # партнёры того же паттерна, встречающиеся в holdout-полях этой пары
        for qpair, cdict in by_pair.items():
            if g not in cdict: continue
            my_offset = cdict[g] - statistics.median(cdict.values())
            for other, t_other in cdict.items():
                if other == g or labels.get(other) != cg: continue
                # партнёр того же паттерна в той же query-паре
                i, j = gidx.get(g), gidx.get(other)
                if i is None or j is None: continue
                p2_num += my_offset * (t_other - statistics.median(cdict.values()))
                p2_den += 1
if p2_den:
    print(f'\nP2: пар замен внутри паттерна в одной query-паре: {p2_den}; '
          f'знак согласованного смещения: {"ЕСТЬ" if p2_num > 0 else "НЕТ"} (проверка через permutation ниже)')

# permutation-null для P2: перемешиваем паттерн-метки между генами
rnd = random.Random(2024)
def perm_test(n_perm=50):
    cnt = 0
    for _ in range(n_perm):
        vals = list(labels.values())
        rnd.shuffle(vals)
        pl = dict(zip(labels.keys(), vals))
        s = 0.0; n = 0
        for qpair, cdict in by_pair.items():
            medq = statistics.median(cdict.values())
            for g, t in cdict.items():
                cg = pl.get(g)
                for other, t2 in cdict.items():
                    if other != g and pl.get(other) == cg:
                        s += (t - medq) * (t2 - medq); n += 1
        if p2_num > 0 and s >= p2_num: cnt += 1
    return cnt / n_perm
if p2_den:
    pp = perm_test(30)
    print(f'P2 permutation-null (30 перемешиваний): p={pp:.3f}')

results['P2'] = {'num': p2_num, 'den': p2_den}
json.dump(results, open('/tmp/b0p_p23.json', 'w'), indent=1)
print('сохранено /tmp/b0p_p23.json')
