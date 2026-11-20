#!/usr/bin/env python3
"""
B0-2: покрытие нижних порядков для троек Kuzmin 2018.
Тройка = query-пара (a,b) + array-ген c.
Требуемые нижние порядки: fitness a, b, c; пары a+b, a+c, b+c.
Источники: S1 (fitness W_ab, W_c, W_abc, tau), S4 (fitness стандарт для
query single/double), S5 (диагностическая панель: Strain ID→ORF/Gene),
Costanzo 2016 S2 матрицы (пары a+c, b+c; штамм-имена sn/dma→ORF).
"""
import csv, openpyxl, re, statistics
from collections import defaultdict, Counter

# --- S5: array strain → ORF/Gene/Allele ---
wb = openpyxl.load_workbook('/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/AdditionalDataS5.xlsx', read_only=True)
ws = wb['DiagnosticArray']
array_map = {}   # allele name → (orf, gene)
for r in ws.iter_rows(min_row=2, values_only=True):
    if not r or not r[0]:
        continue
    sid, orf, gene, allele = r[0], r[1], r[2], r[3]
    array_map[allele] = (orf, gene)
print(f'S5: {len(array_map)} array-штаммов с ORF')

# --- S4: fitness стандарт query single/double ---
wb4 = openpyxl.load_workbook('/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/AdditionalDataS4.xlsx', read_only=True)
print('S4 листы:', wb4.sheetnames)
ws4 = wb4[wb4.sheetnames[0]]
s4_rows = 0
query_fit = {}   # (query strain id) → dict
for i, r in enumerate(ws4.iter_rows(values_only=True)):
    if i == 0:
        hdr4 = [str(v) for v in r]
        print('S4 шапка:', hdr4[:10])
        continue
    if r and r[0]:
        s4_rows += 1
print(f'S4 строк данных: {s4_rows}')

# --- Costanzo матрицы: узлы (штаммы) → ORF ---
# atr: NODExX ARRYxxxx ARRYxxxx corr — node → two strain barcodes; для маппинга
# нужен файл соответствия штамм→ген. Проще: cdt-файл имеет колонки ORF/Name.
# Заголовки колонок cdt = штамм-имена array (dma.../sn...). Строки = query штаммы.
# Для пар (a,c)/(b,c) нужно: пары генов в матрицах есть между строками и колонками.
# Читаем cdt: первая колонка GID, вторая Strain, третья ORF, четвёртая Name.
nxn = '/home/ninjacat/.bee_swarm/Benchmarks/kuzmin2018/costanzo_matrix/Data File S2. Raw genetic interaction datasets: Matrix format/SGA_NxN_clustered.cdt'
row_orf = {}
with open(nxn) as f:
    hdr = f.readline().rstrip().split('\t')
    col_strains = hdr[6:]   # имена штаммов-колонок (array)
    for ln in f:
        p = ln.rstrip().split('\t')
        if len(p) > 3:
            row_orf[p[1]] = (p[2], p[3])   # strain → (ORF, gene name)
print(f'NxN: строк-штаммов с ORF: {len(row_orf)}, колонок: {len(col_strains)}')
# колонки: dma/sn имена → маппим через .gtr/.atr? Попробуем: колонки и строки —
# одни и те же штаммы; ORF колонок получим из atr
atr = nxn.replace('.cdt', '.atr')
col_orf = {}
with open(atr) as f:
    f.readline()
    for ln in f:
        p = ln.rstrip().split('\t')
        if len(p) > 2:
            col_orf[p[0]] = p[1]  # NODE id → ...? проверим формат
print('atr пример:', list(col_orf.items())[:2])
