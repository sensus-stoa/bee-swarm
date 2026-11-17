#!/usr/bin/env python3
# 0h-check рецензента: gradient vs step. ratio = best_L_valid / base_cv
# (cert <=> ratio < 0.5). Континуум через 0.5 = gradient; зазор = step.
import json, glob

def collect(pattern, arm_filter=None):
    rows = []
    for path in sorted(glob.glob(pattern)):
        d = json.load(open(path))
        tasks = d["tasks"]
        if isinstance(tasks, dict):
            tasks = list(tasks.values())
        for t in tasks:
            if t.get("null"):
                continue  # null-задачи не про градиент
            b = t.get("baseline", {}) or {}
            base_cv = b.get("best_cv")
            if base_cv is None or base_cv <= 0:
                continue
            for r in (t.get("routes") or []):
                if not r.get("activated"):
                    continue
                if arm_filter and d.get("arm") != arm_filter:
                    continue
                lr = r.get("best_L_valid")
                if not isinstance(lr, (int, float)) or lr < 0:
                    continue
                rows.append((path.split("/")[-2] or path, t.get("id"), lr / base_cv, lr))
    return rows

def report(name, rows):
    if not rows:
        print(f"{name}: нет activated-строк с базой")
        return
    rs = sorted(r for r, _, _, _ in rows)
    n = len(rs)
    cert = sum(1 for r in rs if r < 0.5)
    mid = sum(1 for r in rs if 0.5 <= r < 0.8)
    print(f"{name}: n={n}")
    print(f"  ratio p0={rs[0]:.3f} p10={rs[n//10]:.3f} p25={rs[n//4]:.3f} p50={rs[n//2]:.3f} p75={rs[3*n//4]:.3f} p90={rs[9*n//10]:.3f} max={rs[-1]:.3f}")
    print(f"  cert(<0.5)={cert}  зона градиента(0.5-0.8)={mid}  стена(>=0.8)={n-cert-mid}")
    best = {}
    for r, src, tid, lr in rows:
        if tid not in best or r < best[tid][0]:
            best[tid] = (r, src)
    tops = sorted(best.items(), key=lambda kv: kv[1][0])[:10]
    print("  лучшие: " + ", ".join(f"{k}={v[0]:.3f}({v[1]})" for k, v in tops))

report("E1-LITE лёгкий сет (evolved, 3 прогона)", collect("/tmp/e1lite_evolved_grow/results_evolved_1*.json"))
report("E2 ниша (evolved, 3 прогона)", collect("/tmp/e1lite_e2/evolved_1*/results_*.json"))
report("E2 ниша (evolvednm, 3 прогона)", collect("/tmp/e1lite_e2/evolvednm_1*/results_*.json"))
