#!/usr/bin/env python3
"""
B0-P уровень 2: паттерны ПАР (query-пары по их τ-профилям против c).
Корреляции τ-профилей между query-парами → организуются ли пары в паттерны.
"""
import csv, math, json, statistics
from collections import defaultdict, Counter
import numpy as np

csv.field_size_limit(10**9)
def norm(a): return a.strip().rstrip('Δ')

pair_eps = {}; pair_smf = {}
for fn in ['SGA_NxN.txt', 'SGA_ExN.txt', 'SGA_ExE.txt', 'SGA_DAmP.txt']:
    path = f'/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/costanzo_pairwise/Data File S1. Raw genetic interaction datasets: Pair-wise interaction format/{fn}'
    with open(path) as f:
        r = csv.DictReader(f, delimiter='\t')
        for row in r:
            q = norm(row['Query allele name']); a = norm(row['Array allele name'])
            key = (q, a) if q < a else (a, q)
            try: pair_eps[key] = float(row['Genetic interaction score (ε)'])
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
        except ValueError:
            continue
        w_a, w_b = pair_smf.get(a), pair_smf.get(b)
        if not w_a or not w_b or not w_c:
            continue
        w_ac = w_a * w_c + pair_eps[kac]
        w_bc = w_b * w_c + pair_eps[kbc]
        vals = (w_ac, w_bc, w_abc, w_ab, w_a, w_b, w_c)
        if any(v <= 0 or v != v for v in vals):
            continue
        tau = w_abc - w_ab * w_c - w_ac * w_b - w_bc * w_a + 2 * w_a * w_b * w_c
        triples.append({'qpair': row['Query strain ID'], 'c': c, 'tau': tau})

by_pair = defaultdict(dict)
for t in triples:
    by_pair[t['qpair']][t['c']] = t['tau']
pair_ids = sorted(by_pair.keys())
print('query-пар:', len(pair_ids))

# матрица корреляций τ-профилей между парами (перекрытие ≥50 c)
n = len(pair_ids)
M = np.full((n, n), np.nan)
for i in range(n):
    si = set(by_pair[pair_ids[i]])
    for j in range(i + 1, n):
        common = si & set(by_pair[pair_ids[j]])
        if len(common) < 50:
            continue
        cl = sorted(common)
        v1 = np.array([by_pair[pair_ids[i]][c] for c in cl])
        v2 = np.array([by_pair[pair_ids[j]][c] for c in cl])
        if v1.std() == 0 or v2.std() == 0:
            continue
        M[i, j] = M[j, i] = np.corrcoef(v1, v2)[0, 1]

iu = np.triu_indices(n, 1)
vals = M[iu]
ok = ~np.isnan(vals)
print(f'пар с перекрытием ≥50 c: {ok.sum()} из {ok.size}')
v = vals[ok]
print(f'корреляции τ-профилей: медиана {np.median(v):+.3f}, '
      f'IQR [{np.percentile(v,25):+.3f}, {np.percentile(v,75):+.3f}]')
hist, edges = np.histogram(v, bins=10)
for h, e in zip(hist, edges):
    print(f'  {e:+.2f}: {"#" * int(h / 6)} {h}')
print(f'доля пар с corr>0.3: {(v > 0.3).mean() * 100:.1f}%')
print(f'доля пар с corr>0.5: {(v > 0.5).mean() * 100:.1f}%')

json.dump({'n_pairs': n, 'n_compared': int(ok.sum()),
           'median_corr': float(np.median(v)),
           'frac_gt03': float((v > 0.3).mean()),
           'frac_gt05': float((v > 0.5).mean())},
          open('/tmp/b0p_level2.json', 'w'))
print('сохранено /tmp/b0p_level2.json')
