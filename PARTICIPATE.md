# Participate

For visitors arriving from Show HN, Habr, or the release page.

## Run the demo (1 command)

Requirements: PHP >= 8.1 with the `pdo_sqlite` extension.

```bash
composer install && php scripts/verify_2_8.php
```

Expected: exit code 0, JSON report with `"verdict": "PASS"`. In-memory DB, no
network. What you just saw: two isomorphic laws from two domains compressed
into one self-named grammar atom, and that atom solving a third domain that
raw search cannot solve at the same depth and beam. A random atom does not
solve it.

## Read the evidence before the claims

- `Benchmarks/verify_2_8/report.json` - the raw measurement (all costs, all
  controls, per-block configuration).
- `Benchmarks/verify_2_8/evidence.json` - the claim, its scope, limitations,
  and the replication status (currently: not independently replicated).
- `Benchmarks/verify_2_8/MANIFEST.md` - frozen hashes of the v1.0.0 state.

The claim is bounded: the acquired operator ENABLES a solution under the
given search constraints. Equal compute cost and universal superiority are
not claimed. `CV 9.99` in rows with `found=false` is a sentinel, not a
measurement.

## Two open research tasks (issues #1 and #2)

1. Independent re-implementation of the section 3.8 measurement from the
   protocol text alone. This is the path from EXPERIMENTALLY_VERIFIED to
   INDEPENDENTLY_REPRODUCED.
2. Adversarial call: find an isomorphism class where compiling two laws into
   one atom is worse than the coalition. Breaking our gate is a published
   result with credit.

## Reporting a bug

Open an issue with: PHP version, OS, exact command, full output. A failing
command beats a description.

## Etiquette

Pre-register before you run. Honest negatives are contributions. If a change
makes the system certify more, show that the null false-positive rate stays
at zero.
