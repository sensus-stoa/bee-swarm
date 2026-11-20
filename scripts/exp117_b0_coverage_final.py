#!/usr/bin/env python3
"""
B0-2 (финал): покрытие нижних порядков для троек Kuzmin 2018.
Пара (a,c)/(b,c) — из Costanzo 2016 SGA_NxN.txt / SGA_ExN.txt / SGA_ExE.txt
(парный формат, allele names). Генерим индекс пар 'ген1|ген2' → есть/нет
(и epsilon). Считаем долю троек с полным покрытием:
  fitness a,b,c; W_ab (S1); W_ac, W_bc (Costanzo).
"""
import csv, sys
from collections import defaultdict

csv.field_size_limit(10**9)

# --- индекс пар Costanzo 2016 (по allele names, без Δ) ---
def norm(a):
    return a.strip().rstrip('Δ')

pair_index = set()
for fn in ['SGA_NxN.txt', 'SGA_ExN.txt', 'SGA_ExE.txt', 'SGA_DAmP.txt']:
    path = f'/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/costanzo_pairwise/Data File S1. Raw genetic interaction datasets: Pair-wise interaction format/{fn}'
    with open(path) as f:
        r = csv.DictReader(f, delimiter='\t')
        for row in r:
            q = norm(row['Query allele name'])
            a = norm(row['Array allele name'])
            pair_index.add((q, a))
    print(fn, '→ индекс пар:', len(pair_index))

# --- S1 тройки: query allele 'aΔ+bΔ' → гены a,b; array 'cΔ' → c ---
def genes_of_q(q):
    return [p.strip().rstrip('Δ') for p in q.split('+')]

trips_total = 0
trips_full = 0
miss_ac = 0
miss_bc = 0
path = '/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/AdditionalDataS1.tsv'
with open(path) as f:
    r = csv.DictReader(f, delimiter='\t')
    for row in r:
        if row['Combined mutant type'] != 'trigenic':
            continue
        trips_total += 1
        a, b = genes_of_q(row['Query allele name'])
        c = norm(row['Array allele name'])
        has_ac = (a, c) in pair_index or (c, a) in pair_index
        has_bc = (b, c) in pair_index or (c, b) in pair_index
        if has_ac and has_bc:
            trips_full += 1
        else:
            if not has_ac: miss_ac += 1
            if not has_bc: miss_bc += 1
print(f'\nтроек: {trips_total}')
print(f'с парой (a,c): {trips_total - miss_ac} ({(trips_total-miss_ac)/trips_total*100:.1f}%)')
print(f'с парой (b,c): {trips_total - miss_bc} ({(trips_total-miss_bc)/trips_total*100:.1f}%)')
print(f'ПОЛНОЕ ПОКРЫТИЕ (a,c)+(b,c): {trips_full} ({trips_full/trips_total*100:.1f}%)')
