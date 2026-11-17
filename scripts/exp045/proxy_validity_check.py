#!/usr/bin/env python3
# 0h-check рецензента: корреляции прокси (b') с cert на существующих
# evolved-прогонах. Валидность metric'и до заморозки кампании.
# Goodhart-гейт: proxy должен коррелировать с cert, не только с activity.
import json, glob, math

def phi(rows, key, invert=False):
    # точечно-бисериальная корреляция cert (0/1) ↔ непрерывный признак
    xs = [(1.0 if r.get("certified") else 0.0, float(r.get(key) or 0.0)) for r in rows]
    n = len(xs)
    if n < 5:
        return None, n
    mc = sum(x for x, _ in xs) / n
    mk = sum(v for _, v in xs) / n
    cov = sum((x - mc) * (v - mk) for x, v in xs)
    sc = math.sqrt(sum((x - mc) ** 2 for x, _ in xs))
    sk = math.sqrt(sum((v - mk) ** 2 for _, v in xs))
    if sc == 0 or sk == 0:
        return None, n
    r = cov / (sc * sk)
    return (-r if invert else r), n

runs = sorted(glob.glob("/tmp/e1lite_evolved_grow/results_evolved_1*.json"))
rows = []
for path in runs:
    d = json.load(open(path))
    tasks = d["tasks"]
    if isinstance(tasks, dict):
        tasks = list(tasks.values())
    for t in tasks:
        for r in (t.get("routes") or []):
            if r.get("junk_fraction") is None:
                continue  # строки без телеметрии дороги скипаем честно
            rows.append(r)

print(f"evolved route-rows с телеметрией: {len(rows)} из {len(runs)} прогонов")
tu = [float(r.get("topk_useful") or 0) for r in rows]
print(f"topk_useful: min={min(tu)} max={max(tu)} mean={sum(tu)/len(tu):.3f} nonzero={sum(1 for v in tu if v>0)}")
us = [float(r.get("unspent") or 0) for r in rows]
print(f"unspent:     min={min(us)} max={max(us)} mean={sum(us)/len(us):.3f}")
cert_n = sum(1 for r in rows if r.get("certified"))
print(f"certified: {cert_n}/{len(rows)}")

r1, n1 = phi(rows, "topk_useful")
r2, n2 = phi(rows, "unspent")
r3, n3 = phi(rows, "wall", invert=True)
print(f"corr(cert, topk_useful)  = {r1}  n={n1}")
print(f"corr(cert, unspent)      = {r2}  n={n2}")
print(f"corr(cert, -wall)        = {r3}  n={n3}")

# Goodhart-контроль: у cert=false строк с высокой road-activity
high_tu_nocert = sum(1 for r in rows if not r.get("certified") and float(r.get("topk_useful") or 0) > 0)
print(f"nocert routes с topk_useful>0: {high_tu_nocert} (если много и corr мал — metric=activity)")
