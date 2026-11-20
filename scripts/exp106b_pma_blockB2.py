#!/usr/bin/env python3
"""
B6.5-PMA, блок B (продолжение): композиционность ΔABC.
Сэмплы именованы {membership}_{species}_{time}-{rep} либо парные/тройные
композиции. Разбираем имена, строим ΔABC per-метаболит/per-время:
ΔABC(t, feat) = Full_ABC(t) - (nullAdditive(t)), где nullAdditive — авторский.
Авторский null: (mono BT + mono CS + mono PS)/3? Или суммарный? В challenge_table
уже есть определение. Здесь: проверяем ЗНАК согласованности ΔABC между
репликами (блок B.2 owner) и ресурсную чувствительность (глюкоза
0.067/0.13/0.2% в mono/pair/full — авторский контроль плотности).
"""
import csv, math, statistics, random, os, re, json
from collections import defaultdict

BASE = '/home/ninjacat/.bee_swarm/Benchmarks/chodkowski/MSData'

def load(fn):
    with open(os.path.join(BASE, fn)) as fh:
        r = csv.reader(fh)
        hdr = next(r)
        data = {}
        for row in r:
            if not row or not row[0]:
                continue
            feat = row[0]
            vals = {}
            for i, s in enumerate(hdr[1:]):
                try:
                    vals[s] = float(row[i + 1])
                except (TypeError, ValueError):
                    vals[s] = None
            data[feat] = vals
    return data

data = load('PolarPosFeatures_readyForMetaboanalyst.csv')
samples = list(next(iter(data.values())).keys())
# разбор имён: membership; species; time; rep
pat = re.compile(r'^(?P<mem>[^_]+)_(?P<sp>[^_]+)_(?P<time>[\d.]+)hr-(?P<rep>\d+)$')
parsed = {}
for s in samples:
    m = pat.match(s)
    if m:
        parsed[s] = (m['mem'], m['sp'], float(m['time']), int(m['rep']))
    else:
        # пары/тройки: '3mem_BtCs_25hr-1' и т.п.
        m2 = re.match(r'^(?P<mem>\dmem)_(?P<sp>[^_]+)_(?P<time>[\d.]+)hr-(?P<rep>\d+)$', s)
        if m2:
            parsed[s] = (m2['sp'], 'COMBO', float(m2['time']), int(m2['rep']))
mems = defaultdict(set)
times = defaultdict(set)
for s, (a, b, t, rep) in parsed.items():
    times[t].add(s)
    mems[a if b == 'COMBO' else f'{a}_{b}'].add(s)
print('membership-группы:', {k: len(v) for k, v in sorted(mems.items())})
print('времена:', sorted(times.keys()))

