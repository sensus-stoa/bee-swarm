
## MOL-001 ADDENDUM (15.10 вечер): семантический roundtrip починен

Root cause (уточнён, двухслойный):
1. **parse, атом-ветка**: birth-операторный tier отсутствовал — склеенная B-форма (x0BWTOKx1) возвращалась цельным атомом. Fix: extraOps-tier ПОСЛЕ +− и ×÷ тиров (B-применение связывает сильнее) — ~20 строк.
2. **renameTerminals портил слоты** (следствие 1): парсер не видел слоты внутри склеенного атома → (x0−x2) переименовывался в (x0−x1) по рангу появления → канон T₂ кодировал ДРУГУЮ функцию. Post-fix: molecule path (containsBirthOp → render без переименования) срабатывает корректно.
3. c1 в T₂ definition — дефект ХАРНЕССА (жадный BW-protect regex в p1_labels_to_x съедал следующий лейбл), не ядра; фикс = BW[0-9a-f]{32}.

R1-R6: tests/MolRoundtripTest.php — 11/11 GREEN. R5 full suite (ноут, paratest -p8): **OK 1258 тестов, 0 фейлов, 7:58** (бейлайн 7:41, +4% ≈ 11 новых тестов + parse-overhead). Пер-тест тайминги (junit): тяжёлый хвост — pre-existing интеграционные тесты (Monoculture 331s, DeadCleanup 242s, EnsembleWiringLive 241s, Verify28 233s), MolRoundtrip — 5s.

Реальный T₂ (перерождён post-fix): BWcfe6b0cf... = ((x0−x2)+(x0BW_T₁x1)) — чистая БД с T₁+T₂: eval = -3.7 = sdr(1.5,4.5)+(1.5-3.2) ✓ = исходный закон. Dependency-проба: без T₁-строки → null (отличимо от parse-ошибки: дерево строится).

Изменение имён: T₂ сменил имя (старый канон был семантически искажён — замена корректна и задокументирована); T₁ и все ненnested атомы — имена/ Fingerprints НЕИЗМЕННЫ (R6 бейлайн).

Harness-квирки (документированы, не мешают): probe-режим требует Database::reset() после putenv (singleton переживает env-смену); birthOpNames static-кэш с count-sentinel'ом даёт stale списки при совпадении счётчиков между БД (тесты — try/finally + восстановление).
