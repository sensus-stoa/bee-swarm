# Письмо авторам Venturelli 2018 — запрос данных OD600

**Кому:** venturelli@wisc.edu (Ophelia Venturelli, corresponding author)
**Копия:** возможно, Adam Arkin (апбекинг/супервизор — не писать до ответа)
**Тема:** Request: OD600 time-series for the 12-species gut community dataset (MSB 2018)

---

Dear Professor Venturelli,

We are an independent group studying **composition laws in biological systems** — specifically, how pairwise interaction models transfer (or fail to transfer) to multi-species communities. We have been working with the published supplementary datasets of your 2018 Molecular Systems Biology paper *"Deciphering microbial interactions in synthetic human gut microbiome communities"* (EV1–EV4, SBML models).

Our work reproduces your T3 model (correlation ≈ 0.85 on compositional trajectories — independently confirmed from the published SBML parameters), and goes on to test its **one-step prediction capability** on unseen compositions. In this stricter regime we observe a systematic gap that your validation protocol does not isolate: single-step species growth rates in multi-species communities are ~4.5× larger in magnitude than in pairwise cultures, and neither your T3 (R² ≈ 0.02) nor pairwise fits from EV1 (R² ≈ −0.8) predict them. Simple aggregate composition corrections (dominant-partner, summed-neighbour, richness terms) do not close the gap either.

To determine whether this is a genuine regime change in the dynamics (rather than an artefact of working with relative abundances alone), we would greatly benefit from the **OD600 time-series measurements** that accompany the EV1–EV3 relative-abundance data (absolute abundances = relative × OD600). Even a subset (e.g., the 15 EV2 pairs or the 13 EV3 communities) would allow us to test whether single-step growth rates in absolute terms are predictable from pairwise measurements.

Concretely, we would be grateful for:

1. OD600 (or total biomass) time-series for the communities in EV1–EV3, at whatever resolution was measured;
2. (if available) the monoculture growth curves used to estimate the r_i and a_ii parameters;
3. the dilution/transfer protocol constants used in your serial-transfer scheme (we infer 1:20 every 24h from the Methods).

We are happy to share our reproduction code and analysis, and to cite and acknowledge this assistance appropriately in any resulting work. Of course, we will not redistribute the raw data beyond what your license permits.

Thank you for your time.

Best regards,
[имя]
[аффилиация / контакт]

---

*Пометка для нас:* письмо НЕ отправлять из этого окружения — оно должно уйти от имени владельца (ninjacat) с личной почты. Черновик выше — финальный, готов к отправке. Если ответа нет 10–14 дней — переходить к low-rank β_ijk на EV4-функциях (метаболический базис) в one-step постановке EV3.
