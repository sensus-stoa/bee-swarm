# EXP-044 FREEZE MANIFEST v2 (2026-10-13, post-review repair)
Supersedes v1. code_commit: b463bd0 (driver v2: все 7 fixes ревью; см. EXP044_FREEZE_READINESS.md)

arms: warm / warm_noculture / cold / shuffled / fixed (fixed = NO_BIRTH + trail off; deviation D1: slots не гейтятся)
criterion: certification = found AND cv_eval <= 0.10 на withheld 40% (rows, не видевшие движок); engine cv = selection only
budget: wall 90s/семья TEST (все вылеты), 60s/семья TRAIN, <=3 вылета; evals/energy N/A (Search-direct, deviation D2)
culture provenance: автоматическая (record law -> LawIsomorphismCompressor::compress -> B-атом); ручных инъекций нет
ablation P4 (DEV, multi-flight): neither FAIL / trail_only FAIL / op_only FAIL / both SOLVED (flight 2, cv=0)
smoke matrix (DEV, seed 1, 5 arms): все 5 рук отработали без фаталов; результаты в /tmp/exp044_out_s/

sha256:
  scripts/exp044/driver.php            f7c03b81fc357b90655d66cc5c8e90b2302d47e7c1a6b1df42b5904f3b18b8a1
  scripts/exp044/gen_families.php      caa9b5f01df2b6cbfc6f28b86b8ee2c41b119168e6658f14cac06e44dfb15353
  scripts/exp044/exp044_families.json  5280eb6096a5a018c9da2ec8fc05639a2a314ca99bc5ce8554a6fc2943b2f55e
