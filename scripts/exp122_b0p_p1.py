#!/usr/bin/env python3
"""
B0-P этап P1: библиотека паттернов из профилей парных взаимодействий.
Профиль гена = вектор ε против всех партнёров (из Costanzo 2016,
pair-wise). Кластеризация — KMeans по косинусной близости, только на
train-генах. Паттерн-метки присваиваются holdout-генам по ближайшему
центроиду (это честно: центроиды не видели holdout-композиций).
"""
import csv, math, json, hashlib, statistics, sys
from collections import defaultdict, Counter
import numpy as np

csv.field_size_limit(10**9)
def norm(a): return a.strip().rstrip('Δ')

# --- профили: gene → {partner: eps} ---
profiles = defaultdict(dict)
for fn in ['SGA_NxN.txt', 'SGA_ExN.txt', 'SGA_ExE.txt', 'SGA_DAmP.txt']:
    path = f'/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/costanzo_pairwise/Data File S1. Raw genetic interaction datasets: Pair-wise interaction format/{fn}'
    with open(path) as f:
        r = csv.DictReader(f, delimiter='\t')
        for row in r:
            q = norm(row['Query allele name']); a = norm(row['Array allele name'])
            try: eps = float(row['Genetic interaction score (ε)'])
            except ValueError: continue
            profiles[q][a] = eps
            profiles[a][q] = eps
print(f'профилей: {len(profiles)}')

# --- тройки S1 + τ_SGA (пересчёт) ---
pair_eps_all = {}
pair_smf = {}
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
essential = set()
for fn in ['SGA_ExE.txt', 'SGA_DAmP.txt']:
    path = f'/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/costanzo_pairwise/Data File S1. Raw genetic interaction datasets: Pair-wise interaction format/{fn}'
    with open(path) as f:
        r = csv.DictReader(f, delimiter='\t')
        for row in r:
            essential.add(norm(row['Query allele name']))
            essential.add(norm(row['Array allele name']))

def genes_of_q(q):
    return [p.strip().rstrip('Δ') for p in q.split('+')]

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
        tau = w_abc - w_ab*w_c - w_ac*w_b - w_bc*w_a + 2*w_a*w_b*w_c
        triples.append({'qpair': row['Query strain ID'], 'a': a, 'b': b, 'c': c,
                        'tau': tau})
print(f'троек: {len(triples)}')

# --- сплит по query-парам (замороженный hash) ---
qpairs = sorted(set(t['qpair'] for t in triples))
def hs(q): return int(hashlib.md5(q.encode()).hexdigest(), 16) % 1000 < 300
train_pairs = {q for q in qpairs if not hs(q)}
test_pairs = {q for q in qpairs if hs(q)}
# гены train-композиций (для обучения паттернов) vs holdout-only гены
train_genes = set()
for t in triples:
    if t['qpair'] in train_pairs:
        train_genes.update([t['a'], t['b'], t['c']])
print(f'train-пары {len(train_pairs)}, holdout-пары {len(test_pairs)}; train-генов {len(train_genes)}')

# --- матрица профилей: train-гены (строки) × все партнёры (общий словарь) ---
vocab = Counter()
for g in train_genes:
    vocab.update(profiles.get(g, {}).keys())
vocab = sorted(vocab.keys())
vidx = {v: i for i, v in enumerate(vocab)}
def prof_vec(g):
    d = profiles.get(g, {})
    v = np.zeros(len(vocab))
    for k, e in d.items():
        if k in vidx: v[vidx[k]] = e
    return v

from sklearn.preprocessing import normalize
X_train_genes = np.array([prof_vec(g) for g in sorted(train_genes)])
X_train_genes = normalize(X_train_genes)  # косинусная геометрия
gene_list = sorted(train_genes)
print(f'матрица профилей train-генов: {X_train_genes.shape}')

# --- P1: кластеризация (KMeans, k по сетке) ---
from sklearn.cluster import KMeans
from sklearn.metrics import silhouette_score
best = None
for k in [12, 24, 40, 60]:
    km = KMeans(n_clusters=k, random_state=42, n_init=5)
    lab = km.fit_predict(X_train_genes)
    s = silhouette_score(X_train_genes, lab, sample_size=3000, random_state=42)
    sizes = Counter(lab)
    print(f'k={k}: silhouette={s:.3f}, размеры кластеров min/med/max = '
          f'{min(sizes.values())}/{statistics.median(sizes.values()):.0f}/{max(sizes.values())}')
    if best is None or s > best[0]:
        best = (s, k, km)
sil, k_best, km = best
print(f'\nвыбран k={k_best} (silhouette {sil:.3f})')

# центроиды → паттерн-метки для ВСЕХ генов троек (по ближайшему центроиду)
centroids = km.cluster_centers_
def pattern_label(g):
    v = normalize(prof_vec(g).reshape(1, -1))[0]
    sims = centroids @ v
    return int(np.argmax(sims)), float(sims.max())

# метки train-генов — из кластеризации (точные), для остальных — центроид
label_of = {}
for g, lab in zip(gene_list, km.labels_):
    label_of[g] = int(lab)
json.dump({'k_best': k_best, 'silhouette': sil}, open('/tmp/b0p_p1.json', 'w'))
print('P1: библиотека паттернов построена; примеры размеров:',
      dict(list(Counter(label_of.values()).items())[:6]))
