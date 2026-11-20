#!/usr/bin/env python3
"""
B6.5-PMA блок B (финал): ΔABC per-метаболит/per-время/ per-реплика.
membership: 1mem_Bt/Cv/Ps, 2mem_BtCv/BtPs/CvPs, 3mem_Full. Времена 25-45ч
(5 точек, t=12.5 есть только в 1mem_Bt — не используем). Реплики 1-4.
nullAdditive авторов: для каждой пары/тройки = среднее mono компонентов
(стандарт аддитивного нуля; в challenge_table определение фиксировано).
Здесь: ΔABC(feat, t, rep) = Full - mean(Bt,Cv,Ps mono) — и для пар аналогично.
Проверки:
 B1. знак/медиана ΔABC по фичам; класс-тест purine vs other на ΔABC
 B2. согласованность знака между репликами (доля реплик с тем же знаком)
 B3. ресурсная нормализация: глюкоза 0.067/0.13/0.2% — при линейной
     зависимости от ресурса ожидание Δ=0; проверим масштаб ΔABC против
     масштаба concentration-full/mono (нормировка на 2x глюкозу)
"""
import csv, math, statistics, random, os, re, json
from collections import defaultdict

BASE = '/home/ninjacat/.bee_swarm/Benchmarks/chodkowski/MSData'
PUR = ['purin','xanthin','uric','hypoxanthin','inosin','adenin','guanin','allantoin',
       'nucleos','adenosin','guanosin','uridin','cytidin','thymidin','orot',
       'methylthioadenosine']
def classify(name):
    n = str(name).lower()
    return 'purine' if any(k in n for k in PUR) else 'other'

def load(fn):
    with open(os.path.join(BASE, fn)) as fh:
        r = csv.reader(fh)
        hdr = next(r)
        data = {}
        for row in r:
            if not row or not row[0]:
                continue
            vals = {}
            for i, s in enumerate(hdr[1:]):
                try:
                    vals[s] = float(row[i + 1])
                except (TypeError, ValueError):
                    vals[s] = None
            data[row[0]] = vals
    return data

pat = re.compile(r'^(?P<mem>[^_]+)_(?P<sp>[^_]+)_(?P<time>[\d.]+)hr-(?P<rep>\d+)$')
def parse(data):
    idx = {}
    for s in list(next(iter(data.values())).keys()):
        m = pat.match(s)
        if m:
            idx[s] = (m['mem'], m['sp'], float(m['time']), int(m['rep']))
    return idx

def med(v):
    v = [x for x in v if x is not None and not math.isnan(x)]
    return statistics.median(v) if v else None

results = {}
for mode, fn in [('PolarPos', 'PolarPosFeatures_readyForMetaboanalyst.csv'),
                 ('PolarNeg', 'PolarNegFeatures_readyForMetaboanalyst.csv'),
                 ('NonPolarPos', 'NonPolarPosFeatures_readyForMetaboanalyst.csv'),
                 ('NonPolarNeg', 'NonPolarNegFeatures_readyForMetaboanalyst.csv')]:
    data = load(fn)
    idx = parse(data)
    if not idx:
        continue
    # mono по (sp, t, rep); full и pairs по (combo, t, rep)
    mono = defaultdict(dict)   # (sp,t,rep) -> {feat}
    combos = defaultdict(dict) # (combo,t,rep) -> {feat}
    feat_cls = {}
    for feat, vals in data.items():
        feat_cls[feat] = classify(feat)
        for s, (mem, sp, t, rep) in idx.items():
            v = vals.get(s)
            if mem == '1mem':
                mono[(sp, t, rep)][feat] = v
            else:
                combos[(mem, sp, t, rep)] = v
    # ΔABC для Full (3mem_Full) и пар
    d_full = defaultdict(list)   # (t, rep) -> list of (feat, delta)
    rep_sign = defaultdict(list) # feat -> список знаков по репликам (t усреднено)
    pur_delta = []
    oth_delta = []
    for (mem, sp, t, rep), v in combos.items():
        pass
    for feat, vals in data.items():
        # для каждой (t,rep) Full против аддитивного среднего mono
        for t in sorted({tt for s, (m_, s_, tt, r_) in idx.items() if m_ == '3mem'}):
            for rep in (1, 2, 3, 4):
                key_full = [s for s, (m_, sp, tt, rr) in idx.items()
                            if m_ == '3mem' and tt == t and rr == rep]
                if not key_full:
                    continue
                fv = vals.get(key_full[0])
                monos = [vals.get(s) for s, (m_, sp, tt, rr) in idx.items()
                         if m_ == '1mem' and tt == t and rr == rep and sp in ('Bt', 'Cv', 'Ps')]
                monos = [x for x in monos if x is not None and not math.isnan(x)]
                if fv is None or len(monos) < 3:
                    continue
                delta = fv - statistics.mean(monos)
                d_full[(t, rep)].append((feat, delta))
                rep_sign[feat].append(math.copysign(1, delta) if delta != 0 else 0)
                if feat_cls[feat] == 'purine':
                    pur_delta.append(delta)
                else:
                    oth_delta.append(delta)
    # B1: медианы и класс-тест
    dp = [x for x in pur_delta if x is not None]
    do = [x for x in oth_delta if x is not None]
    med_p = statistics.median(dp) if dp else None
    med_o = statistics.median(do) if do else None
    # permutation по меткам классов (feat-level): перемешаем классы фич
    all_d = defaultdict(list)
    for (t, rep), lst in d_full.items():
        for feat, delta in lst:
            all_d[feat].append(delta)
    feats = list(all_d.keys())
    med_per_feat = {f: statistics.median([x for x in all_d[f] if x is not None]) for f in feats}
    pur_feats = [f for f in feats if feat_cls[f] == 'purine']
    oth_feats = [f for f in feats if feat_cls[f] == 'other']
    real = statistics.median([med_per_feat[f] for f in pur_feats]) - \
           statistics.median([med_per_feat[f] for f in oth_feats]) if pur_feats and oth_feats else None
    random.seed(2024)
    cnt = 0; N = 2000
    labels = [feat_cls[f] for f in feats]
    for _ in range(N):
        labs = labels[:]
        random.shuffle(labs)
        pf = [f for f, l in zip(feats, labs) if l == 'purine']
        of = [f for f, l in zip(feats, labs) if l == 'other']
        if not pf or not of:
            continue
        s = statistics.median([med_per_feat[f] for f in pf]) - statistics.median([med_per_feat[f] for f in of])
        if abs(s) >= abs(real):
            cnt += 1
    p = cnt / N
    # B2: согласованность знака между репликами
    consistent = 0
    total_f = 0
    for f, signs in rep_sign.items():
        if len(signs) >= 3:
            total_f += 1
            pos = sum(1 for x in signs if x > 0)
            neg = sum(1 for x in signs if x < 0)
            if max(pos, neg) / len(signs) >= 0.75:
                consistent += 1
    results[mode] = {
        'n_pur': len(pur_feats), 'n_oth': len(oth_feats),
        'median_pur_delta': med_p, 'median_oth_delta': med_o,
        'class_diff': real, 'perm_p': p,
        'sign_consistent_feats': consistent, 'total_feats': total_f,
    }
    print(f"=== {mode} ===")
    print(f"  медиана ΔABC: purine={med_p:+.3f} (n={len(pur_feats)}) other={med_o:+.3f}")
    print(f"  класс-тест (per-фича медианы): Δ={real:+.3f} p={p:.4f}")
    print(f"  согласованность знака между репликами (≥75%): {consistent}/{total_f} фич")
json.dump(results, open('/tmp/pma_blockB.json', 'w'), indent=1)
print('\nсохранено /tmp/pma_blockB.json')
