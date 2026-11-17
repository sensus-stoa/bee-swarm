#!/usr/bin/env python3
"""EXP-045 E1-lite агрегатор: тройная вилка owner'а (dilution/junk-generation/material-junk)."""
import json, glob, sys, math

def classify(f):
    if '+' in f and '×' in f and 'min' not in f and 'max' not in f and f.count('+') == 1:
        return 'useful'
    if 'min' in f or 'max' in f:
        return 'minmax'
    return 'other'

rows = []
for fp in sorted(glob.glob('/tmp/exp045_e1lite/results_*.json')):
    d = json.load(open(fp))
    arm, seed = d['arm'], d['seed']
    for t in d.get('tasks', []):
        if t['id'] not in ('DOT4A', 'DOT4B'):
            continue
        routes = t.get('routes', [])
        cert = sum(1 for r in routes if r.get('certified'))
        baseline_cert = bool(t.get('baseline', {}).get('certified'))
        novel = sum(1 for r in routes if r.get('certified') and not baseline_cert)
        written = sum(int(r.get('written', 0)) for r in routes)
        injected = sum(int(r.get('injected', 0)) for r in routes)
        m3 = sum(int(r.get('m3', 0)) for r in routes)
        m3_pos = sum(1 for r in routes if int(r.get('m3', 0)) > 0)
        tu = sum(int(r.get('topk_useful', 0)) for r in routes)
        tj = sum(int(r.get('topk_junk', 0)) for r in routes)
        confirmed_seen = sum(int(r.get('confirmed_seen', 0)) for r in routes)
        rows.append(dict(file=fp.split('_')[-1].replace('.json', ''), arm=arm, seed=seed, task=t['id'],
                         routes=len(routes), cert=cert, novel=novel, written=written, injected=injected,
                         m3=m3, m3_pos=m3_pos, topk_useful=tu, topk_junk=tj, confirmed_seen=confirmed_seen))

if not rows:
    print('NO DATA')
    sys.exit(1)

print('%-5s %-10s %-4s %-5s | %5s %5s %5s %6s %6s %5s | %10s %9s %8s' % (
    'SCALE', 'arm', 'seed', 'task', 'rt', 'cert', 'novel', 'written', 'inject', 'm3', 'topk_use', 'topk_junk', 'cnfrm'))
for r in sorted(rows, key=lambda x: (x['file'], x['arm'], x['seed'], x['task'])):
    print('%-5s %-10s %-4s %-5s | %5d %5d %5d %6d %6d %5d | %10d %9d %8d' % (
        r['file'], r['arm'], r['seed'], r['task'], r['routes'], r['cert'], r['novel'],
        r['written'], r['injected'], r['m3'], r['topk_useful'], r['topk_junk'], r['confirmed_seen']))

# Сводка по SCALE (evolved arm): novel, m3_pos incidence, material quality share
print('\n=== СВОДКА по SCALE (evolved, оба сида, DOT4A+DOT4B) ===')
from collections import defaultdict
agg = defaultdict(lambda: dict(novel=0, cert=0, m3=0, m3_pos=0, rt=0, written=0, tu=0, tj=0))
for r in rows:
    if r['arm'] != 'evolved':
        continue
    a = agg[r['file']]
    a['novel'] += r['novel']; a['cert'] += r['cert']; a['m3'] += r['m3']
    a['m3_pos'] += r['m3_pos']; a['rt'] += r['routes']; a['written'] += r['written']
    a['tu'] += r['topk_useful']; a['tj'] += r['topk_junk']
for s in sorted(agg, key=lambda x: int(x[1:])):
    a = agg[s]
    tot = a['tu'] + a['tj']
    share = (100.0 * a['tu'] / tot) if tot else 0.0
    print('SCALE=%-4s novel=%-3d cert=%-3d m3=%-4d m3>0=%-3d/%-3d written=%-5d quality_useful=%.0f%%' % (
        s, a['novel'], a['cert'], a['m3'], a['m3_pos'], a['rt'], a['written'], share))
