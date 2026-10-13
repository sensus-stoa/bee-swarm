# RELEASE FREEZE MANIFEST — verify-2-8-v1.0.0
Frozen: 2026-10-13 13:04 MSK (final gate, owner-approved)

code_commit: 1bf87c384eaaef34d119ce561d11fd6cd317d375
verifier: php scripts/verify_2_8.php -> exit 0, verdict PASS (gates a&b&v&g&c)
clean_clone_repro at final HEAD: fresh clone + composer install -> exit 0 PASS (16 s)
suite: TWO-PASS GREEN on runner laptop (rc6: fast 1217/43510 OK, slow 9 OK, invariant 1226/1226)
isolation check: LanguageVerify28Test + FullPipelineRegressionTest in ONE process -> OK (13 tests, 42 assertions)
flaky watchlist: Benchmarks/verify_2_8/FLAKY_OBSERVATIONS.md (rc3 plateau single fail; slow-pass history fixed in adb7aee)

sha256:
  report.json       276909a0ee5fe7f9f839bf4f2f91a27597f313ec60623c6e2607ddbcdcc2f4ea
  evidence.json     f52a5c23f33a4a9039a002ad24c60807d2e6e06dbcc2be9771a2c1c90d368301
  RELEASE_NOTES.md  f418b9c3ed5f3296d7b84cbd108ecf01e57097981592906488ee7cd12345324f
  README.md         4130a9f7e92b7e0592f7d8242c72e0f20c65057cd55b035996e59a5ead9c80ba
  FLAKY_OBSERVATIONS.md 750deef94e5a9e1773cf1ec7f2a8a3a54317fd133f6de602b5f5d65f283ce343

claim (bounded): the acquired operator enabled a solution under the given
search constraints (raw fails at depth-2/original AND depth-3/matched-beam;
with-atom solves exact; random-atom control does not solve). Equal compute
cost NOT claimed (with-atom 6.1 s vs raw-matched 2.1 s; birth 0.001 s).
Universal superiority NOT claimed. CV 9.99 = NOT_FOUND sentinel. Synthetic
fixture; not independently replicated.
