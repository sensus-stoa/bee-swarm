#!/usr/bin/env python3
"""
B6.5-PMA, блок B: композиционность ΔABC (Chodkowski).
Проверки: (1) per-метаболит/ per-время разложение третьего порядка;
(2) согласованность между био-репликами; (3) чувствительность к
ресурсной нормализации (глюкоза 0.067/0.13/0.2% в mono/pair/full!).
Данные: MSData CSV (Polar/NonPolar × Pos/Neg, MetaboAnalyst-нормализация).
"""
import csv, math, statistics, random, os, json

BASE = '/home/ninjacat/.bee_swarm/Benchmarks/chodkowski/MSData'
# Условия: BT, CS, PS (mono), BTCS, BTPS, CSPS (pairs), BTCS-P (full)? Уточним по файлам.
fn = [f for f in os.listdir(BASE) if f.endswith('.csv')]
print('файлы:', fn)
# Возьмём Polar Pos readyForMetaboanalyst — самый полный
for f in sorted(fn):
    if 'readyFor' in f and 'PolarPos' in f:
        path = os.path.join(BASE, f)
        break
else:
    path = os.path.join(BASE, sorted(fn)[0])
with open(path) as fh:
    r = csv.reader(fh)
    hdr = next(r)
    print('файл:', os.path.basename(path))
    print('шапка (первые 20):', hdr[:20])
    rows = list(r)
print('фич:', len(rows))
# Имена сэмплов
print('сэмплы:', [h for h in hdr[1:15]])
