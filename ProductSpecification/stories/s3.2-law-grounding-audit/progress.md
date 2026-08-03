# S3.2 — Law Grounding Audit

## Статус: ⬜ Stage 2 backlog

## Источник

Quigley & Maynard (2025): "On Measuring Grounding and Generalizing Grounding Problems."
5-критериальный аудит символов: подлинность, сохранность, точность, устойчивость, композиционность.

Li (2025): "How the Intrinsic Representation of Artificial Intelligence is Possible."
Самоорганизация онтологии через внутреннюю цель.

Протокол §3.5 уже содержит зачатки grounding audit.

## Что нужно

1. **verify_3_2**: для каждого закона в БД проверить 5 критериев
2. **Grounding score**: числовая метрика 0-1 для каждого закона
3. **/explain grounding**: почему закон считается grounded или нет

## Фазы

### Phase 1: Grounding criteria implementation (2h)
- Подлинность: `origin = 'cv0_discovered'`, не `'manual'`
- Сохранность: `source_path` присутствует в RecordKeeper
- Точность: CV ≤ ε_holdout (уже есть в verify_0_1)
- Устойчивость: cross-domain ≥2 (ожидает Stage 2)
- Композиционность: закон участвует в ≥1 compose/merge атоме

### Phase 2: GroundingScore calculator (1h)
- `GroundingScore = (authenticity + preservation + accuracy + stability + compositionality) / 5`
- Каждый критерий 0/1 → score ∈ [0, 1]
- Законы с score < 0.6 помечаются `WEAKLY_GROUNDED`

### Phase 3: verify_3_2 script (0.5h)
- 100% законов должны иметь `origin = 'cv0_discovered'`
- ≥50% законов должны иметь `grounding_score ≥ 0.6`

## Сложность: ⭐⭐ | 3.5h | Stage 2
