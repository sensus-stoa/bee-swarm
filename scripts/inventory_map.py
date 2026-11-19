#!/usr/bin/env python3
"""Инвентаризация кодовой базы bee_swarm (проход 1 карты B4.5).

Для каждого .php в src/ и scripts/ собирает:
  - докблок-назначение (первые строки комментария)
  - классы и публичные функции
  - размер, mtime
  - последний коммит (дата + тема)
  - число ссылок (require/include/use по базе)
Выход: docs/inventory/INVENTORY.jsonl + docs/inventory/SUMMARY.md
"""
import json
import os
import re
import subprocess
from collections import Counter
from datetime import datetime

ROOT = os.path.expanduser('~/.bee_swarm')
OUT_DIR = os.path.join(ROOT, 'docs', 'inventory')
os.makedirs(OUT_DIR, exist_ok=True)

PHP_DIRS = ['src', 'scripts']
SKIP_DIRS = {'vendor', 'node_modules', '.git', 'Generated'}


def git_log(path: str) -> tuple[str, str]:
    try:
        out = subprocess.run(
            ['git', 'log', '-1', '--format=%ad %s', '--date=short', '--', path],
            capture_output=True, text=True, cwd=ROOT, timeout=10,
        ).stdout.strip()
        if out:
            date, _, subject = out.partition(' ')
            return date, subject
    except Exception:
        pass
    return '', '(не в git)'


def references_for(basename: str) -> list[str]:
    """Кто ссылается на файл (require/include/использование класса)."""
    if not basename.endswith('.php'):
        return []
    refs = subprocess.run(
        ['grep', '-rl', '--include=*.php', basename, 'src', 'scripts', 'tests'],
        capture_output=True, text=True, cwd=ROOT, timeout=60,
    ).stdout.split()
    # исключаем сам файл
    stem = os.path.splitext(basename)[0]
    cls = ''.join(w.capitalize() for w in stem.split('_'))
    class_refs = []
    if cls and len(cls) > 3:
        try:
            class_refs = subprocess.run(
                ['grep', '-rl', '--include=*.php', rf'\b{cls}\b', 'src', 'scripts', 'tests'],
                capture_output=True, text=True, cwd=ROOT, timeout=60,
            ).stdout.split()
        except Exception:
            pass
    return sorted(set(refs) | set(class_refs))


def docblock(text: str) -> str:
    """Назначение: первый блоковый комментарий или doc-строки."""
    m = re.search(r'/\*\*(.*?)\*/', text, re.S)
    if m:
        lines = [re.sub(r'^\s*\*\s?', '', ln).strip() for ln in m.group(1).splitlines()]
        lines = [ln for ln in lines if ln and not ln.startswith('@')]
        if lines:
            return ' '.join(lines)[:220]
    m = re.search(r'^//\s*(.+)$', text, re.M)
    if m:
        return m.group(1).strip()[:220]
    return ''


def decls(text: str) -> tuple[list[str], list[str]]:
    classes = re.findall(r'^(?:abstract\s+|final\s+)?class\s+(\w+)', text, re.M)
    funcs = re.findall(r'^\s*(?:public|private|protected)?\s*(?:static\s+)?function\s+(\w+)', text, re.M)
    return classes, [f for f in funcs if not f.startswith('_')][:12]


entries = []
for base in PHP_DIRS:
    for dirpath, dirnames, filenames in os.walk(os.path.join(ROOT, base)):
        dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS]
        for fn in filenames:
            if not fn.endswith('.php'):
                continue
            path = os.path.join(dirpath, fn)
            rel = os.path.relpath(path, ROOT)
            try:
                text = open(path, encoding='utf-8', errors='replace').read()
            except Exception:
                continue
            classes, funcs = decls(text)
            gdate, gsubj = git_log(rel)
            entries.append({
                'path': rel,
                'purpose': docblock(text),
                'classes': classes,
                'functions': funcs,
                'size': os.path.getsize(path),
                'mtime': datetime.fromtimestamp(os.path.getmtime(path)).strftime('%Y-%m-%d'),
                'last_commit': gdate,
                'commit_subject': gsubj[:100],
                'refs': len(references_for(fn)),
            })

with open(os.path.join(OUT_DIR, 'INVENTORY.jsonl'), 'w') as f:
    for e in entries:
        f.write(json.dumps(e, ensure_ascii=False) + '\n')

# сводка
by_dir = Counter('/'.join(e['path'].split('/')[:-1]) for e in entries)
lines = ['# Инвентарь bee_swarm (проход 1)', '',
         f'Всего PHP-файлов: {len(entries)}', '', '## По каталогам', '']
for d, c in sorted(by_dir.items()):
    lines.append(f'- {d}: {c}')
lines += ['', '## Кандидаты на мусор (нет ссылок, нет классов, старый коммит)', '']
for e in sorted(entries, key=lambda x: x['path']):
    if e['refs'] == 0 and not e['classes'] and e['last_commit'] < '2026-09':
        lines.append(f"- {e['path']} ({e['last_commit']}, {e['size']//1024}KB): {e['commit_subject']}")
lines += ['', '## Свежие файлы (последний месяц)', '']
for e in sorted(entries, key=lambda x: x['last_commit'], reverse=True)[:40]:
    if e['last_commit'] >= '2026-10-17':
        lines.append(f"- {e['path']}: {e['commit_subject']}")
with open(os.path.join(OUT_DIR, 'SUMMARY.md'), 'w') as f:
    f.write('\n'.join(lines) + '\n')

print(f'проинвентаризировано: {len(entries)} файлов')
print('каталог:', os.path.join(OUT_DIR, 'INVENTORY.jsonl'))
print('сводка:', os.path.join(OUT_DIR, 'SUMMARY.md'))
