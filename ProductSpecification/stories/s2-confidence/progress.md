# Story S2-CONFIDENCE: Semantic Confidence Tracking Fix

> Анализ CUBE_CV0_SEMANTIC.md: 405 фактов, все confidence=1.0, ~35% шума. Механизм сломан.

## Диагноз

```
Сейчас:  confidence = min(1.0, 0.3 + count × 0.15)
         = счётчик повторных обнаружений
         = частота цитирования, не достоверность

Проблемы:
- Нет contradiction penalty (−0.4 за опровержение)
- Нет ageing (факт 100-дневной давности = свежий)
- Нет source_independence (3 копии текста = 3 источника)
- ~35% фактов — откровенный шум («Авито is_a десятки»)
```

## Fix: непрерывный confidence

```php
confidence = sigmoid(
    +0.5 × independent_source_count
    −0.1 × age_days  
    −0.8 × contradiction_count
    +0.3 × cv_heldout_quality  // если факт связан с числовым законом
    −0.5 × is_stop_word_penalty
)
```

sigmoid гарантирует [0,1], непрерывность, дифференцируемость.

## Phases

### Phase 1: Contradiction detection
- [ ] RED: test_contradiction_reduces_confidence — противоречащий факт → −0.4
- [ ] GREEN: SemanticFactInserter::contradict(s,p,o)

### Phase 2: Source independence
- [ ] RED: test_duplicate_source_not_independent — копия текста ≠ второй источник
- [ ] GREEN: cosine similarity контекстных окон → dependent если >0.9

### Phase 3: Age tracking
- [ ] RED: test_old_fact_has_lower_confidence — возраст 30 дней → penalty
- [ ] GREEN: age_days = now − last_confirmation_date

### Phase 4: Непрерывный confidence
- [ ] RED: test_confidence_is_continuous — не дискретный счётчик
- [ ] GREEN: sigmoid-формула вместо `min(1.0, 0.3 + n×0.15)`

## Статус
⬜ Backlog — зависит от: S1-WIRE (нужна работающая популяция для накопления истории)
