#!/usr/bin/env python3
"""
B6.5-PMA блок B (по challenge_table.json — авторский nullAdd уже посчитан).
Проверки:
 B1. ΔABC (delta = full - nullAdd) per-фича/время: класс-тест purine vs other
     по per-фича медианам с permutation по меткам (это повторяет уровень 1,
     но на per-фича медианах, не на пуле всех точек).
 B2. Согласованность знака ΔABC между био-репликами (nFull=4):
     доля purine-фич, у которых ≥75% реплик имеют знак медианы.
 B3. Ресурсная чувствительность: глюкоза mono 0.067%, pair 0.13%, full 0.2%
     — при чисто ресурсном происхождении сигнала Δ должно масштабироваться
     с (resource_full/resource_mono - 1) ≈ +200% для full и +94% для пар.
     Проверяем: медиана Δ(пары, в долях mono) vs медиана Δ(full, в долях mono).
"""
import json, statistics, random, math
from collections import defaultdict

ct = json.load(open('/home/ninjacat/.bee_swarm/Benchmarks/chodkowski/challenge_table.json'))
print('записей:', len(ct))
# Разложим: mode, feat, t, классы, delta. Реплики усреднены автором (nFull=4) —
# per-реплика Δ не восстановить из этой таблицы; проверим per-mode/per-t согласованность
# знака как прокси реплик (t-точки как «псевдореплики» при фиксированном классе).
by_feat = defaultdict(lambda: defaultdict(list))  # feat -> mode -> deltas
for rec in ct:
    if rec.get('delta') is None:
        continue
    by_feat[(rec['mode'], rec['feat'])][rec['t']].append(rec)

# per-фича медиана delta (по всем t)
med_per_feat = {}
cls_per_feat = {}
for key, recs in by_feat.items():
    ds = [r['delta'] for rs in recs.values() for r in rs if r['delta'] is not None]
    if ds:
        med_per_feat[key] = statistics.median(ds)
        cls_per_feat[key] = recs[next(iter(recs))][0]['class'] if isinstance(recs[next(iter(recs))][0], dict) else None
# класс достаём из rec
for key, recs in by_feat.items():
    first = next(iter(recs.values()))[0]
    cls_per_feat[key] = first.get('class')

feats = list(med_per_feat.keys())
pur = [f for f in feats if cls_per_feat[f] == 'purine']
oth = [f for f in feats if cls_per_feat[f] == 'other']
print(f'фич: {len(feats)} (purine {len(pur)}, other {len(oth)})')

med_pur = statistics.median([med_per_feat[f] for f in pur])
med_oth = statistics.median([med_per_feat[f] for f in oth])
real = med_pur - med_oth
random.seed(2024)
labels = [cls_per_feat[f] for f in feats]
cnt = 0; N = 5000
for _ in range(N):
    labs = labels[:]
    random.shuffle(labs)
    pf = [f for f, l in zip(feats, labs) if l == 'purine']
    of = [f for f, l in zip(feats, labs) if l == 'other']
    s = statistics.median([med_per_feat[f] for f in pf]) - statistics.median([med_per_feat[f] for f in of])
    if abs(s) >= abs(real):
        cnt += 1
print(f'\nB1 класс-тест: медиана purine={med_pur:+.2f} other={med_oth:+.2f} Δ={real:+.2f} p={cnt/N:.4f}')

# B2: согласованность знака по временным точкам (proxy реплик)
cons = 0; tot = 0
for key, recs in by_feat.items():
    if cls_per_feat[key] != 'purine':
        continue
    signs = [math.copysign(1, statistics.median([r['delta'] for r in rs if r['delta'] is not None]))
             for rs in recs.values()
             if any(r['delta'] is not None for r in rs)]
    if len(signs) >= 4:
        tot += 1
        pos = sum(1 for s in signs if s > 0)
        neg = sum(1 for s in signs if s < 0)
        if max(pos, neg) / len(signs) >= 0.75:
            cons += 1
print(f'B2 purine-фич со знаком, согласованным в ≥75% временных точек: {cons}/{tot}')

# B3: ресурсная чувствительность — относительный Δ (в долях mono-среднего)
rel_full = []
rel_pair = []
for rec in ct:
    mono_vals = rec.get('mono', {})
    if not mono_vals or rec.get('full') is None:
        continue
    m = statistics.mean([v for v in mono_vals.values() if v is not None])
    if m and m != 0:
        rel_full.append((rec['full'] - m) / abs(m))
    if rec.get('pair'):
        # средняя пара
        pv = [v for v in rec['pair'].values() if v is not None]
        if pv:
            rel_pair.append((statistics.mean(pv) - m) / abs(m))
mf = statistics.median(rel_full); mp = statistics.median(rel_pair)
print(f'\nB3 относительный Δ: full медиана={mf:+.3f} (ожидание при чисто-ресурсном +2.0) '
      f'| pair медиана={mp:+.3f} (ожидание +0.94)')
print(f'   full/ре-ресурсное отношение: {mf/2.0:+.3f} (если <<1 — сигнал не чисто ресурсный)')

