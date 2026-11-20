#!/usr/bin/env python3
"""
B7 этап 2в: ЗАМОРОЖЕННЫЙ датасет Clark 25.
- исходы per-комбинация (mean по репликам): butyrate (primary), OD, acetate,
  lactate, succinate (secondary)
- M0 = sum(mono); M1 = M0 + сумма измеренных парных остатков
  r_ij = pair_mean - mono_i - mono_j (термы только для 129 измеренных пар)
- holdout: детерминированный hash-сплит по комбинации с стратификацией
  по богатству (мод 1000 < 300 → holdout), правило зафиксировано в B7_PREREG.md
Артефакты: Benchmarks/b7/clark25_frozen_dataset.json (+features копия).
"""
import csv, json, hashlib, statistics
from collections import defaultdict

SPECIES = ['ER','FP','AC','HB','CC','RI','DP','BH','CA','PC','DL','CG','BF','EL',
           'CH','BO','BT','BU','BV','BC','BY','PJ','DF','BL','BP']
sp_set = set(SPECIES)

rows_by_combo = defaultdict(list)
with open('/home/ninjacat/.bee_swarm/Benchmarks/clark25/MasterDF_WellNumbers.csv') as f:
    r = csv.reader(f)
    hdr = next(r)
    sp_idx = {s: hdr.index(s) for s in SPECIES}
    od_i = hdr.index('OD'); but_i = hdr.index('Butyrate')
    ac_i = hdr.index('Acetate'); lac_i = hdr.index('Lactate'); suc_i = hdr.index('Succinate')
    rep_i = hdr.index('Rep')
    for row in r:
        if len(row) < len(hdr):
            continue
        members = tuple(s for s in SPECIES if row[sp_idx[s]] == '1')
        if not members:
            continue
        def val(i):
            try:
                v = float(row[i])
                return v
            except (TypeError, ValueError):
                return None
        rows_by_combo[members].append({
            'rep': row[rep_i],
            'od': val(od_i), 'butyrate': val(but_i),
            'acetate': val(ac_i), 'lactate': val(lac_i), 'succinate': val(suc_i),
        })

combos = {}
for members, rows in rows_by_combo.items():
    rec = {'members': list(members), 'rich': len(members), 'n_rows': len(rows)}
    for out in ['od', 'butyrate', 'acetate', 'lactate', 'succinate']:
        vs = [x[out] for x in rows if x[out] is not None]
        rec[f'{out}_mean'] = statistics.mean(vs) if vs else None
        rec[f'{out}_n'] = len(vs)
    combos[members] = rec

monos = {c: r for c, r in combos.items() if r['rich'] == 1}
pairs = {c: r for c, r in combos.items() if r['rich'] == 2}
print(f'комбинаций: {len(combos)} | mono: {len(monos)} | пар: {len(pairs)} | rich>=3: {len(combos)-len(monos)-len(pairs)}')

mono_mean = {}
for c, r in monos.items():
    s = c[0]
    mono_mean[s] = {o: r[f'{o}_mean'] for o in ['od', 'butyrate', 'acetate', 'lactate', 'succinate']}

# --- M0, M1, pair coverage ---
pair_resid = {}   # (a,b) sorted -> {out: resid}
pair_cov_missing_mono = []
for c, r in pairs.items():
    a, b = c
    ma, mb = mono_mean.get(a, {}), mono_mean.get(b, {})
    for out in ['od', 'butyrate', 'acetate', 'lactate', 'succinate']:
        if r[f'{out}_mean'] is None or out not in ma or ma.get(out) is None or mb.get(out) is None:
            pair_resid.setdefault((a, b), {})[out] = None
        else:
            pair_resid.setdefault((a, b), {})[out] = r[f'{out}_mean'] - (ma[out] + mb[out]) / 2
n_pairs_ok = sum(1 for pr in pair_resid.values() if pr.get('butyrate') is not None)
print(f'парных остатков ноyrate: {n_pairs_ok}/{len(pairs)}')

def hash_split(members):
    key = '+'.join(sorted(members))
    h = int(hashlib.md5(key.encode()).hexdigest(), 16)
    return (h % 1000) < 300  # 30% holdout

def rich_bucket(k):
    if k <= 2: return 'base'
    if k <= 5: return 'r3-5'
    if k <= 15: return 'r6-15'
    return 'r16+'

dataset = {'monocultures': {}, 'pairs': {}, 'combos': []}
for s, vals in mono_mean.items():
    dataset['monocultures'][s] = vals
for c, pr in pair_resid.items():
    dataset['pairs']['|'.join(c)] = pr

stats_rich = defaultdict(list)
stats_rich_m1 = defaultdict(list)
for c, r in sorted(combos.items(), key=lambda kv: (kv[1]['rich'], kv[0])):
    if r['rich'] == 1:
        continue
    members = r['members']
    rec = {
        'members': members, 'rich': r['rich'],
        'n_rows': r['n_rows'],
        'hash_holdout': hash_split(members) if r['rich'] >= 3 else False,
        'rich_bucket': rich_bucket(r['rich']),
    }
    # pair coverage
    import itertools
    all_pairs = list(itertools.combinations(sorted(members), 2))
    cov = sum(1 for p in all_pairs if p in pair_resid and pair_resid[p].get('butyrate') is not None)
    rec['pair_cov'] = cov / len(all_pairs) if all_pairs else 0
    for out in ['od', 'butyrate', 'acetate', 'lactate', 'succinate']:
        obs = r[f'{out}_mean']
        if obs is None:
            continue
        monos_ok = [mono_mean.get(s, {}).get(out) for s in members]
        if any(v is None for v in monos_ok):
            rec[f'M0_{out}'] = None; rec[f'M1_{out}'] = None; rec[f'{out}_residM0'] = None
            rec[f'{out}_residM1'] = None
            continue
        m0 = statistics.mean(monos_ok)
        k = len(members)
        scale = 4.0 / (k * k)  # r_ij измерен при f=1/2 (f_i*f_j=1/4); в k-комбо f_i*f_j=1/k^2
        pair_sum = sum(pair_resid[p][out] * scale for p in all_pairs
                       if p in pair_resid and pair_resid[p].get(out) is not None)
        rec[f'M0_{out}'] = m0
        rec[f'M1_{out}'] = m0 + pair_sum
        rec[f'{out}_residM0'] = obs - m0
        rec[f'{out}_residM1'] = obs - (m0 + pair_sum)
    dataset['combos'].append(rec)
    if rec.get('butyrate_residM0') is not None:
        stats_rich[rec['rich']].append(rec['butyrate_residM0'])
    if rec.get('butyrate_residM1') is not None:
        stats_rich_m1[rec['rich']].append(rec['butyrate_residM1'])

print('\n=== sanity: медиана residM0/residM1 butyrate по богатству (репликация Δrich) ===')
for k in sorted(stats_rich):
    m0 = statistics.median(stats_rich[k]); m1 = statistics.median(stats_rich_m1[k])
    print(f'  rich={k:2d} n={len(stats_rich[k]):3d} | residM0={m0:+8.2f} | residM1={m1:+8.2f}')

# holdout статистика
hold = [c for c in dataset['combos'] if c['hash_holdout'] and c['rich'] >= 3]
train = [c for c in dataset['combos'] if not c['hash_holdout'] and c['rich'] >= 3]
print(f'\nholdout комбинаций (rich>=3): {len(hold)} | train rich>=3: {len(train)}')
hb = defaultdict(int)
for c in hold:
    hb[c['rich_bucket']] += 1
print('holdout по бакетам:', dict(hb))
with_cov = sum(1 for c in hold if c['pair_cov'] >= 0.5)
print(f'holdout с pair_cov>=0.5: {with_cov}/{len(hold)}')

dataset['split_rule'] = ('md5("+".join(sorted(members))) mod 1000 < 300 → holdout '
                         '(rich>=3 only); зафиксировано 20.11.2026 до M2/M3')
dataset['M0_def'] = ('mean(mono) - addivnyi nul pri ravnykh dolyakh '
                     '(vosproizvodit sertificirovannyi dR(rich) +0.7->+15 mM); '
                     'pravka 20.11.2026 do M2/M3: sum daval -65 mM pri rich 10-15 '
                     '(ne sootvetstvuet dizainu ravnoi plotnosti)')
dataset['pair_resid_def'] = ('pair_obs - mean(mono_i, mono_j); вклад в M1 = '
                             'r_ij * 4/k^2 (density-scaled, pravka 20.11.2026 '
                             'do M2/M3: unscaled sum raskhoditsya pri high k)')
dataset['primary_outcome'] = 'butyrate'
json.dump(dataset, open('/home/ninjacat/.bee_swarm/Benchmarks/b7/clark25_frozen_dataset.json', 'w'), indent=1)
print('\nсохранено Benchmarks/b7/clark25_frozen_dataset.json')
