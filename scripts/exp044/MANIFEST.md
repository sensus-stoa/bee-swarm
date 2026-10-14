# EXP-044 FREEZE MANIFEST v4 (2026-10-14 01:20 MSK, final semantic gate PASSED)
v4 = действующий манифест (v3 -> MANIFEST_v3.md сохранён бы при следующем изменении; v2 в MANIFEST_v2.md).
code_commit: c04af1f (driver v4; коммит манифеста — df67228/последующие docs)
driver: v4 (final gate fixes: run_task передаёт $trail — FIXED реально NO_TRAIL с assert 0 меток; WARM экспортирует ВСЕ trail marks rows; SHUFFLED импортирует их с asserts non-empty/count/content-identical)
smoke v4 (DEV, seed 1): warm 1/2 fpr0 / shuffled 1/2 fpr0 (16 меток WARM перенесено, content identical, атомы различаются) / fixed 2/2 fpr0 no-marks-assert TRUE

sha256:
  scripts/exp044/driver.php            be40761ea71646ed8e2f3ea0608fdc5ba39922ca17ed06f1cf07b2298adbc0e5
  scripts/exp044/gen_families.php      6b317e5aa4d464ec4e0e5049f7bf9dfe7b16d94d8a91252624c6a4545bfb4a50
  scripts/exp044/exp044_families.json  b8353016b4679c0676b3d4437d8d173fd75a570cc73ced1d59fb77be31e8fb3c

Phase C (authorised by final gate): 5 arms x 6 seeds x (H1-H6 + 12 nulls), runner laptop, detached.
Hypothesis/criteria: UNCHANGED from prereg v2.2 + amendment v2.3.
