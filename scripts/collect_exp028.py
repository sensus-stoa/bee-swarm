#!/usr/bin/env python3
"""
Сборка финальной таблицы EXP-028 из логов PySR + Bee (ноут).
Чтение: /tmp/pysr_13.log, /tmp/bee_13.log (по SSH).
Вывод: markdown-таблица.
"""
import json, re, sys

def parse_pysr_log(path):
    """Достаёт JSON из лога PySR"""
    text = open(path).read()
    m = re.search(r'=== JSON ===\n(\{.*\})', text, re.S)
    if not m:
        return None
    try:
        return json.loads(m.group(1))
    except json.JSONDecodeError:
        return None

def parse_bee_log(path):
    """Достаёт строки '=== name:' + 'CV_H med=...'"""
    out = {}
    text = open(path).read()
    for m in re.finditer(r'=== (\w+): .*?\n(.*?)(?=\n=== |\Z)', text, re.S):
        name, body = m.group(1), m.group(2)
        cv = re.search(r'CV_H med=([\d.]+) q05=([\d.]+) q95=([\d.]+)\s+success=(\d+)/(\d+)\s+R2=([\d.]+)?', body)
        if cv:
            out[name] = {
                'cv_med': float(cv.group(1)),
                'q05': float(cv.group(2)),
                'q95': float(cv.group(3)),
                'success': f"{cv.group(4)}/{cv.group(5)}",
                'r2': float(cv.group(6)) if cv.group(6) else None,
            }
    return out

def main():
    pysr_path = sys.argv[1] if len(sys.argv) > 1 else '/tmp/pysr_13.log'
    bee_path = sys.argv[2] if len(sys.argv) > 2 else '/tmp/bee_13.log'

    pysr = parse_pysr_log(pysr_path)
    bee = parse_bee_log(bee_path)

    if not pysr:
        print("PySR-лог не распарсен (ищет '=== JSON ===' + JSON)", file=sys.stderr)
    if not bee:
        print("Bee-лог не распарсен", file=sys.stderr)

    names = list(dict.fromkeys(list(bee.keys()) + list(pysr.keys() if pysr else [])))
    print("| Задача | PySR CV_H (med) | PySR pass | Bee CV_H (med) | Bee pass | Вердикт |")
    print("|---|---|---|---|---|---|")
    for n in names:
        p = pysr.get(n, {}) if pysr else {}
        b = bee.get(n, {})
        p_cv = f"{p.get('cv_holdout_median', '—'):.4f}" if isinstance(p.get('cv_holdout_median'), float) else "—"
        p_pass = f"{p.get('pass_rate_cv<=0.10', '—'):.2f}" if isinstance(p.get('pass_rate_cv<=0.10'), float) else "—"
        b_cv = f"{b.get('cv_med', 9.99):.4f}"
        b_pass = b.get('success', '—')
        verdict = "?"
        if isinstance(p.get('pass_rate_cv<=0.10'), float) and b.get('success'):
            pp = p['pass_rate_cv<=0.10']
            bb = int(b['success'].split('/')[0]) / int(b['success'].split('/')[1])
            if pp >= 0.8 and bb >= 0.8:
                verdict = "PARITY (оба нашли)"
            elif pp < 0.5 and bb < 0.5:
                verdict = "оба отказ/не нашли"
            elif pp >= 0.8 and bb < 0.5:
                verdict = "PySR нашёл, Bee отказ"
            elif pp < 0.5 and bb >= 0.8:
                verdict = "Bee нашёл, PySR нет"
        print(f"| {n} | {p_cv} | {p_pass} | {b_cv} | {b_pass} | {verdict} |")

if __name__ == '__main__':
    main()
