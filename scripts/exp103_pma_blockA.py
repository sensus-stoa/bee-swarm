#!/usr/bin/env python3
"""
B6.5-PMA (Purine Mechanism Audit), блок A: перепроверка сертификата core-20.

Слои (owner 20.11):
  de novo: purA,B,C,D,E,F,H,K,L,M,N,T
  salvage: guaA,B,C(?), apt, hpt/gpt (нет в списке - добавить), ndk, adk, pnp, deoA,C
  XDH upstream: xdhA,B,C
  degradation: allantoicase, uglyase, gcl/glcB

Проблема исходного расчёта: фон «все гены вида в сети» и отсутствие
коррекции на множественность проверок (2 модуля × 2 контекста).
Пересчёт: фиксируем фон, многоуровневый Fisher, BH-коррекция по всем
проверенным модулям обоих контекстов.
"""
import openpyxl, json, math
from math import comb

BASE = '/home/ninjacat/.bee_swarm/Benchmarks/chodkowski/Supplementary_Files'
wb3 = openpyxl.load_workbook(f'{BASE}/Supplementary File 3.xlsx', read_only=True)

def locus2module(sheet):
    ws = wb3[sheet]
    d = {}
    for r in ws.iter_rows(values_only=True, min_row=2):
        if r and r[0] and r[1] is not None:
            d[str(r[0])] = str(r[1])
    return d

nets = {
    'Cs': locus2module('Bt_genesFromBtCsNetwork'),   # Bt в контексте C. subtsugae
    'Ps': locus2module('Bt_genesFromBtPsNetwork'),   # Bt в контексте P. syringae
}

# --- ген-списки с функциональной разметкой (заморожены ДО пересчёта) ---
PURINE_CORE = {
    'de_novo': ['purA','purB','purC','purD','purE','purF','purH','purK','purL','purM','purN','purT'],
    'salvage': ['guaA','guaB','adk','ndk','pnp','apt','deoA','deoC'],  # hpt/gpt отсутствуют в Bt-списке eutils
}
LOCUS = json.load(open('/tmp/bt_purine_loci.json'))
def alias_to_loci(alias):
    return [a.strip() for a in alias.split(',') if a.strip().startswith('BTH_')]

def resolve(names):
    out = {}
    for n in names:
        if n in LOCUS:
            for l in alias_to_loci(LOCUS[n]):
                out[l] = n
    return out

de_novo_loci = resolve(PURINE_CORE['de_novo'])
salvage_loci = resolve(PURINE_CORE['salvage'])
all_axis = {**de_novo_loci, **salvage_loci}
print(f'de novo: {len(de_novo_loci)} локусов | salvage: {len(salvage_loci)} | всего: {len(all_axis)}')

# --- исходный результат для сравнения (коммит 7d88c6c): модуль 24 (Ps) и 40 (Cs) ---
def fisher_exact(a, b, c, d):
    n = a + b + c + d
    r1, c1 = a + b, a + c
    def p(x):
        return comb(r1, x) * comb(n - r1, c1 - x) / comb(n, c1)
    pobs = p(a)
    return min(1.0, sum(p(x) for x in range(max(0, c1 - (n - r1)), min(r1, c1) + 1)
                        if p(x) <= pobs * 1.0001))

def bh_adjust(pvals):
    m = len(pvals)
    order = sorted(range(m), key=lambda i: pvals[i])
    adj = [0.0] * m
    prev = 1.0
    for rank, i in enumerate(reversed(order)):
        idx = order[len(order) - 1 - rank]
        # BH: p_adj = min over k>=rank of (p_k * m / k)
    # проще: стандартная реализация
    sorted_p = sorted((pvals[i], i) for i in range(m))
    adj = [1.0] * m
    prev = 1.0
    for rank in range(m - 1, -1, -1):
        p, i = sorted_p[rank]
        val = min(prev, p * m / (rank + 1))
        adj[i] = val
        prev = val
    return adj

results = []
for ctx, net in nets.items():
    total_genes = len(net)
    n_axis_in_net = sum(1 for l in all_axis if l in net)
    mod_counts = {}
    for l, m in net.items():
        mod_counts.setdefault(m, 0)
    axis_per_mod = {}
    for l, func in all_axis.items():
        if l in net:
            axis_per_mod.setdefault(net[l], []).append(func)
    # проверяем каждый модуль, содержащий ≥2 генов оси
    tested = []
    for m, genes in sorted(axis_per_mod.items(), key=lambda kv: -len(kv[1])):
        if len(genes) < 2:
            continue
        n_mod = sum(1 for v in net.values() if v == m)
        a = len(genes)
        b = n_mod - a
        c = n_axis_in_net - a
        d = total_genes - n_mod - c
        p = fisher_exact(a, b, c, d)
        tested.append({'ctx': ctx, 'module': m, 'n_mod': n_mod, 'n_axis': a,
                       'genes': genes, 'p': p})
    results.extend(tested)

# BH-коррекция по всем проверенным (2 контекста × модули с ≥2 генами оси)
ps = [r['p'] for r in results]
adj = bh_adjust(ps)
for r, q in zip(results, adj):
    r['q_bh'] = q

print('\n=== Блок A: enrichment с коррекцией множественности ===')
print(f"{'контекст':8.8} {'модуль':>8} {'n_генов':>8} {'осевых':>7} {'p':>10} {'q_BH':>10}")
for r in results:
    flag = ' <-- исходный сертификат' if (r['ctx'] == 'Ps' and r['module'] == '24') or \
                                        (r['ctx'] == 'Cs' and r['module'] == '40') else ''
    print(f"{r['ctx']:8.8} {r['module']:>8} {r['n_mod']:>8} {r['n_axis']:>7} {r['p']:>10.2e} {r['q_bh']:>10.3f}{flag}")

# выжившие после BH
surv = [r for r in results if r['q_bh'] < 0.10]
print(f'\nвыживших после BH q<0.10: {len(surv)}')
for r in surv:
    print(f"  {r['ctx']} модуль {r['module']}: {r['n_axis']} генов оси, q={r['q_bh']:.3f}")

json.dump(results, open('/tmp/pma_blockA.json', 'w'), default=str)
