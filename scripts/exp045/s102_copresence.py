#!/usr/bin/env python3
"""EXP-045 сид-102: оффлайн-разделение факторов (owner 19.10).
1) co-presence операндов финального хопа: была ли ((c1×c4)+(c0×c3)) и (c2×c5)
   одновременно доступны (road_before ∪ injected) в одном вылете?
   ДА → хоп имел операнды и не склеился → pair-бюджет ВНУТРИ вылета (fix #1).
   НЕТ → операнды не встречались → бюджет ВЫЛЕТОВ/порядок (fix #4).
2) junk-доля в written по вылетам (рост каскада).
CLI: python3 s102_copresence.py /tmp/exp045_s102/results_full_102_dot.json"""
import json
import sys

d = json.load(open(sys.argv[1]))
dot = next(t for t in d["tasks"] if t["id"] == "DOT")
TERM2 = "((c1×c4)+(c0×c3))"
PROD = "(c2×c5)"

road = set()
timeline = []
for ladder in dot["ladders"]:
    for rec in ladder["records"]:
        if rec["op"] != "FLIGHT":
            continue
        tr = rec.get("trail", {}) or {}
        injected = set(tr.get("read_injected", []) or [])
        written = set(tr.get("written", []) or [])
        available = road | injected
        has2 = TERM2 in available
        hasp = PROD in available
        junk = sum(1 for f in written if "min" in f)
        timeline.append({
            "ladder": ladder["route_index"],
            "flight": rec.get("seq"),
            "has2": has2,
            "hasp": hasp,
            "copresent": has2 and hasp,
            "injected": len(injected),
            "written": len(written),
            "junk_written": junk,
        })
        road |= written

first2 = next((e for e in timeline if e["has2"]), None)
firstp = next((e for e in timeline if e["hasp"]), None)
copr = [e for e in timeline if e["copresent"]]
print("=== сид-102: таймлайн операндов финального хопа (road_before ∪ injected) ===")
print("первое появление 2-term на операции:", ("ladder %s flight %s" % (first2["ladder"], first2["flight"])) if first2 else "НИКОГДА")
print("первое появление (c2×c5):", ("ladder %s flight %s" % (firstp["ladder"], firstp["flight"])) if firstp else "НИКОГДА")
print("вылетов с co-presence (оба операнда доступны):", len(copr))
if copr:
    for e in copr[:5]:
        print("  co-presence: ladder %s flight %s" % (e["ladder"], e["flight"]))
junk_total = sum(e["junk_written"] for e in timeline)
wr_total = sum(e["written"] for e in timeline)
print("\njunk (min-формы) в written: %d из %d (%d%%)" % (junk_total, wr_total, 100 * junk_total // max(1, wr_total)))
late = [e for e in timeline if (e["ladder"], ) and (e["ladder"] > 0 or e["flight"] > 1)]
junk_late = sum(e["junk_written"] for e in late)
wr_late = sum(e["written"] for e in late) or 1
print("junk-доля с вылета 3+: %d из %d (%d%%)" % (junk_late, wr_late, 100 * junk_late // max(1, wr_late)))
if copr:
    print("\nВЕРДИКТ: операнды co-present → хоп имел материал и не склеился → pair-бюджет внутри вылета (fix #1)")
else:
    print("\nВЕРДИКТ: co-presence НИКОГДА → финальный хоп не имел обоих операндов → бюджет вылетов/порядок материализации (fix #4)")
