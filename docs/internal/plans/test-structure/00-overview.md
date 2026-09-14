# Test structure: subject layout, controls, and suite integrity

## Why

679 `*Test.php` files exist to stop an AI agent from breaking the code. Three
things currently work against that purpose, and they are independent problems
that happen to live in the same tree:

1. **The suite cannot be trusted to run what it contains.** `phpunit.xml.dist`
   enumerates 53 directories by name. Today every test is reachable, but 39
   level-directories of existing capabilities are listed in no suite, so any
   relocation into one of them silently disables the test while `composer check`
   stays green. One test already never executes at all.
2. **Repository controls live among tests.** 43 files (plus control methods
   inside 13 more) assert facts about the repository — generated-artifact
   freshness, `docs/` agreement, `src/` layout — not about product behaviour.
3. **Two layouts coexist.** 583 files follow ADR 0022 (`{subject}/{level}`),
   96 remain in the pre-migration role buckets `tests/Unit`,
   `tests/Integration`, `tests/Functional`.

Plus 213 content defects recorded in the ledger: duplicates, tautologies, tests
whose name contradicts their body.

## Decisions taken, with the alternative that was rejected

**D1. Finish ADR 0022; do not reverse it.** Layout stays `{subject}/{level}`.
Rejected: `{level}/{subject}` (role first). It moves 583 files instead of 96,
requires rewriting ADR 0022 and the CLAUDE.md section, and introduces at the top
of `tests/` exactly the role bucket ADR 0016 forbids for `src/`. The project
already votes for D1 in practice: every test written since August lands in the
subject layout, and `tests/Unit/Reporting` is an unmigrated tail, not a design.

**D2. Controls leave `tests/` entirely.** A separate PHPUnit suite inside
`tests/` was cheaper, but a file under `tests/` is among the tests however the
config labels it. PHPUnit stays as the assertion library — rewriting 43 files as
scripts buys nothing.

**D3. Controls and tooling tests are two subjects, not one.** Tests of
`scripts/` code (PromiseEffect, DirectiveAudit) are ordinary behavioural tests
whose subject happens not to be `src/`. They move next to the code they test,
not into the controls root. Folding both into one `controls/` directory would
repeat the present mistake: a directory named by role ("checks") rather than by
subject. See ADR 0016.

**D4. The controls root is organised by what is guarded**, not by check type:
documentation agreement, generated-artifact freshness, `src/` layout invariants,
and the test suite's own integrity. Each answers "what is this about?" with a
noun phrase, per ADR 0016.

**D5. The discriminator for "control", applied file by file:** an assertion that
learns about `src/` through the *filesystem* — globbing directories, reading
files as text, parsing them, reflecting over a directory scan — is a control. An
assertion that asks the *compiled DI container* what is registered and then
exercises it is an integration test over the product's real runtime surface and
stays. A product test that reads repository files because reading files is the
product's job (installing git hooks, reading the analysed project's
`composer.json`) is an ordinary test. This rule reversed 7 wave-1 verdicts and
is the thing to attack if the taxonomy is wrong — not the 81 individual calls.

## Stages

| Stage                           | Subject                            | Files touched | Blocks     |
| ------------------------------- | ---------------------------------- | ------------- | ---------- |
| [01](01-suite-integrity.md)     | The suite runs what it contains    | 3 + config    | everything |
| [02](02-controls-extraction.md) | Repository controls leave `tests/` | 43 + 13 split | 03         |
| [03](03-tooling-tests.md)       | Tooling tests move to their code   | 8             | —          |
| [04](04-subject-layout.md)      | ADR 0022 completed                 | 96            | —          |
| [05](05-content-defects.md)     | Ledger defects                     | ~120          | —          |

Order is forced only at the head: stage 01 produces the guards that make every
later move verifiable, and nothing else touches the tree until stage 01 is green
in CI. Stages 02–05 are independent of each other by file set; 03 depends on 02
only for the shared root's registration.

## Measurement this plan stands on

All tables are on disk under [`measurement/`](measurement/); read them there
rather than trusting counts quoted in prose.

| Artifact                        | What it holds                                  | How it was obtained                                                                              | What it cannot see                          |
| ------------------------------- | ---------------------------------------------- | ------------------------------------------------------------------------------------------------ | ------------------------------------------- |
| `slice-reports/*.md`            | per-file SUT, category, defects; 679/679 files | 10 agents read the tree, then 5 more read method bodies where the first pass admitted it had not | judgement, not execution: no test was run   |
| `defect-ledger.tsv`             | 213 defects, typed and ranked                  | synthesis over the slice reports, each line re-checked against code                              | only defects some reader named              |
| `controls-verdict.tsv`          | 81 candidates → 43/24/13                       | D5 applied by reading each file                                                                  | a control hidden behind a helper it calls   |
| `identical-bodies.txt`          | 20 groups, 58 methods, byte-identical bodies   | hash of normalised bodies, whole tree                                                            | near-duplicates that differ by one literal  |
| `untested-methods.txt`          | `itXxx` without `#[Test]`                      | regex over the whole tree                                                                        | a test disabled some other way              |
| `legacy-relocation-snapped.csv` | 96 files → target dirs                         | owner inferred from imports, snapped to existing dirs                                            | files whose imports do not name their owner |
| `controls-disagreement.txt`     | where the two witnesses disagreed              | diff of the mechanical and the read witness                                                      | —                                           |

**Two witnesses, deliberately.** The mechanical pass (imports, path references)
produced 17 false accusations out of 21 — it cannot see code pulled in by
`require_once`, and it flags product tests that legitimately read repository
files. The reading pass missed 4 real controls for the same `require_once`
reason. Neither list alone is the answer; the verdict file is the reconciliation.

**Known blind spot, named rather than closed:** nothing here was established by
running the suite. Categories, duplicates and tautologies rest on static
reading. Stage 01 is what converts that into something executable.
