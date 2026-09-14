# Test structure: subject layout, controls, and suite integrity

## Why

679 `*Test.php` files exist to stop an AI agent from breaking the code. Three
independent problems work against that purpose:

1. **The suite cannot be trusted to run what it contains.** One test never
   executes at all, and `phpunit.xml.dist` enumerates 53 directories by name, so
   the set of executed tests is maintained by hand.
2. **Repository controls live among tests.** 40 files, plus control methods
   inside 17 more, assert facts about the repository — artefact freshness,
   `docs/` agreement, `src/` vocabulary invariants — not product behaviour. A
   further 9 files test repository tooling, which is behavioural testing of a
   non-`src` subject.
3. **Two layouts coexist.** 583 files follow ADR 0022 (`{subject}/{level}`); 96
   remain in the pre-migration role buckets.

Plus 276 content defects across 219 files: duplicates, tautologies that cannot
fail, tests whose name contradicts their body.

## Decisions, with the alternative rejected

**D1. Finish ADR 0022; do not reverse it.** Layout stays `{subject}/{level}`.
Rejected: `{level}/{subject}`, which moves 583 files instead of 96, requires
rewriting ADR 0022 and CLAUDE.md, and puts at the top of `tests/` the role
bucket ADR 0016 forbids for `src/`. The project already votes for D1: every test
written since August lands in the subject layout.

**D2. Controls leave `tests/` entirely.** A separate suite inside `tests/` was
cheaper, but a file under `tests/` is among the tests however the config labels
it. PHPUnit stays as the assertion library.

**D3. Controls and tooling tests are two subjects.** Tests of `scripts/`,
`tools/phpstan/` and `finding-gate/` code are ordinary behavioural tests; they
move next to their code, not into the controls root.

**D4. The controls root is grouped by guarded subject** — `Channel/`,
`RuleDeclaration/`, `ThresholdKeys/` … — not by the carrier of the artefact.
Rejected after measurement: under a carrier axis
(`Documentation/ GeneratedArtifacts/ SourceLayout/`), three real channel commits
each have to touch three directories, failing ADR 0016's co-change test.

**D5. A test is a control when its expected side is copied from the repository
rather than invented by the test.** Rejected: the first draft's mechanism axis
("filesystem ⇒ control, DI container ⇒ test"), which split files making the same
kind of claim, and which concealed an unwritten third criterion. Restated: *one
named thing behaves so* is a test; *every registered X has property Y* is a
control, whichever device it reads through.

**D6. The suite config uses globs, with a shrinking transitional remainder.**
Measured: PHPUnit 12.5.25 accepts `<directory>tests/*/*/Unit</directory>`, and a
depth-glob set plus transitional entries reproduces the current suite exactly —
679 classes, 9098 methods, nothing lost, nothing extra. Globs alone lose 100
classes: 96 legacy-bucket files whose level segment comes first (covered once
stage 04 moves them) and 4 `Infrastructure/Logging` tests that sit under no level
directory (moved in stage 01). Rejected: keeping the enumeration and adding a
directory-coverage guard, which taxes every later stage with a registration step.

## Stages

| Stage                           | Subject                            | Scope                                       | Depends on |
| ------------------------------- | ---------------------------------- | ------------------------------------------- | ---------- |
| [01](01-suite-integrity.md)     | The suite runs what it contains    | 1 test, 3 guards, config, the controls root | —          |
| [02](02-controls-extraction.md) | Repository controls leave `tests/` | 40 files + 33 methods in 17                 | 01         |
| [03](03-tooling-tests.md)       | Tooling tests move to their code   | 9 files + 4 partials                        | 01, 02     |
| [04](04-subject-layout.md)      | ADR 0022 completed                 | 96 files                                    | 01, 02, 03 |
| [05](05-content-defects.md)     | Ledger defects                     | 219 files                                   | 01         |

**The stages are not independent, and the first draft claimed they were.**
Review showed the file sets intersect: the tooling files carry control verdicts
(02 ↔ 03), five of them also have relocation targets (03 ↔ 04), and 40 ledger
files are also control or relocation files (02/04 ↔ 05). Each stage's tables
describe the tree as it is *before* the previous stage ran, so the order above is
mandatory, not advisory.

Stage 01 also creates and fully registers the controls root, resolving the cycle
"the guards are controls, and the controls root is made in stage 02". A stage
that left the root half-registered would be green by its own DoD and would break
the next one.

## Cost the stages share

`scripts/generate-modular-architecture-test-inventory.php` hardcodes 346 paths
under `tests/`; the stages touch 123 of them
([`measurement/pinned-paths-impact.txt`](measurement/pinned-paths-impact.txt)).
Each touched path means editing the generator and regenerating
`docs/internal/generated/modular-architecture/`, or `architecture:check` reddens.

## Measurement this plan stands on

Tables are in [`measurement/`](measurement/); read them there rather than
trusting counts in prose.

| Artifact                                                           | Holds                                          | Obtained by                                                                                 | Cannot see                                                                     |
| ------------------------------------------------------------------ | ---------------------------------------------- | ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| `slice-reports/*.md`                                               | per-file SUT, category, defects; 679/679 files | 10 agents read the tree; 5 more read method bodies where the first pass admitted it had not | judgement, not execution — no test was run                                     |
| `defect-ledger.tsv` + `ledger-provenance.md`                       | 276 defects over 219 files                     | union of all slice reports, the body-hash pass and the never-runs scan                      | only defects some reader named                                                 |
| `controls-verdict.tsv` + `controls-taxonomy.md`                    | 81 files → 15/40/9/17                          | D5 applied by reading each file                                                             | its input list, which was built by the older criterion                         |
| `identical-bodies.txt`                                             | 20 groups, 58 methods, byte-identical          | hashing normalised bodies, whole tree                                                       | near-duplicates differing by one literal                                       |
| `legacy-relocation-snapped.csv` + `relocation-map-corrections.txt` | 96 files → targets, by `#[CoversClass]`        | the file's own coverage claim, imports as fallback                                          | 7 fallback rows carry the old defect; `#[CoversClass]` is a claim, not a proof |
| `pinned-paths-impact.txt`                                          | 346 pinned paths, 123 touched                  | enumerating the generator's literals                                                        | paths built at runtime rather than written                                     |

**Three witnesses, and they disagreed usefully.** The mechanical import scan
produced 17 false accusations out of 21 and still found 4 real controls the
readers missed — both because of `require_once`. The body-hash pass found 56
duplicates no reader reported. The readers found tautologies no script can see.
No single list was right.

**What review corrected, recorded because the same mistakes recur:** the
relocation map was built from the wrong witness and was wrong in 68 of 96 rows;
the ledger was one source rather than the union and was missing 63 rows,
including both specimens the plan cited; the pinned-path cost was described as a
single special case rather than 123; G2's justification asserted something
false; and the glob option was measured only after review asked for it.

**Blind spot, named rather than closed:** nothing here was established by
running the suite. Stage 01 is what converts static reading into something
executable.
