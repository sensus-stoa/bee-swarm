# Contributing to Bee Swarm

Thanks for your interest. This repository is a PHP implementation of the
CV→0 Autonomous Evolution Protocol: agents that discover mathematical
invariants, evolve their own grammar, and refuse to certify what they cannot
prove. The refusal with a falsifiable diagnosis is a product, not a failure.

## Setup

```bash
git clone <this repository>
cd bee-swarm
composer install
php scripts/verify_2_8.php   # one-command language-emergence demo
```

Expected: exit code 0, JSON report with `"verdict": "PASS"`. In-memory DB,
no network. The run refuses persistent databases unless you set an explicit
opt-in flag (protects your data).

## Verification ladder

Every claim in this project maps to a script. Run the ladder:

```bash
php scripts/verify/verify_all.php --stage=0
```

Full test suite (fast parallel + slow serial):

```bash
bash scripts/run_two_pass.sh
```

The slow group runs serially on purpose: those tests are wall-clock
sensitive, and parallel workers make them flaky.

## How we work (and how contributions are judged)

1. **Pre-registration.** If you propose an experiment: fix the hypothesis,
   PASS/FAIL criteria, budgets, and controls in writing BEFORE running.
   Changing criteria after seeing results is rejected, including in review
   comments.
2. **Honest negatives are contributions.** A null result with controls is
   published in the experiment journal, not buried.
3. **No threshold tuning against data.** Calibration happens before the
   run; post-hoc adjustments require a new pre-registration.
4. **Refusals are results.** If your change makes the system certify more,
   show that the null false-positive rate stays at zero.
5. **Cite artifacts.** Claims reference commits, reports, or hash-pinned
   files, not descriptions.

## Invited contributions (good first issues)

- Independent re-implementation of the section 3.8 measurement
  (language emergence) from the protocol text alone, in any language,
  checked against our fixtures.
- Adversarial search for an isomorphism class where compiling two laws into
  one atom is WORSE than keeping the coalition (breaks our parity gate).

Both are tracked as GitHub Issues. Credit: contributors are listed in the
evidence record of the affected experiment (`Benchmarks/verify_2_8/` shows
the format) and in the release notes.

## Reporting bugs

Open an issue with: PHP version, OS, exact command, full output, and the
smallest reproducing input. Bug reports that include a failing command are
answered first.

## License

Code: MIT (`LICENSE-CODE`). Protocol and scientific texts: CC BY 4.0
(`LICENSE-PROTOCOL`).
