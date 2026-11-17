#!/usr/bin/env python3
# E1-lite E2 aggregate — PREREG_E1LITE_ROADYIELD.md критерии (31.10).
# Каноны: PASS = pooled cert(evolved) > cert(evolvednm) без проигранного
# сида; GUARD-fuse (EN); GUARD-junk (>0.5); proxy-validity в нише.
import json, glob, math

out = "/tmp/e1lite_e2"
pooled = {"evolved": 0, "evolvednm": 0}
per_seed = {"evolved": [], "evolvednm": []}
guard_fuse = False
guard_junk = False
rows_all = []

for arm in ["evolved", "evolvednm"]:
    for seed in [101, 102, 103]:
        d = json.load(open(f"{out}/{arm}_{seed}/results_{arm}_{seed}.json"))
        tasks = d["tasks"]
        if isinstance(tasks, dict):
            tasks = list(tasks.values())
        cert = 0
        m3 = 0
        wr = 0
        jfs = []
        shares = []
        fpr = 0
        for t in tasks:
            routes = t.get("routes") or []
            rc = [r for r in routes if r.get("certified")]
            if rc:
                cert += 1
            if t.get("null_shuffle") or (t.get("id", "").startswith("EN")):
                if rc:
                    fpr += 1
            for r in routes:
                m3 += r.get("m3", 0) or 0
                wr += r.get("written", 0) or 0
                jf = r.get("junk_fraction")
                if jf is not None:
                    jfs.append(jf)
                    rows_all.append(r)
                if r.get("road_share") is not None:
                    shares.append(r["road_share"])
        jf_avg = sum(jfs) / len(jfs) if jfs else 0.0
        sh_avg = sum(shares) / len(shares) if shares else 0.0
        line = (f"{arm}/{seed}: cert={cert}/6 m3={m3} written={wr} "
                f"junkAvg={jf_avg:.3f} shareAvg={sh_avg:.4f} FPR={fpr}/2")
        print(line)
        pooled[arm] += cert
        per_seed[arm].append(cert)
        if fpr > 0:
            guard_fuse = True
        if jf_avg > 0.5:
            guard_junk = True

print("---")
ev = per_seed["evolved"]
nm = per_seed["evolvednm"]
print(f"pooled cert: evolved={pooled['evolved']} evolvednm={pooled['evolvednm']} per-seed ev={ev} nm={nm}")

# proxy-validity в нише (чек рецензента на новых данных)
def phi(key, invert=False):
    xs = [(1.0 if r.get("certified") else 0.0, float(r.get(key) or 0.0)) for r in rows_all]
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

tu = [float(r.get("topk_useful") or 0) for r in rows_all]
print(f"route-rows: {len(rows_all)}, topk_useful nonzero={sum(1 for v in tu if v>0)} max={max(tu) if tu else 0}")
r1, n1 = phi("topk_useful")
print(f"corr(cert, topk_useful) = {r1} n={n1}")

if guard_fuse:
    print("VERDICT: GUARD-FAIL (fuse)")
elif guard_junk:
    print("VERDICT: GUARD-FAIL (junk>0.5)")
elif pooled["evolved"] == 0 and pooled["evolvednm"] == 0:
    print("VERDICT: NICHE-EMPTY — cert=0 у обоих: ниша слишком жёсткая для cert-канала,")
    print("  вердикт по (c) не выносится; анализ: task budget/time vs сложность DOT4-ниш")
elif pooled["evolved"] > pooled["evolvednm"] and all(e >= n for e, n in zip(ev, nm)):
    print("VERDICT: PASS — компонент 1 B++ доказан (lite)")
elif any(n > e for n, e in zip(nm, ev)):
    print("VERDICT: FAIL — evolvednm превосходит evolved на сиде")
else:
    print("VERDICT: INCONCLUSIVE")
