# RELEASE FREEZE MANIFEST — verify-2-8-v1.0.0 (candidate)
Frozen: 2026-10-13 12:21 MSK

code_commit: adb7aeeecedfe02bf0f2da965909ddcd50ec9daa
suite: TWO-PASS GREEN on runner laptop (fast 1217 tests / 43510 assertions OK; slow 9 tests OK; invariant 1226/1226); log: /tmp/v017_rc5 on runner
clean_clone_repro: fresh clone + composer install + php scripts/verify_2_8.php -> exit 0, verdict PASS (22 s)
verifier: php scripts/verify_2_8.php -> verdict PASS (gates a&b&v&g&c)

sha256:
  report.json       276909a0ee5fe7f9f839bf4f2f91a27597f313ec60623c6e2607ddbcdcc2f4ea
  evidence.json     f52a5c23f33a4a9039a002ad24c60807d2e6e06dbcc2be9771a2c1c90d368301
  RELEASE_NOTES.md  f418b9c3ed5f3296d7b84cbd108ecf01e57097981592906488ee7cd12345324f
  README.md         4130a9f7e92b7e0592f7d8242c72e0f20c65057cd55b035996e59a5ead9c80ba

claim (bounded): the acquired operator enabled a solution under the given
search constraints (raw fails at BOTH depth-2/original and depth-3/matched-beam
configurations; with-atom solves exact; random-atom control does not solve).
Equal compute cost NOT claimed (with-atom 6.1 s vs raw-matched 2.1 s; birth
0.001 s). Universal superiority NOT claimed. CV 9.99 = NOT_FOUND sentinel.
Synthetic fixture; not independently replicated.

publication gates (owner): git push (currently local-only), git tag
verify-2-8-v1.0.0 + GitHub Release, Zenodo deposit via GitHub integration,
CONTRIBUTING.md + 2 invited-contribution issues, Show HN post (draft ready).
