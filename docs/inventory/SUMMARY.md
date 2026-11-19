# Инвентарь bee_swarm (проход 1)

Всего PHP-файлов: 583

## По каталогам

- scripts: 67
- scripts/archive: 15
- scripts/archive/bench: 1
- scripts/archive/exp045: 2
- scripts/bench: 2
- scripts/exp044: 2
- scripts/exp045: 26
- scripts/genome001: 5
- scripts/genome002: 3
- scripts/probe0: 3
- scripts/probe1: 4
- scripts/verify: 20
- scripts/word_cum001: 6
- src: 1
- src/Certification: 4
- src/Core: 23
- src/Forager: 6
- src/Hive: 44
- src/Infra: 6
- src/Knowledge: 1
- src/Math: 1
- src/Text: 2
- src/Trading: 2
- src/Validation: 4
- tests: 333

## Кандидаты на мусор (нет ссылок, нет классов, старый коммит)

- scripts/archive/bench/exp037_freeze_vs_explore.php (, 7KB): (не в git)
- scripts/archive/exp033_grounded.php (, 13KB): (не в git)
- scripts/archive/exp035_phase3.php (, 3KB): (не в git)
- scripts/archive/exp035_phase3b.php (, 3KB): (не в git)
- scripts/archive/exp035_phase4_heat.php (, 7KB): (не в git)
- scripts/archive/exp045/probe_eval_check.php (, 3KB): (не в git)
- scripts/archive/probe_a19_gate_live.php (, 2KB): (не в git)
- scripts/archive/v14_dashboard_smoke.php (, 1KB): (не в git)
- scripts/baseline_random.php (2026-08-08, 8KB): security: baseline_random.php (lair-пути) вне репо
- scripts/diagnose_all_classes.php (2026-08-08, 10KB): security: P0/P1 аудит — удалены внутренние файлы (110), deploy-скрипты с IP, синтетика fixtures, san
- scripts/diagnose_metrics.php (2026-08-08, 8KB): security: P0/P1 аудит — удалены внутренние файлы (110), deploy-скрипты с IP, синтетика fixtures, san
- src/Validation/ValidatorInterface.php (2026-07-06, 0KB): feat: D4 — phpcs PSR-12 + Clean Code rules, 12 errors remain (complexity, Stage 0 OK)

## Свежие файлы (последний месяц)

- scripts/exp067_probe.php: B4.1: cert_v2-гейт в пробнике — tolerance-сертификация топ-кандидата
- src/Core/Search.php: fix: lookahead ONLY depth>=3 — material-каналы дороги не трогаем
- scripts/exp066_telescope.php: green: L2-BEAM assembly-potential (SEARCH_L2_LOOKAHEAD=1) — кирпичи выживают, глубина-3 собирается
- scripts/exp065_b2_pilot.php: green: нишевая квота записи/чтения (TRAIL_ADMIT_NICHE=1) — дорога несёт всех доноров
- scripts/exp066_feynman.php: EXP-066 B3.2: дискриминирующая рука не найдена в 3 пробах — зато локализован разрез конвейера
- tests/L2StairQuotaTest.php: green: L2-BEAM assembly-potential (SEARCH_L2_LOOKAHEAD=1) — кирпичи выживают, глубина-3 собирается
- tests/DiscoveryDepth3Test.php: test: DiscoveryDepth3 — ассерт перестановочно-инвариантен (канон «ассерт не уже механизма»)
- tests/TrailNicheQuotaTest.php: green: нишевая квота записи/чтения (TRAIL_ADMIT_NICHE=1) — дорога несёт всех доноров
- tests/RouteExecutorWriteOnTest.php: test: READ_K=0 честный ноль — контракт чтения напрямую (read_injected пуст)
- src/Hive/driver.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- src/Hive/BudgetAccountant.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- src/Hive/RouteMutator.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- src/Hive/TransferStats.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- src/Hive/RouteExecutor.php: psalm: 0 errors — 9 расхождений тип-аннотаций с фактической телеметрией закрыты
- src/Hive/SensorState.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- src/Hive/RouteGenome.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- src/Hive/RouteLedger.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- src/Infra/Database.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/bd_seed_sweep.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/bd_triage.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/probe_autodecomp.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/h2lite_pilot.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/gate_scan.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/probe_spurious_v2.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/prec_test.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/exp060_test1_forced_merge.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/exp058_pilot.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/exp055_smoke.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/exp056_probe.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/exp054_templates.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/probe_c_decompose.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/exp056_pilot.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/probe_islands.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/exp060_pilot.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/v14_wu5_ccpp_debug.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/v14_wu5_concrete_debug.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/v14_wu5_debug3.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/boundary_probe.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/probe_shape_sweep.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
- scripts/bd_triage3.php: Trail admission: cv-argmin + sq-factor, read diversity; exp060-062 пилоты; retraction-verify 1430/14
