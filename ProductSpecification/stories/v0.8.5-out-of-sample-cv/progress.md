# V0.8.5 — Out-of-Sample CV (Anti-Tautology Gate)

## Статус: ⬜

## Проблема

Search::find вычисляет CV на ВСЕХ данных. Тавтологии вида `R×x × x/R×x = x`
получают CV=0 и проходят как «законы». В реальных данных (Auto MPG, Wine)
первый «закон» — всегда тавтология с CV=0, скрывающая настоящие находки.

## Что нужно

1. **Split данных**: 80% train / 20% test
2. **Search на train**: найти формулу как обычно
3. **CV на test**: вычислить предсказания на test-части
4. **Gate**: если CV_test > 0.5 → отбросить (тавтология/переобучение)
5. **isTrivial upgrade**: добавить паттерн `R×x × x/R×x` и `x + R×x - R×x`

## Фазы

### Phase 1: Split + test-CV в Search::find (1h)
- `Search::find` получает параметр `$testRatio = 0.2`
- Делит X/y на train/test
- Ищет формулу на train
- Вычисляет CV_test на test
- Возвращает оба: `[$found, $cv_train, $cv_test, $formula]`

### Phase 2: Gate в DiscoveryEngine (0.5h)
- `discover()` отбрасывает кандидатов с `$cv_test > 0.5`
- Тавтологии уходят, реальные законы остаются

### Phase 3: isTrivial upgrade (0.5h)
- Добавить паттерны: `R×x × x/R×x`, `R+x − R+x`, `x × R×x / R×x`
- Проверять на identity: `abs($pred_i − $y_i) < 1e-10` для всех i

### Phase 4: Обновить интеграционные тесты (0.5h)
- Auto MPG: первый закон не должен быть R-тавтологией
- Wine: то же
- Добавить тест что тавтологии НЕ проходят

## Сложность: ⭐⭐ | 2.5h
