# Release notes: verify-2-8-v1.0.0

## What this release demonstrates

Language emergence, formalized as a measurement (protocol section 3.8):

1. The compressor distills isomorphic laws from two synthetic domains into
   one self-named grammar atom (a "word": the max-minus-min isomorph of the
   heat/diffusion molecule).
2. A third domain (5 columns) is unsolvable by raw search at BOTH the
   original configuration (depth 2) and the matched configuration
   (depth 3, same beam): cv = 9.99, the engine's NOT_FOUND sentinel.
3. With the born atom in the grammar, the same search configuration solves
   the third domain exactly (cv = 0), and the found formula contains the
   atom (reuse gate).
4. A random arity-2 atom does not solve the third domain: the effect is
   atom-specific, not extra-capacity.
5. Compilation is not worse than the coalition of source laws on held-out
   slices (criterion 3.8(g), v1.6 mean form).

## The claim, precisely

The acquired operator enabled a solution under the given search constraints.
Equal compute cost is NOT claimed (the with-atom run is slower: 6.1 s vs
2.1 s raw-matched; atom-birth cost is timed separately, 0.001 s bootstrap
mint) and universal superiority is NOT claimed. The demonstration is a
self-contained synthetic fixture, not a production-daemon observation; the
reuse record inside it is written via the same public contract the live
daemon uses, invoked by the fixture.

## Reproduce

```bash
composer install
php scripts/verify_2_8.php
```

Exit 0 and verdict PASS on a clean clone (verified: fresh clone, composer
install, PHP 8.2). In-memory DB only; the run refuses persistent databases
unless an opt-in env flag is set. Committed artifacts:
`Benchmarks/verify_2_8/report.json` and `evidence.json` (hashes in the
evidence file).

## Known limitations

- Synthetic fixture domains only (heat/diffusion-class molecule).
- Single-author system: no independent replication yet. An independent
  re-implementation of the section 3.8 measurement is explicitly invited
  (see CONTRIBUTING).
- CV 9.99 in rows with found=false is the NOT_FOUND sentinel, not a
  measured coefficient of variation.
- The certification criterion is baseline-relative as of A19 (EXP-040
  diagnoses); empirical-data comparisons listed in the README carry their
  own validity notes.

## Verification

Full two-pass suite on the dedicated runner machine: 1226 tests,
0 failures (fast 1217 parallel + slow 9 serial). Targeted LanguageVerify28 suite:
8/8 tests, 35 assertions.

## References

- Protocol (CV>0 Autonomous Evolution Protocol, latest version): https://doi.org/10.5281/zenodo.21810055
- This release on Zenodo (all versions, including Erratum 1): https://doi.org/10.5281/zenodo.23338479
