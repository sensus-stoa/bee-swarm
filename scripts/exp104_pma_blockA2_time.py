#!/usr/bin/env python3
"""
B6.5-PMA, блок A.2: time-conditioned null.
Проверка: выживает ли enrichment модуля 24/40, если убрать временную
компоненту коэкспрессии. У нас нет counts, но у сети есть свойство:
авторы строили сеть на 23-24 образцах с сильным временно́м трендом.
Прокси-тест на доступных данных: в модуле 24 рибосомальные гены и гены
жгутика имеют ОБЩИЙ временной профиль (рост -> стационар). Если purine-гены
попадают в модуль только за счёт общего временного тренда (rRNA-дозировка
при росте), то в модуле будет избыток И ribosomal, И flagellar, И ribosomal-
связанных (translation), т.е. модуль гетерогенен по функции, но согласован
по времени. Проверяем: доля ribosomal/flagellar/translation-генов в
модулях 24/40 vs весь геном (по GO-аннотациям сетей авторов), и —
ключевое — наличие в модуле 24 генов с ПРОТИВОПОЛОЖНЫМ временным поведением
(Bactobolin/Thailandamide BGC — вторичный метаболизм стационарной фазы,
антикоррелирует с ribosome при переходе в стационар).

Если BGC-гены и ribosomal-гены в ОДНОМ модуле — простое временное объяснение
НЕ работает (их профили противофазны), значит коэкспрессия функциональная.
"""
import openpyxl, re

BASE = '/home/ninjacat/.bee_swarm/Benchmarks/chodkowski/Supplementary_Files'
wb3 = openpyxl.load_workbook(f'{BASE}/Supplementary File 3.xlsx', read_only=True)
wb4 = openpyxl.load_workbook(f'{BASE}/Supplementary File 4.xlsx', read_only=True)

def locus2module(sheet):
    ws = wb3[sheet]
    d = {}
    for r in ws.iter_rows(values_only=True, min_row=2):
        if r and r[0] and r[1] is not None:
            d[str(r[0])] = str(r[1])
    return d

bt_ps = locus2module('Bt_genesFromBtPsNetwork')
bt_cs = locus2module('Bt_genesFromBtCsNetwork')

# GO-категории модулей (File 3, sheets modulesGOenrich) — перечислим все строки
# с категориями и модулями; ищем противофазные категории в одном модуле.
for sh in ['Bt_modulesGOenrich_BtPsnetwork', 'Bt_modulesGOenrich_BtCsnetwork']:
    ws = wb3[sh]
    print(f'=== {sh} ===')
    current_mod = None
    for r in ws.iter_rows(values_only=True):
        if not r or r[0] is None:
            continue
        v0 = str(r[0])
        if v0.isdigit():
            current_mod = v0
        elif v0 == 'GO enrichment category':
            continue
        else:
            # строка категории
            print(f"  модуль {current_mod}: {str(r[0])[:55]:55.55} p={r[2] if len(r)>2 else '?'}")
    print()

