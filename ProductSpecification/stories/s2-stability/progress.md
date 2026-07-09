# Story S2-STABILITY: Semantic Stability Law через Log-Loss

> Протокол §3.5: CV→0 над историей семантических фактов → закон стабильности.
> Анализ CUBE_CV0_SEMANTIC.md: CV→0 математически невозможен на бинарном target.
> Решение: log-loss для бинарной классификации + CV→0 для непрерывного confidence.

## Почему log-loss а не CV

```
CV = σ(ratio)/|μ(ratio)|  — требует непрерывный target y_i > 0
                             
Бинарный target (0/1):      CV→∞ для любого константного предиктора
                             
Log-loss:                   −[y·log(p) + (1−y)·log(1−p)]
                             y ∈ {0,1}, p ∈ (0,1)
                             Математически корректен для бинарной классификации
```

Log-loss НЕ заменяет CV→0. Это другой критерий на другом уровне (separation in STRUCTURE):
- **Уровень фактов:** log-loss для бинарной оценки «факт стабилен?»
- **Уровень мета-данных:** CV→0 для непрерывного confidence «насколько стабилен?»

## Спецификация

```php
StabilityLawDiscoverer::discover(array $factHistory): array
// Вход: история фактов [{source_count, independent_count, age_days, 
//         contradiction_count, cv_heldout, predictive_superiority, stable}]
// Выход: stability law с log-loss < threshold

// Признаки (X):
// - source_count: сколько раз факт обнаружен
// - independent_count: сколько НЕЗАВИСИМЫХ источников
// - age_days: дней с последнего подтверждения
// - contradiction_count: сколько опровержений
// - cv_heldout: качество связанного числового закона (если есть)
// - predictive_superiority: CV_H(альтернативы) / CV_H(данного)

// Цель (y):
// - stable: 1 если факт не опровергнут за 30 дней, 0 если опровергнут
```

## Phases

### Phase 1: Log-loss fitness function
- [ ] RED: test_logloss_binary — log-loss([1,0,1], [0.9,0.1,0.8]) < log-loss([1,0,1], [0.5,0.5,0.5])
- [ ] GREEN: LogLossEvaluator с порогом значимости

### Phase 2: Stability data preparation
- [ ] RED: test_stability_features_from_history — факт с 3 confirmations, 1 contradiction, age=5 → вектор признаков
- [ ] GREEN: FactHistoryCompiler::compile Features(since timestamp)

### Phase 3: Stability law discovery
- [ ] RED: test_discover_stability_law — 47+ фактов → закон с log-loss < 0.3
- [ ] GREEN: StabilityLawDiscoverer использует Search::find с log-loss

### Phase 4: /explain endpoint
- [ ] RED: test_explain_semantic_fact — возвращает stability_law с полями formula, cv_heldout, predicted_stability
- [ ] GREEN: ExplainEndpoint с JSON-ответом по спецификации протокола

## Метрики
- verify_2_5: stability law с log-loss < 0.3 (или CV < 0.10 на непрерывном confidence)
- 47+ фактов для обучения
- /explain для 3 случайных фактов содержит stability_law

## Статус
⬜ Backlog — зависит от: S2-CONFIDENCE, S1-WIRE
