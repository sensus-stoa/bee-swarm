#!/usr/bin/env python3
"""Дамп AssemblyRelAbu + пуриновые профили видов (mono BHI секретом) в JSON."""
import openpyxl, math, json

wb = openpyxl.load_workbook('/home/ninjacat/.bee_swarm/Benchmarks/ho2024/rc_relab.xlsx', read_only=True)
ws = wb['AssemblyRelAbu']
rows = list(ws.iter_rows(values_only=True))
species = [str(v) for v in rows[1][1:] if v and str(v) != 'None']
treatments = []
for r in rows[2:]:
    if r and r[0] and str(r[0]) != 'None':
        members = str(r[0]).split('-')
        frac = {}
        for i, s in enumerate(species):
            v = r[i + 1] if i + 1 < len(r) else None
            frac[s] = float(v) if isinstance(v, (int, float)) else 0.0
        treatments.append({'members': members, 'rich': len(members), 'frac': frac})
print('treatments:', len(treatments))

# --- профили: enrichment ratio per вид для ключевых фич (mono BHI) ---
wbm = openpyxl.load_workbook('/home/ninjacat/.bee_swarm/Benchmarks/ho2024/rc_metab.xlsx', read_only=True)
rows_m = list(wbm[wbm.sheetnames[0]].iter_rows(values_only=True))
hdr = [str(v) for v in rows_m[0]]
col_of = {}
for j, h in enumerate(hdr[6:], start=6):
    col_of.setdefault(h, []).append(j)
fresh = col_of['BHI']
def avg(vals, js):
    v = [vals[j] for j in js if isinstance(vals[j], (int, float))]
    return sum(v) / len(v) if v else None

mapping = {
    'Enterococcus hirae': 'Eh', 'Escherichia fergusonii': 'Efe',
    'Clostridium symbiosum': 'Csy', 'Bacteroides thetaiotaomicron': 'Bt',
    'Clostridium clostridioforme': 'Cc', 'Blautia producta': 'Bp',
    'Clostridium scindens': 'Csc', 'Enterococcus faecium': 'Efm',
    'Clostridium hylemonae': 'Chy', 'Enterococcus faecalis': 'Efs',
    'Clostridium hathewayi': 'Cha', 'Bacteroides fragilis': 'Bf',
    'Flavonifractor plautii': 'Fp', 'Bacteroides uniformis': 'Bu',
    'Parabacteroides distasonis': 'Pd',
}
SPECIES = list(mapping.values())
PUR = ['purin', 'xanthin', 'uric', 'hypoxanthin', 'inosin', 'adenin', 'guanin', 'allantoin',
       'nucleos', 'adenosin', 'guanosin', 'uridin', 'cytidin', 'thymidin', 'orot',
       'methylthioadenosine']
CAT_TARGETS = ['isopentenyladenine', 'allantoin', 'methylthioadenosine']
NUC_TARGETS = ['adenosine', 'thymidine', 'uridine', 'guanosine']

cat = {s: [] for s in SPECIES}
nuc = {s: [] for s in SPECIES}
pur_enr = {s: 0 for s in SPECIES}
oth_enr = {s: 0 for s in SPECIES}
for r in rows_m[1:]:
    if not r or not r[0]:
        continue
    name = str(r[4]) if len(r) > 4 and r[4] and str(r[4]) != 'nan' else str(r[0])
    nl = name.lower()
    f = avg(r, fresh)
    if f is None:
        continue
    is_pur = any(k in nl for k in PUR)
    is_cat = any(t in nl for t in CAT_TARGETS)
    is_nuc = any((t + ' ') in nl + ' ' or nl.endswith(t) or t in nl.split("'") and False for t in NUC_TARGETS) or any(t == nl for t in NUC_TARGETS)
    for s in SPECIES:
        v = avg(r, col_of.get(s, []))
        if v is None:
            continue
        ratio = v / max(f, 1e-9)
        if ratio > 1.5:
            if is_pur:
                pur_enr[s] += 1
            else:
                oth_enr[s] += 1
        if is_cat:
            cat[s].append(ratio)
        if is_nuc:
            nuc[s].append(ratio)

# mono yield (BHI OD48h)
wby = openpyxl.load_workbook('/tmp/ho_yield_bhi.xlsx', read_only=True)
yields = {}
for r in list(wby['Monoculture'].iter_rows(values_only=True))[2:]:
    if r and r[0] in mapping:
        vals = [float(v) for v in r[1:5] if isinstance(v, (int, float))]
        if vals:
            yields[mapping[r[0]]] = sum(vals) / len(vals)

out = {
    'cat': {s: (sum(v) / len(v) if v else None) for s, v in cat.items()},
    'nuc': {s: (sum(v) / len(v) if v else None) for s, v in nuc.items()},
    'share': {s: (pur_enr[s] / max(pur_enr[s] + oth_enr[s], 1) if pur_enr[s] + oth_enr[s] > 3 else None) for s in SPECIES},
    'yield': yields,
}
json.dump(treatments, open('/tmp/ho_relab.json', 'w'))
json.dump(out, open('/tmp/ho_profiles2.json', 'w'))
print('profiles: cat/nuc/share/yield готовы')
