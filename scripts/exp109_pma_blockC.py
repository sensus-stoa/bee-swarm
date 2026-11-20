#!/usr/bin/env python3
"""
B6.5-PMA блок C: панель подтверждённых идентификаций пуриновых метаболитов.
Цель: из 55 purine m/z-фич (m/z-разметка 5 mDa) выделить те, у кого есть
ИМЕНОВАННАЯ идентификация (Supplementary File 2 — MS identifications с
молекулярными видами и PPM), а не только масса. Для стандарто-подтверждённых —
отметить. Проверяем направление ΔABC.
"""
import json, csv, os, statistics, math
from collections import defaultdict

BASE = '/home/ninjacat/.bee_swarm/Benchmarks/chodkowski'
ct = json.load(open(f'{BASE}/challenge_table.json'))
# ident в challenge_table
with_ident = [(rec['feat'], rec['ident'], rec['mz']) for rec in ct if rec.get('ident')]
print('фич с ident в challenge_table:', len(with_ident))
for f, i, mz in with_ident[:20]:
    print(f'  {f[:30]:30.30} mz={mz} ident={i[:50]}')

# Suppl File 2 — MS identifications
import openpyxl
wb2 = openpyxl.load_workbook(f'{BASE}/Supplementary_Files/Supplementary File 2.xlsx', read_only=True)
ws = wb2[wb2.sheetnames[0]]
print('\n=== Supplementary File 2 (MS identifications) ===')
rows = list(ws.iter_rows(values_only=True))
print('строк:', len(rows))
for r in rows[:8]:
    print('  ', [str(v)[:28] for v in r[:7] if v is not None])
