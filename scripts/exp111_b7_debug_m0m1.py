#!/usr/bin/env python3
"""
B7 этап 1 (отладка): M0/M1 механика на Chodkowski.
M0(combo) = mean(mono) (равные доли); M1(combo) = M0 + Σ парных остатков
r_ij = pair_obs(i,j) - M0(pair(i,j)). Проверяем на 55 named purine + all
other фичах challenge_table: распределение остатков M0 vs M1 на тройке.
Ожидание: M1-остаток тройки должен быть меньше |M0-остатка| если пары
несут композиционную информацию (owner: ΔABC = evidence неаддитивности,
значит M1 (аддитивный с парами) всё ещё имеет остаток — и его и предсказывает
M2/M3).
"""
import json, statistics, math
from collections import defaultdict

ct = json.load(open('/home/ninjacat/.bee_swarm/Benchmarks/chodkowski/challenge_table.json'))
# записи: mode, feat, class, ident, t, mono{Bt,Cv,Ps}, pair{BtCv,BtPs,CvPs}, full, nullAdd, delta
# M0/M1 считаем по t-точкам, медианы по времени per фичу.

by_feat = defaultdict(list)
for rec in ct:
    if rec.get('mono') and rec.get('full') is not None:
        by_feat[rec['feat']].append(rec)

rows = []
for feat, recs in by_feat.items():
    cls = recs[0].get('class')
    ident = recs[0].get('ident', '')
    d0_list, d1_list = [], []
    for rec in recs:
        monos = [v for v in rec['mono'].values() if v is not None]
        if len(monos) < 3 or rec.get('full') is None:
            continue
        m0 = statistics.mean(monos)
        d0 = rec['full'] - m0
        # парные остатки (в тех же условных единицах)
        pair_resids = []
        pr_map = {'BtCv': ('Bt', 'Cv'), 'BtPs': ('Bt', 'Ps'), 'CvPs': ('Cv', 'Ps')}
        for pk, (a, b) in pr_map.items():
            pv = rec['pair'].get(pk)
            if pv is None:
                continue
            m0_pair = statistics.mean([rec['mono'].get(a), rec['mono'].get(b)])
            if m0_pair is not None:
                pair_resids.append(pv - m0_pair)
        m1 = m0 + (statistics.mean(pair_resids) if pair_resids else 0)
        d1 = rec['full'] - m1
        d0_list.append(d0)
        d1_list.append(d1)
    if not d0_list:
        continue
    rows.append({'feat': feat, 'cls': cls, 'ident': ident,
                 'med_d0': statistics.median(d0_list),
                 'med_d1': statistics.median(d1_list)})

pur = [r for r in rows if r['cls'] == 'purine']
oth = [r for r in rows if r['cls'] == 'other']
print(f'фич: {len(rows)} (purine {len(pur)}, other {len(oth)})')

med0_all = statistics.median([abs(r['med_d0']) for r in oth])
med1_all = statistics.median([abs(r['med_d1']) for r in oth])
print(f'other: медиана |M0-остаток| = {med0_all:.2f} → |M1-остаток| = {med1_all:.2f} '
      f'(пары объясняют {100*(1-med1_all/med0_all):.0f}%)')

# то же для purine
med0_p = statistics.median([abs(r['med_d0']) for r in pur])
med1_p = statistics.median([abs(r['med_d1']) for r in pur])
print(f'purine: |M0| = {med0_p:.2f} → |M1| = {med1_p:.2f} (объясняют {100*(1-med1_p/med0_p):.0f}%)')

# класс-тест остатка M1: purine vs other (медианы per-фича, permutation)
real = statistics.median([r['med_d1'] for r in pur]) - statistics.median([r['med_d1'] for r in oth])
import random
random.seed(2024)
labels = [r['cls'] for r in rows]
vals = [r['med_d1'] for r in rows]
cnt = 0; N = 5000
for _ in range(N):
    labs = labels[:]
    random.shuffle(labs)
    pf = [v for v, l in zip(vals, labs) if l == 'purine']
    of = [v for v, l in zip(vals, labs) if l == 'other']
    s = statistics.median(pf) - statistics.median(of)
    if abs(s) >= abs(real):
        cnt += 1
print(f'\nкласс-тест M1-остатка: медиана purine={statistics.median([r["med_d1"] for r in pur]):+.2f} '
      f'other={statistics.median([r["med_d1"] for r in oth]):+.2f} Δ={real:+.2f} p={cnt/N:.4f}')

# доля purine-фич, где M1-остаток меньше M0-остатка
better = sum(1 for r in pur if abs(r['med_d1']) < abs(r['med_d0']))
print(f'M1 лучше M0 у purine-фич: {better}/{len(pur)}')

json.dump({'rows': rows}, open('/tmp/b7_debug_chodkowski.json', 'w'), default=str)
print('\nсохранено /tmp/b7_debug_chodkowski.json')
