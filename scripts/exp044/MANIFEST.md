# EXP-044 FREEZE MANIFEST v1 (2026-10-13 22:44 MSK)
Запечатано до фазы C. После freeze правки = только erratum (прецедент verify-2-8).

code_commit: 1ea5a14212004259d448df85a9029fda53d7e663
families_json: scripts/exp044/exp044_families.json (train D1-D8 seeds 800-807; test H1-H6 seeds = md5('EXP044H'+id), запечатаны, в фазе P не использовались)
arms (зафиксированы): warm / warm_noculture / cold / shuffled / fixed
  fixed = NO_BIRTH=1 + TRAIL_MARKS off; остальные — механизмы включены
  deviation D1 (задокументировано): FIXED не отсекает слоты (нет env-гейта; правка ядра после freeze хуже отклонения) — слоты общие для всех рук, COLD-vs-FIXED изолирует birth+trail
criterion: Search::find(testRatio=0.4 held-out, cvTrainMax=0.10, A19 baseline-relative в HEAD)
budget (deviation D2): wall-clock кап 90s/семья-задача на ВСЕ вылеты (драйверный deadline), TRAIN учтён отдельно (60s/семья); evals/energy = N/A в Search-direct режиме (экономика существует только в Hive-режиме, отбракован пилотом) — зафиксировано отклонением от буквы prereg v2.2
flights: ≤3 на семью, stop on found
culture provenance: только автоматическая (driver-level birth найденного закона TRAIN → canon → B-атом; diff-слово рождается из D1/D2 purediff; ручные инъекции отсутствуют — smoke warm s1: 3 атома родились, файл culture_warm_s1.json)
ablation P4 (DEV, мульти-флайт): neither FAIL / trail_only FAIL / op_only FAIL / both SOLVED (вылет 2, cv=0) — механизм строго конъюнктивный

sha256:
  scripts/exp044/driver.php            d5637f59c7c21b0e2a81d6e82bea59d520e8af9802090a7bfc66582848ffa05e
  scripts/exp044/gen_families.php      caa9b5f01df2b6cbfc6f28b86b8ee2c41b119168e6658f14cac06e44dfb15353
  scripts/exp044/exp044_families.json  5280eb6096a5a018c9da2ec8fc05639a2a314ca99bc5ce8554a6fc2943b2f55e
