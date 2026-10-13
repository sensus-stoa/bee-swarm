# EXP-044 FREEZE MANIFEST v3 (2026-10-14 00:42 MSK, финальный)
v3 = единственный действующий манифест (v2 сохранён как MANIFEST_v2.md; prereg v2.2 и amendment v2.3 неизменны).
code_commit: 6a5a928
driver: v3 (все 7 fixes ревью + условия owner: полный strip warm_noculture, SHUFFLED переносит метки с asserts, birth только после cv_eval, frozen null-контроли)
DEV-smoke matrix v3 (seed 1): 5 рук завершены без фаталов; FPR=0 у всех (24 null-задачи... 4 null-задачи × 5 рук = 20, 0 сертификаций); asserts SHUFFLED green (объём совпадает, определения различаются); warm_noculture state после strip = {laws:0, birth:0, marks:0} (COLD-эквивалентность).
Наблюдение (не баг): warm 1/2 vs fixed 2/2 на raw-разрешимых семьях — beam-вариативность с культурой; фаза C на 6 сидах измерит распределение.

sha256:
  scripts/exp044/driver.php            349efe88203d3eec84baf510e388edbaf6dfbe1d19ade91ba4b72d6570bcd8ce
  scripts/exp044/gen_families.php      6b317e5aa4d464ec4e0e5049f7bf9dfe7b16d94d8a91252624c6a4545bfb4a50
  scripts/exp044/exp044_families.json  b8353016b4679c0676b3d4437d8d173fd75a570cc73ced1d59fb77be31e8fb3c
  scripts/exp044/MANIFEST_v2.md        f7dcadb087e4be265839314eb3ee4aa1b7aef4e8346aa425d0fb1a17b0314c94

Phase C (после OK owner): 5 рук × 6 seeds × (H1-H6 + 12 nulls) на runner-ноуте, ≈6-9h; отчёт = per-family + nulls + FPR + t_first_found + ресурсы.
