#!/usr/bin/env python3
"""
MTBLS12572: перенос пуриновой оси (уровень 2, третья система).

Дизайн: 6 видов rhizosphere (C.cellulans E, B.velezensis SQR9, P.stutzeri G,
P.fluorescens J, A.brasilense K, B.megaterium L), exometabolome.
Сэмплы: mono x6 (x2 реплики), SynCom4 (без B.megaterium? состав из имён:
SynCom4 + dropout C.cellulans => SynCom4 = 4 вида), SynCom5 (+dropout
P.stutzeri => SynCom5 = 5 видов). Все фичи named.

Тесты (замороженная ось: пуриновые фич систематически отклоняются от
аддитивного прогноза в композиции; класс = purine vs other):
 T1. Δ(mono-pool): SynCom фича - max mono / аддитивная сумма mono — знак.
 T2. dropout-контраст: Δ_dropout = SynCom - dropout (вклад C.cellulans /
     P.stutzeri в композицию) — класс-тест по |Δ| и доле Δ>0.
 T3. Permutation по меткам классов (фича-уровень, 5000x) для purine vs other.
"""
import csv, re, random, statistics, math, json, os

BASE = '/home/ninjacat/.bee_swarm/Benchmarks/mtbls12572'
PUR = ['purin', 'xanthin', 'uric', 'hypoxanthin', 'inosin', 'adenin', 'guanin',
       'allantoin', 'nucleos', 'adenosin', 'guanosin', 'uridin', 'cytidin',
       'thymidin', 'orot', 'methylthioadenosine']

def classify(name):
    n = name.lower()
    if any(k in n for k in PUR):
        return 'purine'
    return 'other'

def load_maf(fn):
    with open(fn) as f:
        r = csv.reader(f, delimiter='\t')
        hdr = next(r)
        i_ab = hdr.index('smallmolecule_abundance_sub')
        j_id = hdr.index('metabolite_identification') if 'metabolite_identification' in hdr else 4
        j_mz = hdr.index('mass_to_charge') if 'mass_to_charge' in hdr else 5
        samples = hdr[i_ab + 3:]
        feats = []
        for row in r:
            if len(row) <= max(j_id, j_mz):
                continue
            vals = {}
            for k, s in enumerate(samples):
                v = row[i_ab + 3 + k] if i_ab + 3 + k < len(row) else None
                try:
                    vals[s] = float(v)
                except (TypeError, ValueError):
                    vals[s] = None
            feats.append({'name': row[j_id], 'mz': row[j_mz] if j_mz < len(row) else '',
                          'cls': classify(row[j_id]), 'vals': vals})
    return samples, feats

def med(v):
    v = [x for x in v if x is not None]
    return statistics.median(v) if v else None

def class_perm_test(d_pur, d_oth, stat='median_abs', n_perm=5000, seed=2024):
    real = statistics.median([abs(x) for x in d_pur]) - statistics.median([abs(x) for x in d_oth])
    allv = d_pur + d_oth
    n_p = len(d_pur)
    cnt = 0
    random.seed(seed)
    for _ in range(n_perm):
        random.shuffle(allv)
        p = allv[:n_p]; o = allv[n_p:]
        s = statistics.median([abs(x) for x in p]) - statistics.median([abs(x) for x in o])
        if abs(s) >= abs(real):
            cnt += 1
    return real, cnt / n_perm

results = {}
for mode in ['neg', 'pos']:
    fn = [f for f in os.listdir(BASE) if mode in f and f.startswith('m_')][0]
    samples, feats = load_maf(os.path.join(BASE, fn))
    print(f'=== {mode.upper()}: {len(feats)} фич, {len(samples)} сэмплов ===')
    n_pur = sum(1 for f in feats if f['cls'] == 'purine')
    print(f'пуриновых: {n_pur}')

    def mv(feat, sample):
        return feat['vals'].get(sample)

    # --- T1: SynCom4 vs mono (аддитивный null: среднее mono членов SynCom4) ---
    # SynCom4 = SQR9 + J + K + L (без E, E — dropout) — из имён дропаутов
    SC4 = ['B.velezensisSQR9', 'P.fluorescensJ', 'A.brasilenseK', 'B.megateriumL']
    SC5 = ['C.cellulansE', 'B.velezensisSQR9', 'P.stutzeriG', 'P.fluorescensJ', 'A.brasilenseK']
    def mean_mono(feat, members):
        vs = [mv(feat, f'{m}_repeat{r}') for m in members for r in (1, 2)]
        vs = [v for v in vs if v is not None]
        return sum(vs) / len(vs) if vs else None
    def syncom_mean(feat, name):
        vs = [mv(feat, f'{name}_repeat{r}') for r in (1, 2)]
        vs = [v for v in vs if v is not None]
        return sum(vs) / len(vs) if vs else None

    # T2: dropout-контраст (внутри SynCom4: с E и без E)
    d_drop = []  # (cls, delta)
    for f in feats:
        sc4 = syncom_mean(f, 'SynCom4')
        dr = syncom_mean(f, 'SynCom4_C.cellulansEdroupout')
        if sc4 is None or dr is None or sc4 == 0:
            continue
        # log2ratio: SynCom (с E) vs dropout (без E) — вклад E в удержание фичи
        if sc4 > 0 and dr > 0:
            d = math.log2(sc4 / dr)
            d_drop.append((f['cls'], d, f['name']))
    print(f'T2 dropout-пар фич: {len(d_drop)}')
    dp = [x[1] for x in d_drop if x[0] == 'purine']
    do = [x[1] for x in d_drop if x[0] == 'other']
    if dp and do:
        print(f'  purine: n={len(dp)} медиана log2={statistics.median(dp):+.3f} доля>0={sum(1 for x in dp if x>0)/len(dp):.2f}')
        print(f'  other : n={len(do)} медиана log2={statistics.median(do):+.3f} доля>0={sum(1 for x in do if x>0)/len(do):.2f}')
        real, p = class_perm_test(dp, do)
        print(f'  класс-тест |log2| purine-other: Δmedian|={real:+.4f} p={p:.4f}')
    # топ пуриновых по |log2|
    top = sorted([x for x in d_drop if x[0] == 'purine'], key=lambda x: -abs(x[1]))[:10]
    print('  топ-10 пуриновых (|log2 SynCom4/dropout|):')
    for cls, d, name in top:
        print(f'    {name[:60]:60.60} {d:+.2f}')

    # T1b: SynCom5 vs dropout P.stutzeri
    d_drop5 = []
    for f in feats:
        sc5 = syncom_mean(f, 'SynCom5')
        dr = syncom_mean(f, 'SynCom5_P.stutzeriGdropout')
        if sc5 is None or dr is None or sc5 == 0 or dr == 0:
            continue
        d_drop5.append((f['cls'], math.log2(sc5 / dr), f['name']))
    print(f'T2b SynCom5 dropout-пар: {len(d_drop5)}')
    dp5 = [x[1] for x in d_drop5 if x[0] == 'purine']
    do5 = [x[1] for x in d_drop5 if x[0] == 'other']
    if dp5 and do5:
        print(f'  purine: n={len(dp5)} медиана={statistics.median(dp5):+.3f} доля>0={sum(1 for x in dp5 if x>0)/len(dp5):.2f}')
        print(f'  other : n={len(do5)} медиана={statistics.median(do5):+.3f} доля>0={sum(1 for x in do5 if x>0)/len(do5):.2f}')
        real5, p5 = class_perm_test(dp5, do5)
        print(f'  класс-тест |log2| purine-other: Δmedian|={real5:+.4f} p={p5:.4f}')

    results[mode] = {'n_pur': n_pur, 'drop4': len(d_drop), 'drop5': len(d_drop5)}

# --- T1: аддитивный null на mono (SynCom4 vs среднее mono 4 членов) ---
print()
print('=== T1: SynCom4 vs аддитивное среднее mono (пул выше/ниже прогноза) ===')
for mode in ['neg', 'pos']:
    fn = [f for f in os.listdir(BASE) if mode in f and f.startswith('m_')][0]
    samples, feats = load_maf(os.path.join(BASE, fn))
    SC4 = ['B.velezensisSQR9', 'P.fluorescensJ', 'A.brasilenseK', 'B.megateriumL']
    rows_add = []
    for f in feats:
        sc = syncom_mean(f, 'SynCom4')
        ad = mean_mono(f, SC4)
        if sc is None or ad is None or ad == 0 or sc == 0:
            continue
        rows_add.append((f['cls'], math.log2(sc / ad), f['name']))
    dp = [x[1] for x in rows_add if x[0] == 'purine']
    do = [x[1] for x in rows_add if x[0] == 'other']
    print(f'{mode}: n_pur={len(dp)} n_oth={len(do)}')
    if dp and do:
        print(f'  purine медиана log2={statistics.median(dp):+.3f} доля>0={sum(1 for x in dp if x>0)/len(dp):.2f}')
        print(f'  other  медиана log2={statistics.median(do):+.3f} доля>0={sum(1 for x in do if x>0)/len(do):.2f}')
        real, p = class_perm_test(dp, do)
        print(f'  класс-тест |log2|: Δ={real:+.4f} p={p:.4f}')
