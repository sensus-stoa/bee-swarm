#!/usr/bin/env python3
import json
from collections import defaultdict

LOG = '/home/ninjacat/.bee_swarm/Benchmarks/exp056_probe_map.log'
OUT = '/home/ninjacat/.bee_swarm/Benchmarks/exp056_probes_dev.jsonl'

base = {}
rows = []
for line in open(LOG):
    line = line.strip()
    if not line.startswith('PROBE'):
        continue
    fields = {}
    for p in line.split():
        if '=' in p:
            k, v = p.split('=', 1)
            fields[k] = v
    seed = int(fields['seed'])
    shape = fields['shape']
    bcv = None if fields.get('BCV') == 'null' else float(fields['BCV'])
    if shape == 'BASELINE':
        base[seed] = bcv
    else:
        rows.append({'seed': seed, 'shape': shape, 'bcv': bcv})

with open(OUT, 'w') as f:
    for r in rows:
        b0 = base.get(r['seed'])
        prog = max(0.0, b0 - r['bcv']) if (b0 is not None and r['bcv'] is not None) else 0.0
        r['progress'] = round(prog, 6)
        f.write(json.dumps(r) + "\n")

agg = defaultdict(lambda: {'n': 0, 'prog': 0.0, 'hits': 0})
for r in rows:
    a = agg[r['shape']]
    a['n'] += 1
    a['prog'] += r['progress']
    if r['progress'] > 0:
        a['hits'] += 1

print(f"rows={len(rows)} baselines={len(base)}")
print("=== SHAPES BY SUM PROGRESS (dev seeds, desc) ===")
for k, v in sorted(agg.items(), key=lambda x: -x[1]['prog']):
    print(f"{k}\tn={v['n']}\tsum_prog={v['prog']:.4f}\tper_seed={v['prog']/v['n']:.4f}\thits>0={v['hits']}")
zero = sum(1 for v in agg.values() if v['prog'] == 0)
print(f"zero-progress shapes: {zero}/{len(agg)}")
