#!/usr/bin/env python3
# E1-lite evolved (post-grow) aggregate — prereg: PREREG_E1LITE_EVOLVED_GROW.md
import json, sys, glob, os

out = sys.argv[1] if len(sys.argv) > 1 else "/tmp/e1lite_evolved_grow"
rows_out = []
pooled = {"evolved": 0, "evolvednm": 0}
per_seed = {"evolved": [], "evolvednm": []}
guard_fuse = False
guard_junk = False

for arm in ["evolved", "evolvednm"]:
    for seed in [101, 102, 103]:
        path = os.path.join(out, f"results_{arm}_{seed}.json")
        d = json.load(open(path))
        tasks = d["tasks"]
        if isinstance(tasks, dict):
            tasks = list(tasks.values())
        cert = 0
        found = 0
        m3 = 0
        wr = 0
        jfs = []
        dn4_cert = None
        for t in tasks:
            tid = t.get("id", "")
            routes = t.get("routes") or []
            rc = [r for r in routes if r.get("certified")]
            if rc:
                cert += 1
            if t.get("baseline", {}).get("found"):
                found += 1
            for r in routes:
                m3 += r.get("m3", 0) or 0
                wr += r.get("written", 0) or 0
                if r.get("junk_fraction") is not None:
                    jfs.append(r["junk_fraction"])
            if tid == "DN4":
                dn4_cert = bool(rc)
        jf_avg = sum(jfs) / len(jfs) if jfs else 0.0
        line = f"{arm}/{seed}: cert={cert} blFound={found} m3={m3} written={wr} junkAvg={jf_avg:.3f} DN4cert={dn4_cert}"
        print(line)
        rows_out.append(line)
        pooled[arm] += cert
        per_seed[arm].append(cert)
        if dn4_cert:
            guard_fuse = True
        if jf_avg > 0.5:
            guard_junk = True

print("---")
ev = per_seed["evolved"]
nm = per_seed["evolvednm"]
print(f"pooled: evolved={pooled['evolved']} evolvednm={pooled['evolvednm']} per-seed ev={ev} nm={nm}")
if guard_fuse:
    verdict = "GUARD-FAIL: DN4 fuse burned — стигмергия без fuse-дисциплины"
elif guard_junk:
    verdict = "GUARD-FAIL: junk>0.5 — производство течёт под эволюцией"
elif pooled["evolved"] > pooled["evolvednm"] and all(e >= n for e, n in zip(ev, nm)):
    verdict = "PASS: компонент 1 B++ доказан (lite) — стигмергия даёт селекционное преимущество"
elif pooled["evolved"] == pooled["evolvednm"] or any(n > e for n, e in zip(nm, ev)):
    verdict = "INCONCLUSIVE: паритет или смешанные сиды — ветка диагностики (fitness single-bit? mutation? material?)"
else:
    verdict = "INCONCLUSIVE"
print("VERDICT:", verdict)
