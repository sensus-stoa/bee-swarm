#!/usr/bin/env python3
"""MATERIAL PROBE агрегатор (prereg: PREREG_MATERIAL_PROBE.md, заморожено 30.10).
junk = written && !2-term-sum (дефиниция driver.php:577-579)."""
import json, sys, glob

def is_2term(f: str) -> bool:
    return f.count('+') == 1 and ('×' in f) and ('min' not in f) and ('max' not in f)

def is_bare_product(f: str) -> bool:
    """Голое произведение raw-фич (компонент закона, материал mark⊕parent)."""
    import re
    return re.fullmatch(r'\(c\d+×c\d+\)', f) is not None

def is_wrapped(f: str) -> bool:
    import re
    return re.search(r'min|max|sq|²|√', f) is not None

path = sys.argv[1]
d = json.load(open(path))
written = set()
per_task = {}
for t in d['tasks']:
    tw = set()
    for lad in t.get('ladders', []):
        for rec in lad.get('records', []):
            for wf in ((rec.get('trail') or {}).get('written') or []):
                written.add(wf)
                tw.add(wf)
    per_task[t['id']] = tw

allw = sorted(written)
# Junk-таксономия v2 (уточнение 30.10, документировано в progress.md):
# материал = ступени (2-term) + голые произведения raw (компоненты);
# junk = обёртки (min/max/sq/²/√) ИЛИ глубокие композиции без raw-структуры.
material = [f for f in allw if not is_wrapped(f)]
junk = [f for f in allw if is_wrapped(f)]
two = [f for f in allw if is_2term(f)]
bare = [f for f in allw if is_bare_product(f)]
print(f"written_unique={len(allw)}  material={len(material)} (2term={len(two)}, bare={len(bare)})  junk={len(junk)}  junk_fraction={len(junk)/max(1,len(allw)):.3f}")
print(f"m3_loops={sum(len(t['m3_loops']) for t in d['tasks'])}")
for t in d['tasks']:
    tw = per_task[t['id']]
    tj = [f for f in tw if not is_2term(f)]
    nf = sum(1 for lad in t['ladders'] for r in [lad] if r.get('found'))
    print(f"  task={t['id']}: written={len(tw)} junk={len(tj)} ladders_found={nf}/{len(t['ladders'])}")
print("--- 2-term samples:", two[:6])
print("--- junk samples:", junk[:10])
