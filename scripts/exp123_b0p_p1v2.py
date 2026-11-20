#!/usr/bin/env python3
"""
B0-P P1 (v2): паттерны из авторской матрицы сходства профилей
(Costanzo 2016 Data File S3, Global_Similarity_Network.txt, 5616 генов).
Строим граф топ-K сходств (k=15), кластеризуем (Louvain через networkx
или connected components на пороге). Только train-гены для кластеризации;
holdout-гены получают метку ближайшего соседа из train-графа.
"""
import csv, math, json, hashlib, statistics, sys
from collections import defaultdict, Counter
import numpy as np
import networkx as nx

csv.field_size_limit(10**9)
def norm(a): return a.strip().rstrip('Δ')

# --- S1 тройки + τ_SGA (как в exp122) ---
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
print(f'троек: {len(triples)}')

qpairs = sorted(set(t['qpair'] for t in triples))
def hs(q): return int(hashlib.md5(q.encode()).hexdigest(), 16) % 1000 < 300
train_pairs = {q for q in qpairs if not hs(q)}
train_genes = set()
for t in triples:
    if t['qpair'] in train_pairs:
        train_genes.update([t['a'], t['b'], t['c']])
print(f'train-генов: {len(train_genes)}')

# --- граф сходства (авторский): все гены, топ-15 соседей с corr>0.2 ---
G = nx.Graph()
sim_path = '/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/costanzo_sim/Data File S3. Genetic interaction profile similarity matrices/Global_Similarity_Network.txt'
with open(sim_path) as f:
    rdr = csv.reader(f, delimiter='\t')
    header = next(rdr)          # gene names (строка 1: символы)
    orfs = next(rdr)            # ORF (строка 2)
    genes = header[1:]          # col genes
    gorf = orfs[1:]
    assert len(genes) == len(gorf)
    for row in rdr:
        g = row[0]
        vals = row[1:]
        if len(vals) < len(genes): continue
        # топ-15 соседей с corr >= 0.2
        cand = []
        for i, v in enumerate(vals):
            try: fv = float(v)
            except ValueError: continue
            if math.isnan(fv): continue
            if fv >= 0.2:
                cand.append((fv, genes[i]))
        cand.sort(reverse=True)
        for fv, gn in cand[:15]:
            if gn == g: continue
            G.add_edge(g, gn, weight=fv)
print(f'граф сходства: {G.number_of_nodes()} узлов, {G.number_of_edges()} рёбер')

# --- кластеризация: Louvain ---
comms = nx.community.louvain_communities(G, seed=42, resolution=1.0)
comms = sorted([sorted(c) for c in comms if len(c) >= 5], key=len, reverse=True)
print(f'кластеров (≥5 генов): {len(comms)}, размеры топ-10: {[len(c) for c in comms[:10]]}')
node2comm = {}
for ci, c in enumerate(comms):
    for g in c:
        node2comm[g] = ci
json.dump({'n_clusters': len(comms),
           'sizes': [len(c) for c in comms[:20]],
           'example_top1': comms[0][:20]}, open('/tmp/b0p_p1v2.json', 'w'))
print('сохранено /tmp/b0p_p1v2.json')

# сохраняем метки для P2/P3
np.save('/tmp/b0p_triples.npy', np.array(triples, dtype=object), allow_pickle=True)
json.dump({'labels': node2comm}, open('/tmp/b0p_labels.json', 'w'))
print('метки:', len(node2comm), 'генов')
