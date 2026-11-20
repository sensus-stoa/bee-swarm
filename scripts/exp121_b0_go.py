#!/usr/bin/env python3
"""
B0-6: GO-признаки — «композиция функций» в генетике.
Признаки тройки: Jaccard GO-термов и число общих термов пар (a,b),(a,c),(b,c).
Тест: базовые признаки (SMF/ess/degree/neg) vs +GO vs identity на holdout
целых query-пар.
"""
import csv, math, statistics, gzip, hashlib, random, json
from collections import defaultdict, Counter
import numpy as np
from sklearn.linear_model import RidgeCV
from sklearn.model_selection import KFold

csv.field_size_limit(10**9)
def norm(a): return a.strip().rstrip('Δ')

# --- GO: ORF → термы (без IEA) ---
go_sets = defaultdict(set)
sym2orf = {}
with gzip.open('/tmp/sgd.gaf.gz', 'rt') as f:
    for ln in f:
        if ln.startswith('!'): continue
        p = ln.rstrip().split('\t')
        if len(p) < 9: continue
        orf = p[1]; sym = p[2]; go_id = p[4]; ev = p[6]; aspect = p[8]
        sym2orf.setdefault(sym, orf); sym2orf.setdefault(sym.upper(), orf)
        if ev == 'IEA': continue
        go_sets[orf].add(f'{aspect}:{go_id}')
print(f'GO: {len(go_sets)} ORF; symbol→ORF: {len(sym2orf)}')

def go_sim(g1, g2):
    o1 = sym2orf.get(g1.upper()); o2 = sym2orf.get(g2.upper())
    if not o1 or not o2: return None
    s1, s2 = go_sets.get(o1), go_sets.get(o2)
    if not s1 or not s2: return None
    inter = len(s1 & s2); uni = len(s1 | s2)
    return (inter/uni if uni else 0.0, inter)

# --- Costanzo пары ---
pair_eps = {}; pair_smf = {}
pair_neg = defaultdict(lambda: [0, 0]); pair_degree = Counter()
for fn in ['SGA_NxN.txt', 'SGA_ExN.txt', 'SGA_ExE.txt', 'SGA_DAmP.txt']:
    path = f'/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/costanzo_pairwise/Data File S1. Raw genetic interaction datasets: Pair-wise interaction format/{fn}'
    with open(path) as f:
        r = csv.DictReader(f, delimiter='\t')
        for row in r:
            q = norm(row['Query allele name']); a = norm(row['Array allele name'])
            key = (q, a) if q < a else (a, q)
            try: eps = float(row['Genetic interaction score (ε)'])
            except ValueError: continue
            pair_eps[key] = eps
            for g, k in ((q, 'Query single mutant fitness (SMF)'), (a, 'Array SMF')):
                try:
                    v = float(row[k])
                    if g not in pair_smf: pair_smf[g] = v
                except ValueError: pass
            if eps < -0.08:
                pair_neg[q][0] += 1; pair_neg[a][0] += 1
            pair_neg[q][1] += 1; pair_neg[a][1] += 1
            pair_degree[q] += 1; pair_degree[a] += 1
essential = set()
for fn in ['SGA_ExE.txt', 'SGA_DAmP.txt']:
    path = f'/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/costanzo_pairwise/Data File S1. Raw genetic interaction datasets: Pair-wise interaction format/{fn}'
    with open(path) as f:
        r = csv.DictReader(f, delimiter='\t')
        for row in r:
            essential.add(norm(row['Query allele name']))
            essential.add(norm(row['Array allele name']))

# --- тройки: τ_SGA + признаки (базовые + GO) ---
def genes_of_q(q):
    return [p.strip().rstrip('Δ') for p in q.split('+')]

def base_feats(fa, fb, fc):
    feat = []
    for arr in (fa, fb, fc): feat.extend(arr)
    m = [statistics.mean(x) for x in zip(fa, fb, fc)]
    feat.extend(m)
    feat.append(fa[1] + fb[1] + fc[1])
    feat.append((fa[0] + fb[0]) / 2 - fc[0])
    return feat

triples = []
with open('/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/AdditionalDataS1.tsv') as f:
    r = csv.DictReader(f, delimiter='\t')
    for row in r:
        if row['Combined mutant type'] != 'trigenic': continue
        a, b = genes_of_q(row['Query allele name'])
        c = norm(row['Array allele name'])
        kac = (a, c) if a < c else (c, a)
        kbc = (b, c) if b < c else (c, b)
        if kac not in pair_eps or kbc not in pair_eps: continue
        try:
            w_ab = float(row['Query single/double mutant fitness'])
            w_c = float(row['Array single mutant fitness'])
            w_abc = float(row['Combined mutant fitness'])
        except ValueError: continue
        w_a, w_b = pair_smf.get(a), pair_smf.get(b)
        if not w_a or not w_b or not w_c: continue
        w_ac = w_a * w_c + pair_eps[kac]
        w_bc = w_b * w_c + pair_eps[kbc]
        vals = (w_ac, w_bc, w_abc, w_ab, w_a, w_b, w_c)
        if any(v <= 0 or v != v for v in vals): continue
        tau_sga = w_abc - w_ab*w_c - w_ac*w_b - w_bc*w_a + 2*w_a*w_b*w_c
        def fvec(g):
            smf = pair_smf.get(g, 1.0)
            ess = 1.0 if g in essential else 0.0
            deg = math.log1p(pair_degree.get(g, 0))
            ng = pair_neg.get(g, [0, 0])
            negfrac = ng[0]/ng[1] if ng[1] > 0 else 0.0
            return [smf, ess, deg, negfrac]
        fa, fb, fc = fvec(a), fvec(b), fvec(c)
        base = base_feats(fa, fb, fc)
        # GO признаки пар
        go_feats = []
        n_ok_go = 0
        for (g1, g2) in ((a, b), (a, c), (b, c)):
            s = go_sim(g1, g2)
            if s is None:
                go_feats.extend([0.0, 0.0])
            else:
                go_feats.extend(s); n_ok_go += 1
        triples.append({'qpair': row['Query strain ID'], 'c': c, 'a': a, 'b': b,
                        'tau': tau_sga, 'base': base, 'go': go_feats,
                        'go_ok': n_ok_go == 3})
print(f'троек: {len(triples)}; с полным GO: {sum(t["go_ok"] for t in triples)}')

# --- сплит по query-парам ---
qpairs = sorted(set(t['qpair'] for t in triples))
def hash_split(q): return int(hashlib.md5(q.encode()).hexdigest(), 16) % 1000 < 300
tr = [t for t in triples if not hash_split(t['qpair'])]
ho = [t for t in triples if hash_split(t['qpair'])]
print(f'train {len(tr)} / holdout {len(ho)} (query-пар {len(set(t["qpair"] for t in tr))}/{len(set(t["qpair"] for t in ho))})')

def rmse(y, yp): return float(np.sqrt(np.mean((np.asarray(y) - np.asarray(yp)) ** 2)))
ytr = np.array([t['tau'] for t in tr]); yho = np.array([t['tau'] for t in ho])
med = float(np.median(ytr))
rmse_base = rmse(yho, np.full_like(yho, med))
print(f'\nбазовая линия: RMSE={rmse_base:.4f}')

def fit_eval(Xtr_, Xho_, name):
    m = RidgeCV(alphas=[0.01, 0.1, 1, 10, 100], cv=KFold(5, shuffle=True, random_state=42))
    m.fit(Xtr_, ytr)
    p = m.predict(Xho_)
    r = rmse(yho, p)
    ss = np.sum((yho - yho.mean())**2)
    r2 = 1 - np.sum((yho - p)**2)/ss
    print(f'{name}: RMSE={r:.4f} (прирост {100*(1-r/rmse_base):+.1f}%), R²={r2:+.3f}')
    return r, p

Xtr_b = np.array([t['base'] for t in tr]); Xho_b = np.array([t['base'] for t in ho])
r_b, p_b = fit_eval(Xtr_b, Xho_b, 'базовые признаки (SMF/ess/degree/neg)')

Xtr_g = np.array([t['base'] + t['go'] for t in tr])
Xho_g = np.array([t['base'] + t['go'] for t in ho])
r_g, p_g = fit_eval(Xtr_g, Xho_g, 'базовые + GO Jaccard')

# только GO-полные тройки
tr_f = [t for t in tr if t['go_ok']]; ho_f = [t for t in ho if t['go_ok']]
Xtr_f = np.array([t['base'] + t['go'] for t in tr_f]); Xho_f = np.array([t['base'] + t['go'] for t in ho_f])
ytr_f = np.array([t['tau'] for t in tr_f]); yho_f = np.array([t['tau'] for t in ho_f])
m = RidgeCV(alphas=[0.01, 0.1, 1, 10, 100], cv=KFold(5, shuffle=True, random_state=42))
m.fit(Xtr_f, ytr_f)
p_f = m.predict(Xho_f)
r_f = rmse(yho_f, p_f)
print(f'GO-полные тройки (n={len(ho_f)}): RMSE={r_f:.4f} vs база {rmse_base:.4f}')

# identity (третий ген)
all_genes = sorted(set([t['c'] for t in triples]))
gi = {g: i for i, g in enumerate(all_genes)}
Xtr_id = np.zeros((len(tr), len(all_genes))); Xho_id = np.zeros((len(ho), len(all_genes)))
for i, t in enumerate(tr): Xtr_id[i, gi[t['c']]] = 1.0
for i, t in enumerate(ho): Xho_id[i, gi[t['c']]] = 1.0
r_id, p_id = fit_eval(Xtr_id, Xho_id, 'identity (третий ген)')

# GO-only (без базовых)
Xtr_go = np.array([t['go'] for t in tr]); Xho_go = np.array([t['go'] for t in ho])
fit_eval(Xtr_go, Xho_go, 'GO-признаки alone')

json_out = {'rmse_base_model': rmse_base, 'rmse_basic': r_b, 'rmse_basic_go': r_g,
            'rmse_identity': r_id, 'n_triples': len(triples),
            'n_full_go': sum(t['go_ok'] for t in triples)}
json.dump(json_out, open('/tmp/b0_go.json', 'w'), indent=1)
print('\nсохранено /tmp/b0_go.json')
