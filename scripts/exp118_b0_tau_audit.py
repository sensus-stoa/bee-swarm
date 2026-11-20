#!/usr/bin/env python3
"""
B0-3: аудит τ-шкалы — регенерация тройного остатка из fitness.
Модели:
  мультипликативная: W_exp = W_ab·W_ac·W_bc / (W_a·W_b·W_c);
    tau_mult = W_abc − W_exp
  лог (аддитивная в log-пространстве):
    tau_log = ln W_abc − (ln W_ab + ln W_ac + ln W_bc − ln W_a − ln W_b − ln W_c)
  авторская τ из S1 (Adjusted genetic interaction score).
Сравнение на тройках с полным покрытием: корреляция, разброс, доля знака.
W_a, W_b — из S4 (fitness стандарт query singles? Нет — S4 = query пары).
W_a/W_b одиночных query генов: в Costanzo матрицах SMF колонки пар (a,c)/(b,c)?
В парном формате есть Query SMF и Array SMF. Для тройки a,b,c:
  W_a = SMF a (из Costanzo строк (a,c) или (b,c)); W_b аналогично; W_c = Array SMF.
Собираем из парных файлов во время прохода.
"""
import csv, math, statistics, sys
from collections import defaultdict

csv.field_size_limit(10**9)

def norm(a): return a.strip().rstrip('Δ')

# --- 1. пары из Costanzo: (gene_pair) → epsilon, SMF query, SMF array ---
pair_eps = {}
pair_smf = {}   # gene → SMF (собираем с обеих сторон)
for fn in ['SGA_NxN.txt', 'SGA_ExN.txt', 'SGA_ExE.txt', 'SGA_DAmP.txt']:
    path = f'/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/costanzo_pairwise/Data File S1. Raw genetic interaction datasets: Pair-wise interaction format/{fn}'
    with open(path) as f:
        r = csv.DictReader(f, delimiter='\t')
        for row in r:
            q = norm(row['Query allele name']); a = norm(row['Array allele name'])
            key = (q, a) if q < a else (a, q)
            try:
                eps = float(row['Genetic interaction score (ε)'])
            except (ValueError, KeyError):
                continue
            pair_eps[key] = eps
            for g, smfk in ((q, 'Query single mutant fitness (SMF)'), (a, 'Array SMF')):
                try:
                    v = float(row[smfk])
                    if g not in pair_smf:
                        pair_smf[g] = v
                except ValueError:
                    pass
print(f'пары: {len(pair_eps)}, генов с SMF: {len(pair_smf)}')

# --- 2. тройки из S1: W_ab (query fitness), W_c (array fitness), W_abc, tau_author ---
def genes_of_q(q):
    return [p.strip().rstrip('Δ') for p in q.split('+')]

path = '/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/AdditionalDataS1.tsv'
recs = []
with open(path) as f:
    r = csv.DictReader(f, delimiter='\t')
    for row in r:
        if row['Combined mutant type'] != 'trigenic':
            continue
        a, b = genes_of_q(row['Query allele name'])
        c = norm(row['Array allele name'])
        key_ac = (a, c) if a < c else (c, a)
        key_bc = (b, c) if b < c else (c, b)
        if key_ac not in pair_eps or key_bc not in pair_eps:
            continue
        try:
            w_ab = float(row['Query single/double mutant fitness'])
            w_c = float(row['Array single mutant fitness'])
            w_abc = float(row['Combined mutant fitness'])
            tau_auth = float(row['Adjusted genetic interaction score (epsilon or tau)'])
        except ValueError:
            continue
        w_a = pair_smf.get(a); w_b = pair_smf.get(b)
        if not w_a or not w_b or not w_c or w_c <= 0:
            continue
        w_ac = pair_eps.get(key_ac); w_bc = pair_eps.get(key_bc)
        # W_ac из epsilon: W_ac ≈ W_a·W_c + eps_ac (мультипликативная база Costanzo)
        w_ac_fit = w_a * w_c + w_ac
        w_bc_fit = w_b * w_c + w_bc
        vals = (w_ac_fit, w_bc_fit, w_abc, w_ab, w_a, w_b, w_c)
        if any(v <= 0 or v != v for v in vals):
            continue
        # мультипликативный expected
        w_exp_mult = w_ab * w_ac_fit * w_bc_fit / (w_a * w_b * w_c)
        tau_mult = w_abc - w_exp_mult
        # лог-масштаб
        tau_log = (math.log(w_abc) - math.log(w_ab) - math.log(w_ac_fit)
                   - math.log(w_bc_fit) + math.log(w_a) + math.log(w_b) + math.log(w_c))
        recs.append({'tau_auth': tau_auth, 'tau_mult': tau_mult, 'tau_log': tau_log,
                     'w_abc': w_abc, 'w_exp': w_exp_mult})

n = len(recs)
print(f'троек с полным покрытием и fitness: {n}')
def corr(xs, ys):
    mx, my = statistics.mean(xs), statistics.mean(ys)
    num = sum((x-mx)*(y-my) for x, y in zip(xs, ys))
    dx = math.sqrt(sum((x-mx)**2 for x in xs)); dy = math.sqrt(sum((y-my)**2 for y in ys))
    return num/(dx*dy) if dx > 0 and dy > 0 else float('nan')
ta = [r['tau_auth'] for r in recs]
tm = [r['tau_mult'] for r in recs]
tl = [r['tau_log'] for r in recs]
print(f'корреляция τ_auth ~ τ_mult: {corr(ta, tm):+.3f}')
print(f'корреляция τ_auth ~ τ_log:  {corr(ta, tl):+.3f}')
print(f'корреляция τ_mult ~ τ_log:  {corr(tm, tl):+.3f}')
print(f'τ_auth: медиана {statistics.median(ta):+.4f} | τ_mult: {statistics.median(tm):+.4f} | τ_log: {statistics.median(tl):+.4f}')
# доля совпадения знака (для отрицательных значений — доменный класс)
sign_agree = sum(1 for x, y in zip(ta, tm) if (x < 0) == (y < 0))
print(f'знак τ_auth=τ_mult (оба <0): {sign_agree/n*100:.1f}%')
# масштабы
print(f'разброс: τ_auth std={statistics.pstdev(ta):.4f} τ_mult std={statistics.pstdev(tm):.4f} τ_log std={statistics.pstdev(tl):.4f}')
