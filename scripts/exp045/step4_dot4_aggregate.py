#!/usr/bin/env python3
"""EXP-045 ШАГ IV (Column 5): DOT4 re-run агрегатор.
Критерии ЗАМОРОЖЕНЫ (PREREG_DOT4_DEPTH_PROBE.md, неизменны):
  PASS-глубина : on found(DOT4) >=1 слот И off found=0/24 И null-found=0
  BORDER       : on found=0, НО в written 3-членные суммы (2 '+')
  FAIL         : ни 3-term в written, ни found при живой петле
  авто-FAIL    : found на DN4 любой рукой
CLI: python3 step4_dot4_aggregate.py <resultsDir> [v6BaselineDir]
"""
import glob
import json
import os
import sys


def plus_depth(f: str) -> int:
    """Число '+' в формуле = термы-суммы минус 1 (для сумм)."""
    return f.count("+")


def load_run(path: str):
    with open(path) as fh:
        return json.load(fh)


def summarize(d: dict) -> dict:
    tasks = {t["id"]: t for t in d["tasks"]}
    dot4 = tasks["DOT4"]
    dn4 = tasks.get("DN4")
    found = [l for l in dot4["ladders"] if l["found"]]
    null_found = [l for l in dn4["ladders"] if l["found"]] if dn4 else []
    # written-глубина: все written-списки всех лестниц DOT4
    plus_hist = {0: 0, 1: 0, 2: 0, 3: 0}  # 0=нет '+', 1=2-term, 2=3-term, 3=4-term+
    first2 = first3 = None
    examples3 = []
    for l in dot4["ladders"]:
        for rec in l["records"]:
            for wf in (rec.get("trail") or {}).get("written", []):
                p = min(3, plus_depth(wf))
                plus_hist[p] += 1
                if p >= 1 and first2 is None:
                    first2 = l["route_index"]
                if p >= 2:
                    if first3 is None:
                        first3 = l["route_index"]
                    if len(examples3) < 3 and "+" in wf:
                        examples3.append((l["route_index"], wf))
    walls = [l["wall"] for l in dot4["ladders"]]
    unspent = [l["unspent"] for l in dot4["ladders"]]
    return {
        "arm": d["arm"],
        "seed": d["seed"],
        "found": len(found),
        "found_certs": [l["cert_formula"] for l in found][:3],
        "null_found": len(null_found),
        "fuse": d["fuse"]["triggered"],
        "selfchecks": d["selfchecks"],
        "m3": len(dot4["m3_loops"]),
        "self_loops": dot4["m3_self_loops"],
        "n_written": dot4["n_written_formulas"],
        "plus_hist": plus_hist,
        "first_2term_route": first2,
        "first_3term_route": first3,
        "examples_3term": examples3,
        "wall_max": max(walls) if walls else 0.0,
        "unspent_mean": round(sum(unspent) / len(unspent), 3) if unspent else 0.0,
    }


def main() -> None:
    res_dir = sys.argv[1]
    v6_dir = sys.argv[2] if len(sys.argv) > 2 else None
    runs = {}
    for f in sorted(glob.glob(os.path.join(res_dir, "results_probe_*_dot4.json"))):
        d = load_run(f)
        runs[f"{d['arm']}/{d['seed']}"] = summarize(d)
    if not runs:
        print("NO RESULTS FOUND in", res_dir)
        sys.exit(1)

    on_found = sum(r["found"] for r in runs.values() if r["arm"] == "on")
    off_found = sum(r["found"] for r in runs.values() if r["arm"] == "off")
    null_found = sum(r["null_found"] for r in runs.values())
    fuse = any(r["fuse"] for r in runs.values())
    selfcheck_bad = {k: r["selfchecks"] for k, r in runs.items()
                     if not all(r["selfchecks"].values())}
    on_3term = {k: r["first_3term_route"] for k, r in runs.items()
                if r["arm"] == "on" and r["first_3term_route"] is not None}

    print("=" * 72)
    for k in sorted(runs):
        r = runs[k]
        print(f"{k:9s} found={r['found']}/8 null={r['null_found']}/2 m3={r['m3']:3d} "
              f"written={r['n_written']:3d} hist(+0/1/2/3+)={r['plus_hist'][0]}/{r['plus_hist'][1]}"
              f"/{r['plus_hist'][2]}/{r['plus_hist'][3]} "
              f"first2term=r{r['first_2term_route']} first3term="
              f"{'r' + str(r['first_3term_route']) if r['first_3term_route'] is not None else '-'} "
              f"wall_max={r['wall_max']} unspent~{r['unspent_mean']} fuse={'T' if r['fuse'] else '-'} "
              f"sc={'OK' if all(r['selfchecks'].values()) else 'FAIL:' + json.dumps(r['selfchecks'])}")
        if r["found"]:
            print(f"          certs: {r['found_certs']}")
        if r["examples_3term"]:
            print(f"          3-term ex: {r['examples_3term']}")
    print("=" * 72)
    print(f"TOTALS: on found={on_found}/24  off found={off_found}/24  "
          f"null found={null_found}  fuse={'TRIGGERED' if fuse else 'no'}  "
          f"selfcheck_bad={selfcheck_bad if selfcheck_bad else 'none'}")

    if v6_dir:
        print("--- v6 baseline comparison (d4_loop_probe) ---")
        v6 = {}
        for f in sorted(glob.glob(os.path.join(v6_dir, "results_probe_*_dot4.json"))):
            d = load_run(f)
            s = summarize(d)
            v6[f"{s['arm']}/{s['seed']}"] = s
        for k in sorted(v6):
            r = v6[k]
            print(f"v6 {k:9s} found={r['found']}/8 m3={r['m3']:3d} "
                  f"hist={r['plus_hist'][0]}/{r['plus_hist'][1]}/{r['plus_hist'][2]}/{r['plus_hist'][3]} "
                  f"first3term={'r' + str(r['first_3term_route']) if r['first_3term_route'] is not None else '-'}")

    print("=" * 72)
    if null_found > 0 or fuse:
        print("VERDICT: авто-FAIL (found на DN4 / fuse) — FPR-предохранитель сработал")
    elif on_found >= 1 and off_found == 0:
        print("VERDICT: PASS-глубина — дорога расширяет композиционную глубину "
              "(закон, недостижимый поиском, собран стигмергической цепочкой)")
    elif on_found == 0 and on_3term:
        print(f"VERDICT: BORDER — found=0, но цепочка идёт (3-term в written: {on_3term}); "
              "диагностика: бюджет/ранги чтения/квоты")
    elif on_found == 0:
        print("VERDICT: FAIL — ни 3-term в written, ни found при живой петле "
              "(дорога не масштабируется по глубине; граница зафиксирована)")
    else:
        print("VERDICT: НЕОДНОЗНАЧНО — см. таблицу (off нашёл found?)")

    # JSON-сводка рядом с результатами
    out = {"runs": runs, "totals": {"on_found": on_found, "off_found": off_found,
           "null_found": null_found, "fuse": fuse},
           "selfcheck_bad": selfcheck_bad, "on_3term_routes": on_3term}
    summary_path = os.path.join(res_dir, "SUMMARY_step4_dot4.json")
    with open(summary_path, "w") as fh:
        json.dump(out, fh, indent=1, ensure_ascii=False)
    print("summary ->", summary_path)


if __name__ == "__main__":
    main()
