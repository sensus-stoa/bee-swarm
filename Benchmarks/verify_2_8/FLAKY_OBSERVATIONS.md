# FLAKY OBSERVATIONS LOG (watchlist)

Формат: дата · тест · симптом · статус. Принцип: единичный wall-clock фейл
фиксируется и наблюдается; две подряд — открывается расследование (канон
v0.18: wall-clock-класс известен).

## 2026-10-13 · HivePlateauWakeupOscillationTest::testOscillatingTaskCountDoesNotWakePlateau
- Симптом: rc3 fast — "Failed asserting that 1 is greater than 20"
  (осцилляция счётчика задач). Единичное появление: rc2 (11:37) и rc4/rc5
  с тем же кодом — green. Класс теста — wall-clock (CPU-guard/load-зависимый,
  docblock самого класса).
- Статус: WATCHLIST. Не чинить до второго появления подряд.
- forensics: с 13.10 оба пасса пишут junit (`--log-junit`),_lottery-скипы
  и фейлы разбираются по XML, не по текстовым хвостам.
- Связанное: известная worker-death лотерея (1-2 skip при 0 fail,
  forensics: ProductSpecification/stories/m1-ext-trail-memory/
  HANDOFF_M1EXT3_SKIP_FORENSICS.md) — та же watchlist-категория.

## 2026-10-13 · slow-пасс красный с 12.10 (FIXED, не flaky — для истории)
- FullPipelineRegressionTest 0 laws: per-test full-state isolation
  (e5c8138) стирал shared-Hive находки. Фикс adb7aee: opt-out
  skipFullStateIsolation для shared-Hive классов. Урок: пер-тестовая
  изоляция требует исключения для классов с общим прогоном на класс;
  slow-пасс обязателен к перезапуску после любых изменений TestCase.
