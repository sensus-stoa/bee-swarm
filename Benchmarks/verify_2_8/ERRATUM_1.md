# Erratum 1 (2026-10-13, archive version 1.0.1)

Scope: documentation only. No frozen results, numbers, or measured values
were changed.

## What changed

1. `evidence.json`:
   - suite note corrected: "full 1210-test suite" -> "full two-pass suite
     1226 tests GREEN (rc6: fast 1217 + slow 9, invariant 1226/1226)";
   - resources note corrected: peak memory WAS recorded after all
     (`meta.peak_mem_bytes`, ~286 MB) - the "not yet recorded" note was stale.
2. `RELEASE_NOTES.md`:
   - title: "(release candidate)" wording removed (the release was published);
   - test count corrected: 1224 -> 1226 (fast 1217 + slow 9).

## What did NOT change

- `report.json`: untouched (sha256 276909a0... in MANIFEST).
- `MANIFEST.md`: kept verbatim as the v1.0.0 freeze record; its hashes refer
  to the v1.0.0 state of the four files listed there.
- All measured numbers, the bounded claim, and the verdict (PASS).

## Reason

Found in post-release review: documentation files were drafted before the
final gate (+2 tests, peak-RAM recording, release wording) and were not
re-synced at publish time.
