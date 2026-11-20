#!/usr/bin/env python3
"""
B6.6 — Time-conditioned Network Robustness (raw counts доступен в ShadeLab repo!).

Owner (20.11): один узкий аудит, без подбора параметров. Проверка:
выживает ли purine↔ribosome (Ps-контекст) и purine↔malleilactone (Cs-контекст)
после удаления эффектов ВРЕМЕНИ (6 точек) и БИО-РЕПЛИК (4 блока).

Данные: /tmp/shadelab (ShadeLab/Paper_Chodkowski_MonocultureExometabolites_2020):
- RNAseq/diffGeneExp/initialFiles/B-thailandensis_raw_counts.txt (5642 гена × 94 сэмпла)
- B-thailandensis_LIBRARIES.txt: libraryName → Condition, BioRep, timepoint

Метод (без перестройки сети — прямой тест ассоциации):
1. scores: per-сэмпл среднее z-score(log2CPM) генов набора
2. partial correlation: остатки scores после регрессии на time(6)+BioRep(4)
3. null: permutation purine-остатков ВНУТРИ timepoint-блоков (сохраняет
   временную структуру) + отдельная permutation внутри BioRep
4. endpoints: Ps-контекст (Mono+Pseudo), Cs-контекст (Mono+Chromo)
"""
import math, json, statistics, random
from collections import defaultdict

# --- загрузка counts ---
def load_counts(path):
    with open(path) as f:
        lines = f.readlines()
    hdr = lines[0].rstrip().split('\t')
    libs = hdr[4:]
    genes = {}  # locus -> (product, [counts])
    for ln in lines[1:]:
        p = ln.rstrip().split('\t')
        if len(p) < len(hdr):
            continue
        locus = p[1]
        product = p[3]
        counts = []
        for v in p[4:]:
            try:
                counts.append(float(v))
            except ValueError:
                counts.append(0.0)
        genes[locus] = (product, counts)
    return libs, genes

libs, genes = load_counts('/tmp/shadelab/RNAseq/diffGeneExp/initialFiles/B-thailandensis_raw_counts.txt')
print(f'Bt counts: {len(genes)} генов × {len(libs)} библиотек')

# --- metadata ---
meta = {}
with open('/tmp/shadelab/RNAseq/diffGeneExp/initialFiles/B-thailandensis_LIBRARIES.txt') as f:
    hdr = f.readline().rstrip().split('\t')
    for ln in f:
        p = ln.rstrip().split('\t')
        if len(p) < 9:
            continue
        meta[p[0]] = {'condition': p[6], 'biorep': p[7], 'time': p[8], 'sample': p[4]}
print(f'metadata: {len(meta)} библиотек')

# --- фильтр генов: средний CPM > 0.5 в любом контексте, log2CPM ---
N = len(libs)
def cpm_ok(counts):
    total = sum(counts)
    return total > 100  # минимальный фильтр; нормировка per-sample ниже

log_cpm = {}  # locus -> [log2(CPM+1)]
for locus, (prod, counts) in genes.items():
    total = sum(counts)
    if total < 100:
        continue
    lib_totals = defaultdict(float)
    # общая библиотечная глубина
    pass
# глубина per-sample
lib_depth = [0.0] * N
for locus, (prod, counts) in genes.items():
    for i, c in enumerate(counts):
        lib_depth[i] += c
log_cpm = {}
for locus, (prod, counts) in genes.items():
    if sum(counts) < 50:
        continue
    log_cpm[locus] = [math.log2(c / lib_depth[i] * 1e6 + 1) for i, c in enumerate(counts)]
print(f'после фильтра: {len(log_cpm)} генов')

# --- ген-наборы ---
LOCUS = json.load(open('/tmp/bt_purine_loci.json'))
def alias_loci(alias):
    return [a.strip() for a in alias.split(',') if a.strip().startswith('BTH_')]
purine_loci = []
for gene, alias in LOCUS.items():
    for l in alias_loci(alias):
        if l in log_cpm:
            purine_loci.append((gene, l))
            break
print(f'purine-гены с counts: {len(purine_loci)}/20')

# ribosomal: по geneProduct
ribo_loci = [l for l, (prod, _) in genes.items()
             if 'ribosomal protein' in prod.lower() and l in log_cpm]
print(f'ribosomal генов: {len(ribo_loci)}')

# malleilactone: локусы из GO-категории (Suppl File 4 'Genes')
import openpyxl
wb4 = openpyxl.load_workbook('/home/ninjacat/.bee_swarm/Benchmarks/chodkowski/Supplementary_Files/Supplementary File 4.xlsx', read_only=True)
mal_loci = []
for sh in wb4.sheetnames:
    try:
        ws = wb4[sh]
        for r in ws.iter_rows(values_only=True):
            if r and any(v and 'malleilactone' in str(v).lower() for v in r):
                for cell in r:
                    for g in str(cell).split():
                        if g.startswith('BTH_') and g not in mal_loci:
                            mal_loci.append(g)
    except Exception:
        pass
mal_loci = [l for l in mal_loci if l in log_cpm]
print(f'malleilactone генов (GO-список, с counts): {len(mal_loci)}')

# --- per-sample scores ---
def scores(loci_list, sample_idx):
    if not loci_list:
        return [0.0] * N
    zs = []
    flat = loci_list if isinstance(loci_list[0], tuple) else [(None, l) for l in loci_list]
    for _, l in flat:
        vals = log_cpm[l]
        mu = statistics.mean(vals); sd = statistics.pstdev(vals)
        zs.append([(v - mu) / sd if sd > 0 else 0 for v in vals])
    n = len(libs)
    return [statistics.mean(z[i] for z in zs) for i in range(n)]

pur_score = scores(purine_loci, None)
ribo_score = scores([(None, l) for l in ribo_loci], None)
mal_score = scores([(None, l) for l in mal_loci], None)

# --- контексты ---
def samples_for(condition):
    return [i for i, l in enumerate(libs) if meta.get(l, {}).get('condition') == condition]

def residualize(v, times, reps):
    """вычитание групповых средних time и biorep (чередование до сходимости)."""
    v = list(v)
    for _ in range(20):
        for tp in set(times):
            idx = [i for i, t in enumerate(times) if t == tp]
            if len(idx) > 1:
                mu = statistics.mean(v[i] for i in idx)
                for i in idx:
                    v[i] -= mu
        for br in set(reps):
            idx = [i for i, b in enumerate(reps) if b == br]
            if len(idx) > 1:
                mu = statistics.mean(v[i] for i in idx)
                for i in idx:
                    v[i] -= mu
    return v

def partial_corr(xs, ys, times, reps):
    return residualize(xs, times, reps), residualize(ys, times, reps)

def pearson(xs, ys):
    n = len(xs)
    mx, my = statistics.mean(xs), statistics.mean(ys)
    sxy = sum((x-mx)*(y-my) for x, y in zip(xs, ys))
    sx = math.sqrt(sum((x-mx)**2 for x in xs))
    sy = math.sqrt(sum((y-my)**2 for y in ys))
    return sxy/(sx*sy) if sx > 0 and sy > 0 else float('nan')

def block_perm_p(resid_x, resid_y, blocks, n_perm=5000, seed=2024):
    """permutation остатков x внутри блоков time (сохраняет временную структуру)."""
    random.seed(seed)
    real = pearson(resid_x, resid_y)
    cnt = 0
    idx_by_block = defaultdict(list)
    for i, b in enumerate(blocks):
        idx_by_block[b].append(i)
    for _ in range(n_perm):
        xs = resid_x[:]
        for b, idx in idx_by_block.items():
            vals = [xs[i] for i in idx]
            random.shuffle(vals)
            for k, i in enumerate(idx):
                xs[i] = vals[k]
        r = pearson(xs, resid_y)
        if abs(r) >= abs(real):
            cnt += 1
    return real, cnt / n_perm

results = {}
for ctx_name, conditions, target_name, target_score in [
        ('Ps', ['Mono', 'Pseudo'], 'ribosome', ribo_score),
        ('Cs', ['Mono', 'Chromo'], 'malleilactone', mal_score)]:
    idx = [i for i, l in enumerate(libs)
           if meta.get(l, {}).get('condition') in conditions]
    px = [pur_score[i] for i in idx]
    ty = [target_score[i] for i in idx]
    blocks_time = [meta[libs[i]]['time'] for i in idx]
    blocks_rep = [meta[libs[i]]['biorep'] for i in idx]
    # сырая корреляция
    r_raw = pearson(px, ty)
    # корреляция с time-conditioning
    resx_t, resy_t = partial_corr(px, ty, blocks_time, ['*'] * len(px))
    r_time = pearson(resx_t, resy_t)
    # с time + biorep
    resx_tr, resy_tr = partial_corr(px, ty, blocks_time, blocks_rep)
    r_tr = pearson(resx_tr, resy_tr)
    # permutation: внутри time-блоков (как owner просил — блочная permutation)
    _, p_time = block_perm_p(resx_t, resy_t, blocks_time)
    # permutation внутри time × BioRep ячеек (самый строгий)
    blocks_tr = [f"{meta[libs[i]]['time']}|{meta[libs[i]]['biorep']}" for i in idx]
    _, p_tr = block_perm_p(resx_tr, resy_tr, blocks_tr)
    results[ctx_name] = {
        'n_samples': len(idx),
        'r_raw': r_raw, 'r_time_conditioned': r_time,
        'r_time_plus_rep': r_tr,
        'p_time_block_perm': p_time, 'p_time_rep_block_perm': p_tr,
    }
    print(f"\n=== {ctx_name}-контекст (n={len(idx)}: {'+'.join(conditions)}) ===")
    print(f"  purine↔{target_name}: r_raw={r_raw:+.3f}")
    print(f"  time-conditioned:    r={r_time:+.3f}  p(block-perm time)={p_time:.4f}")
    print(f"  time+rep-conditioned: r={r_tr:+.3f}  p(block-perm time×rep)={p_tr:.4f}")

# --- стабильность генов: leave-one-gene-out для Ps-24 ---
idx = [i for i, l in enumerate(libs) if meta.get(l, {}).get('condition') in ('Mono', 'Pseudo')]
loocv = []
for k in range(len(purine_loci)):
    sub = [p for j, p in enumerate(purine_loci) if j != k]
    sc = scores(sub, None)
    resx, resy = partial_corr([sc[i] for i in idx], [ribo_score[i] for i in idx],
                              [meta[libs[i]]['time'] for i in idx],
                              ['*'] * len(idx))
    loocv.append(pearson(resx, resy))
print(f"\nLeave-one-gene-out (Ps, time-conditioned): r от {min(loocv):+.3f} до {max(loocv):+.3f}, "
      f"медиана {statistics.median(loocv):+.3f}")

json.dump({'results': results, 'loocv_min': min(loocv), 'loocv_max': max(loocv),
           'loocv_median': statistics.median(loocv)},
          open('/tmp/pma_b66.json', 'w'), indent=1)
