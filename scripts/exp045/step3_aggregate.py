#!/usr/bin/env python3
"""EXP-045 ШАГ III ablation — агрегатор атрибуции слоёв (prereg PREREG_STEP3_ABLATION.md).
CLI: python3 step3_aggregate.py /tmp/exp045_step3
Референс full-v4: шаг II (101 → 8/8, 103 → 8/8, written/подтверждения не сравниваются напрямую)."""
import glob
import json
import os
import sys

out_dir = sys.argv[1] if len(sys.argv) > 1 else "/tmp/exp045_step3"
ref = {101: 8, 103: 8}
runs = {}
for f in sorted(glob.glob(os.path.join(out_dir, "results_*_dot.json"))):
    name = os.path.basename(f)
    parts = name.replace("results_", "").replace("_dot.json", "").split("_")
    if len(parts) != 2:
        continue
    layer, seed = parts[0], int(parts[1])
    d = json.load(open(f))
    dot = next(t for t in d["tasks"] if t["id"] == "DOT")
    dnn = next(t for t in d["tasks"] if t["id"] == "DNN")
    runs[(layer, seed)] = {
        "found": sum(1 for l in dot["ladders"] if l["found"]),
        "null": sum(1 for l in dnn["ladders"] if l["found"]),
        "m3": len(dot["m3_loops"]),
        "written": dot["n_written_formulas"],
        "fuse": d["fuse"]["triggered"],
        "selfchecks": d["selfchecks"],
    }

layers = ["write", "tau", "slots", "nmd"]
print("=== EXP-045 ШАГ III ablation — атрибуция (референс full-v4: 101=8/8, 103=8/8) ===")
table = []
for layer in layers:
    row: dict = {"layer": layer}
    for seed in (101, 103):
        r = runs.get((layer, seed))
        row[seed] = r
    table.append(row)
    cells = []
    for seed in (101, 103):
        r = row[seed]
        if r is None:
            cells.append(f"{seed}: NO-JSON")
        else:
            delta = r["found"] - ref[seed]
            mark = " =" if delta == 0 else f" ({delta:+d})"
            cells.append(f"{seed}: {r['found']}/8{mark} m3={r['m3']} w={r['written']} fuse={'Y' if r['fuse'] else 'n'}")
    print(f"-{layer:<6} " + " | ".join(cells))

null_total = sum(r["null"] for r in runs.values())
fuse_any = any(r["fuse"] for r in runs.values())
missing = [k for k in [(l, s) for l in layers for s in (101, 103)] if k not in runs]
print(f"\nnull found total: {null_total} (ожидалось 0) | fuse any: {fuse_any} | прогонов: {len(runs)}/8 | missing: {missing}")
print("\nАтрибуция:")
for row in table:
    for seed in (101, 103):
        r = row[seed]
        if r is None:
            print(f"-{row['layer']}/{seed}: нет данных")
            continue
    d101 = (row[101] or {}).get("found", 0) - 8
    d103 = (row[103] or {}).get("found", 0) - 8
    if d101 < 0 or d103 < 0:
        print(f"-{row['layer']}: НЕСУЩИЙ слой в харнесе (found падает: 101 {d101:+d}, 103 {d103:+d})")
    elif d101 == 0 and d103 == 0:
        print(f"-{row['layer']}: no-delta (слой не влияет на found в этом харнесе)")
