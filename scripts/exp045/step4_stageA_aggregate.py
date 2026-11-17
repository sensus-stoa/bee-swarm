#!/usr/bin/env python3
"""EXP-045 Stage A агрегатор (PREREG_STAGE_A_EVOLUTION §4-5, заморожено).
Армы: evolved/random/noinherit/shuffled/ordershuf/baseline × сиды 101-103.
Единица McNemar: route-слот (D4 × 3 сида × 8 маршрутов, n=24), пары по
индексу маршрута evolved vs random; caveat псевдорепликации декларирован.
Novel-cert = route certified ∧ ¬baseline certified (тот же task, seed).
Пространство §5.0: доля d1-FLIGHT у winner'ов (population_export).
CLI: python3 step4_stageA_aggregate.py <dir>
"""
import glob
import json
import math
import os
import sys

ARMS = ["evolved", "evolvednm", "random", "noinherit", "shuffled", "ordershuf", "baseline"]
SEEDS = [101, 102, 103]


def mcnemar_exact_two_sided(b: int, c: int) -> float:
    """Точный двусторонний McNemar = биномиальный на discordant-ах."""
    n = b + c
    if n == 0:
        return 1.0
    k = min(b, c)
    p = sum(math.comb(n, i) for i in range(0, k + 1)) / (2 ** n)
    return min(1.0, 2 * p)


def load_run(path: str) -> dict:
    with open(path) as fh:
        return json.load(fh)


def main() -> None:
    res_dir = sys.argv[1]
    runs = {}
    for arm in ARMS:
        for seed in SEEDS:
            f = os.path.join(res_dir, f"results_{arm}_{seed}.json")
            if os.path.isfile(f):
                runs[(arm, seed)] = load_run(f)
    if not runs:
        print("NO RESULTS in", res_dir)
        sys.exit(1)

    # ---- таблица novel-cert: (arm, seed, task) → [route novel flags] ----
    table = {}
    baselines = {}
    for (arm, seed), d in sorted(runs.items()):
        for t in d["tasks"]:
            key = (arm, seed, t["id"])
            bl = bool(t.get("baseline", {}).get("certified", False))
            if arm == "baseline":
                baselines[(seed, t["id"])] = bl
            novel = []
            for r in t.get("routes", []):
                if r.get("activated"):
                    novel.append(bool(r.get("certified", False)) and not bl)
            table[key] = novel

    print("=" * 78)
    print("novel-cert (route certified ∧ ¬baseline) per task/seed:")
    for (arm, seed, task), nov in sorted(table.items()):
        print(f"  {arm:10s} s{seed} {task}: {sum(nov)}/{len(nov)}")

    # ---- McNemar: evolved vs random, D4 route-слоты, пул ВСЕХ сидов ----
    e = []
    r4 = []
    for seed in SEEDS:
        ea = table.get(("evolved", seed, "D4"), [])
        ra = table.get(("random", seed, "D4"), [])
        n_pairs_seed = min(len(ea), len(ra))
        for i in range(n_pairs_seed):
            e.append(ea[i])
            r4.append(ra[i])
    b, c = 0, 0
    n_pairs = min(len(e), len(r4))
    for i in range(n_pairs):
        if e[i] and not r4[i]:
            b += 1
        elif r4[i] and not e[i]:
            c += 1
    p_val = mcnemar_exact_two_sided(b, c)
    print("-" * 78)
    print(f"McNemar D4 route-слоты (пул 3 сидов, n={n_pairs} пар): discordant b(evolved+/random-)={b}, "
          f"c(evolved-/random+)={c}, exact two-sided p={p_val:.4f}")
    print("caveat: слоты одного (task,seed) делят популяцию — псевдорепликация "
          "декларирована; seed-level sign test n=3 дескриптивно.")

    # ---- McNemar #2: evolved vs evolvednm (plain-vs-stigmergy), пул сидов ----
    nm = []
    for seed in SEEDS:
        na = table.get(("evolvednm", seed, "D4"), [])
        na_e = table.get(("evolved", seed, "D4"), [])
        n_seed = min(len(na), len(na_e))
        for i in range(n_seed):
            nm.append(na[i])
    b2, c2 = 0, 0
    n2 = min(len(e), len(nm))
    for i in range(n2):
        if e[i] and not nm[i]:
            b2 += 1
        elif nm[i] and not e[i]:
            c2 += 1
    p2 = mcnemar_exact_two_sided(b2, c2)
    print(f"McNemar evolved-vs-evolvednm D4 (пул, n={n2} пар): b(evolved+/nm-)={b2}, "
          f"c(evolved-/nm+)={c2}, exact two-sided p={p2:.4f}")
    nm_novel = sum(nm)

    # ----Fuse / nulls ----
    fuse_null = []
    for (arm, seed), d in runs.items():
        if arm == "baseline":
            continue
        for n in d.get("nulls", []):
            if n.get("certified"):
                fuse_null.append((arm, seed, n.get("id")))
    print(f"null-certifications (route arms): {fuse_null if fuse_null else 'none'}")

    # ---- §5.0: пространство геномов у evolved winner'ов ----
    print("-" * 78)
    space = {}
    d_evo = runs.get(("evolved", 101))
    if d_evo:
        pe = d_evo.get("population_export", {})
        genomes = {g["id"]: g for g in pe.get("routes", [])}
        for seed in SEEDS:
            d = runs.get(("evolved", seed))
            if not d:
                continue
            pe = d.get("population_export", {})
            for g in pe.get("routes", []):
                genomes.setdefault(g["id"], g)
            for t in d["tasks"]:
                bl = bool(t.get("baseline", {}).get("certified", False))
                for r in t.get("routes", []):
                    if not r.get("activated"):
                        continue
                    nov = bool(r.get("certified", False)) and not bl
                    g = genomes.get(r["id"])
                    if g is None:
                        continue
                    fl = [a for a in g.get("actions", []) if a.get("op") == "FLIGHT"]
                    d1 = sum(1 for a in fl if a.get("depth") == 1)
                    d2 = sum(1 for a in fl if a.get("depth") == 2)
                    share = d1 / len(fl) if fl else 0.0
                    space.setdefault("winner" if nov else "rest", []).append(
                        {"seed": seed, "task": t["id"], "d1_share": round(share, 2),
                         "flights": len(fl), "d1": d1, "d2": d2})
    if space:
        import statistics as st
        for grp in ("winner", "rest"):
            rows = space.get(grp, [])
            if rows:
                print(f"§5.0 {grp}: n={len(rows)} d1_share mean="
                      f"{round(st.mean([x['d1_share'] for x in rows]), 2)} "
                      f"distribution={sorted(collections.Counter(round(x['d1_share'],1) for x in rows).items())}")
        print("  (а) all-d2 ⇔ winner d1_share≈0; (б) mixed ⇔ устойчивая доля d1 "
              "с паттерном d1-до-M; (в) специализация ⇔ d1-доля коррелирует с классом задачи")

    print("=" * 78)
    evo_novel = sum(e)
    print(f"TOTALS: evolved D4 novel={evo_novel}/{len(e)} random D4 novel={sum(r4)}/{len(r4)} "
          f"evolvednm D4 novel={nm_novel}/{len(nm)}")

    # ---- Вердикт §5.1 (три A-PASS; операционализация порогов — draft, флаг owner) ----
    plain_like = nm_novel >= 0.5 * max(1, evo_novel) and p2 > 0.05
    stig_like = (nm_novel < 0.5 * max(1, evo_novel)) and (p2 < 0.05 or nm_novel == 0)
    if fuse_null:
        print("VERDICT: авто-FAIL (null-certification в route-руках) — прогоны не интерпретируются")
    elif p_val < 0.05 and b > c:
        if stig_like and not plain_like:
            print("VERDICT: A-PASS-stigmergic — селекция на дорожной памяти (evolvednm падает: "
                  f"nm={nm_novel} vs evolved={evo_novel}, p2={p2:.4f}); Stage B с claim'ом stigmergy")
        elif plain_like and not stig_like:
            print("VERDICT: A-PASS-plain — config transfer (evolvednm ≈ evolved: "
                  f"nm={nm_novel} vs evolved={evo_novel}); метки не нужны, claim НЕ stigmergy")
        else:
            print("VERDICT: A-PASS-both — оба компонента (evolvednm > 0, но < evolved: "
                  f"nm={nm_novel} vs evolved={evo_novel}); claim гибридный")
    elif evo_novel == 0 and all(
            sum(table.get(("a", s, "D4"), [])) == 0
            for a in ("random", "noinherit", "shuffled", "ordershuf", "evolvednm") for s in SEEDS):
        print("VERDICT: A-KILL — все route-руки novel-cert=0 на D4; программа genome transfer "
              "закрывается (честный негатив; БЕЗ второго amendment канала)")
    elif b == 0 and c == 0:
        print("VERDICT: A-AMBIGUOUS — novel-cert есть, селекция не отличима от случайного "
              "(0 discordant); разбор диагностикой, Stage B не открывается")
    else:
        print("VERDICT: McNemar не значим при наличии discordant-ов — см. таблицу "
              "(пограничный случай, решение owner)")

    out = {"mcnemar": {"b": b, "c": c, "n": n_pairs, "p": p_val},
           "mcnemar_nm": {"b": b2, "c": c2, "n": n2, "p": p2, "nm_novel": nm_novel},
           "space": space, "fuse_null": fuse_null}
    with open(os.path.join(res_dir, "SUMMARY_stageA.json"), "w") as fh:
        json.dump(out, fh, indent=1, ensure_ascii=False)
    print("summary ->", os.path.join(res_dir, "SUMMARY_stageA.json"))


if __name__ == "__main__":
    import collections  # noqa: F401 (используется в print space distribution)
    main()
