# Low-Priority Bugfixes

## L1: Unify CV tolerance (Search vs CvCalculator)

- Search::cv() uses 0.001 as exact-check threshold
- CvCalculator::compute() uses 0.0001 as exact-check threshold
- **Fix:** unify to 0.0001 (CvCalculator's value is stricter = better)
- **Files:** `src/Core/Search.php`, `src/Math/CvCalculator.php`

## L2: Deterministic retrospective validation

- `getTasks()` generates GEN_ tasks with `mt_rand()` → non-deterministic data
- Retrospective validation runs on each daemon restart with different data
- **Fix:** use fixed seed for GEN_ data, or disable GEN_ generation during bootstrap
- **Files:** `src/Hive/Hive.php` — `getTasks()`, `bootstrap()`

## Status

- [ ] L1: Unified CV tolerance
- [ ] L2: Deterministic retrospective
