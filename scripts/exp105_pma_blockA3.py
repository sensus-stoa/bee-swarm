#!/usr/bin/env python3
"""
B6.5-PMA, блок A.3: исправленный вердикт + time-proxy проверка.

КЛЮЧЕВОЕ ИСПРАВЛЕНИЕ (нашёл при аудите блоков A/A.2):
Вчерашняя формулировка «модуль 24 = ribosome + Bactobolin + Thailandamide»
была ОШИБКОЙ чтения GO-таблицы: GO-категории в Suppl File 3 относятся к
РАЗНЫМ модулям каждой сети, а не к одному. Исправленный разбор:
- Ps-сеть: модуль 24 = ЧИСТО ribosomal (51/637); Bactobolin = модуль 28,
  Thailandamide = 83, flagellum = 2. Purine-гены (9/17) — только в 24.
- Cs-сеть: модуль 40 = malleilactone + 6 purine-генов; ribosome = модуль 310,
  purine-генов в нём НОЛЬ.
Итого: в Ps-контексте ось связана с РИБОСОМОЙ (перевод), в Cs-контексте —
с MALLEILACTONE BGC (вторичный метаболизм). Связи «purine+ribosome+BGC
в одном модуле» НЕТ — была артефактом чтения.

Time-proxy проверка (без counts): если бы коэкспрессия purine↔ribosome
была чистым временным артефактом (общий ростовой тренд), то в Cs-сети
purine-гены должны были бы так же собраться в ribosomal-модуле 310.
Они там отсутствуют (0/14). Вместо этого в Cs-контексте они сидят в
malleilactone-модуле. Контекст-специфичность ассоциации (Ps: ribosome;
Cs: BGC) — аргумент ПРОТИВ чисто временного артефакта: временной тренд
один и тот же в обоих контекстах, а модульная принадлежность оси — разная.

Одновременно это и слабость: без counts нельзя исключить, что «ribosomal
модуль» Ps и «malleilactone модуль» Cs просто отражают разные фазы одного
временного профиля (рост vs стационар) — а purine-гены всегда сидят в
«активно-растущем» блоке. Формальная проверка требует time-conditioned
null на counts (JGI).

Итог блока A: сертификат сужается и уточняется:
  Ps-24: purine↔ribosome (9 генов, Fisher p=9.5e-03, BH q=0.032)
  Cs-40: purine↔malleilactone (6 генов, Fisher p=1.6e-02, BH q=0.032)
  «Единый purine↔ribosome↔BGC закон» — отзывается.
"""
import json

# Финальная сводка блока A (числа из exp103 + исправленный разбор)
summary = {
    'correction': 'GO-категории в Suppl File 3 относятся к разным модулям; '
                  '«ribosome+BGC в одном модуле» было артефактом чтения',
    'Ps_network': {
        'module_24': {'go': 'Structural constituent of ribosome (51/637)',
                      'purine_genes': ['purB','purD','purF','purM','guaA','guaB',
                                       'adk','ndk','pnp'],
                      'fisher_p': 9.47e-03, 'bh_q': 0.032},
        'module_28': {'go': 'Bactobolin', 'purine_genes': ['purL']},
        'module_89': {'go': 'Cytochrome o ubiquinol oxidase',
                      'purine_genes': ['purC','purE']},
    },
    'Cs_network': {
        'module_40': {'go': 'Malleilactone',
                      'purine_genes': ['purD','purF','purL','guaA','guaB','pnp'],
                      'fisher_p': 1.58e-02, 'bh_q': 0.032},
        'module_310': {'go': 'Structural constituent of ribosome',
                       'purine_genes': []},
    },
    'time_proxy': 'контекст-специфичность ассоциации (Ps: ribosome; Cs: BGC) '
                  'при одинаковых временных трендах — аргумент против чисто '
                  'временного артефакта, но не заменяет time-conditioned null '
                  'на counts',
    'verdict': 'сертификат сужен: Ps = purine↔ribosome; Cs = purine↔BGC; '
               'единый «ribosome↔BGC» закон отозван',
}
json.dump(summary, open('/tmp/pma_blockA3_verdict.json', 'w'), indent=1)
print(json.dumps(summary, indent=1, ensure_ascii=False))
