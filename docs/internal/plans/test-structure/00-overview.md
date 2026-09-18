# Test structure: subject layout, controls, and suite integrity

## Why

679 `*Test.php` files exist to stop an AI agent from breaking the code. Three
independent problems work against that purpose:

1. **The suite cannot be trusted to run what it contains.** One test never
   executes at all, and `phpunit.xml.dist` enumerates 52 test directories by name, so
   the set of executed tests is maintained by hand.
2. **Repository controls live among tests.** 40 files, plus control methods
   inside 17 more, assert facts about the repository — artefact freshness,
   `docs/` agreement, `src/` vocabulary invariants — not product behaviour. A
   further 16 files, plus 2 Python files, test repository tooling, which is
   behavioural testing of a non-`src` subject. That count is stage 03's
   re-derivation; this overview first claimed 9, from a census taken before
   stage 02 split the mixed files.
3. **Two layouts coexist.** Re-measured after stages 02 and 03: of the 529 test
   classes outside the buckets, 504 already sit at their manifest owner. 87 remain
   in the pre-migration role buckets, and a further 25 sit under an owner the
   manifest does not have. Stage 04 moves all of them, 114 files including two
   support classes, and states the rule the 504 were already obeying.

Plus 276 content defects across 219 files: duplicates, tautologies that cannot
fail, tests whose name contradicts their body.

## Decisions, with the alternative rejected

**D1. Finish ADR 0022; do not reverse it.** Layout stays `{subject}/{level}`.
Rejected: `{level}/{subject}`, which moves 529 files instead of 114, requires
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

**D6. The suite config keeps its enumerated directories.** A glob-based config
was adopted in the second draft and withdrawn in the third: globs break the
four-suite partition the aggregate proves, and
`generate-modular-architecture-test-inventory.php` holds a second copy of the
suite map that reconciles literally in both directions, so `architecture:check`
reddens. The hazard globs were meant to remove — a move into an unlisted
directory — is already caught by G2's orphan check, so the cheaper config was
also the unnecessary one. Each stage registers the directories it creates; the
full account is in [01](01-suite-integrity.md).

## Stages

| Stage                                                   | Subject                            | Scope                                       | Depends on     |
| ------------------------------------------------------- | ---------------------------------- | ------------------------------------------- | -------------- |
| [01](01-suite-integrity.md)                             | The suite runs what it contains    | 1 test, 3 guards, config, the controls root | —              |
| [02](02-controls-extraction.md)                         | Repository controls leave `tests/` | 40 files + 33 methods in 17                 | 01             |
| [03](03-tooling-tests.md) + [packages](03-packages.md)  | Tooling tests move to their code   | 16 files + 2 Python                         | 01, 02         |
| [04](04-subject-layout.md) + [packages](04-packages.md) | Every test file sits at its owner  | 114 files + 3 named cases                   | 01, 02, 03     |
| [05](05-content-defects.md)                             | Ledger defects                     | 219 files                                   | 01, 02, 03, 04 |
| [06](06-governance-subject-groups.md)                   | `SolePrimitiveOwnership` is split  | 7 controls                                  | 04             |

**Landed:** every stage but 05 is in `main` — 01, 02, 03, 04 and 06
(`52eae218`, `c49fc0b4`, `e15c7f42`, `7a89a3ad`, `666d8679`). The role buckets
are gone, every test class sits at its manifest owner, the invariant is a control
in `governance/TestSuiteHygiene/` rather than a batch of moves, and `governance/`
no longer carries a group named for the form of its assertion. **05 is the only
stage left.** Suite counts measured on `666d8679` with the runner's own
exclusions: 6705 / 383 / 152 / 1029 / 181 / 758. Governance was 757 after 04 —
748 plus the nine cases that stage's control adds — and 06 added the one case
that makes the glob-alphabet guard prove it looked. What 04 hands on is written in its
packages file, not here; the two entries stage 05 must read before re-deriving
its own population are the three capped exception lists and the fourteen
inventory rows that promise a relocation no package is named to perform.

**The stages are not independent, and the first draft claimed they were.**
Measured intersections: 39 ledger files also appear in the relocation map, 42
also carry a controls verdict, 66 are touched by some other stage. Of the 78
ledger files whose defect is `misplaced` or `category-wrong` — the files stage 05
would itself *move* — 16 are moved by stage 04 and 15 by stage 02 or 03, 31 in
all.

So stage 05 runs **last**, and its moving classes are re-derived after 02–04 have
landed: a third of them will already be in the right place and the ledger's paths
for them will no longer exist. Every stage's tables describe the tree as it was
*before* the previous stage ran, which is why the order is mandatory rather than
advisory.

Stage 01 also creates and fully registers the controls root, resolving the cycle
"the guards are controls, and the controls root is made in stage 02". A stage
that left the root half-registered would be green by its own DoD and would break
the next one.

## Cost the stages share

`scripts/generate-modular-architecture-test-inventory.php` hardcodes path
literals under `tests/`; re-measured on `main` @ e15c7f42 there are **320, of which
103 are already dead**. Each live touched path means editing the generator and
regenerating `docs/internal/generated/modular-architecture/`, or
`architecture:check` reddens.

The per-stage split in
[`measurement/pinned-paths-impact.txt`](measurement/pinned-paths-impact.txt) is
superseded for stage 04 and was an overestimate of the wrong thing. Stage 04 does
not edit its share of the literals: 5 name a moving file exactly and 21 are
directory prefixes covering one, but the stage **deletes the ladder those literals
live in** rather than following it, so the count that matters is not how many it
edits. The dead 103 are the argument: the ladder is the one place in that file
`assertPathLiteralsResolve()` does not guard, so a stale prefix there is silent.

**Stage 03's figure of 12 is superseded and understated.** It was derived from
the 9-file population, and it counted only this one generator's `tests/`
literals. The re-derived stage-03 population is 16 files plus 2 Python, and its
addresses reach further than pinned paths — into two other generator constants,
a tracked digest, `.dockerignore` and the rename-enumeration surface. The
current list is
[`measurement/stage-03/addresses-to-edit.md`](measurement/stage-03/addresses-to-edit.md);
the other stages' figures here have not been re-derived and carry the same
risk.

## Measurement this plan stands on

Tables are in [`measurement/`](measurement/); read them there rather than
trusting counts in prose.

| Artifact                                                                   | Holds                                                         | Obtained by                                                                                                              | Cannot see                                                                                              |
| -------------------------------------------------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------- |
| `slice-reports/*.md`                                                       | per-file SUT, category, defects; 679/679 files                | 10 agents read the tree; 5 more read method bodies where the first pass admitted it had not                              | judgement, not execution — no test was run                                                              |
| `defect-ledger.tsv` + `ledger-provenance.md`                               | 276 defects over 219 files                                    | union of all slice reports, the body-hash pass and the never-runs scan                                                   | only defects some reader named                                                                          |
| `controls-verdict.tsv` + `controls-taxonomy.md`                            | 81 files → 15/40/9/17                                         | D5 applied by reading each file                                                                                          | its input list, which was built by the older criterion                                                  |
| `identical-bodies.txt`                                                     | 20 groups, 58 methods, byte-identical                         | hashing normalised bodies, whole tree                                                                                    | near-duplicates differing by one literal                                                                |
| `stage-04/relocation-map.csv` (supersedes `legacy-relocation-snapped.csv`) | 114 files → targets, owner, witness, current and target suite | `#[CoversClass]` resolved through `use` to an FQCN, looked up in the 37-owner manifest; 11 rows read and decided by hand | `#[CoversClass]` is a claim, not a proof; says nothing about whether the *level* is right               |
| `stage-04/addresses.md` + two witness reports                              | registration addresses stage 04 breaks, loud and silent       | two independent enumerations, one by the stage-03 taxonomy and one derived from the code with that taxonomy withheld     | dynamically built class names, paths carried in data; neither witness ran the generator on a moved tree |
| `stage-04/prediction.md`                                                   | per-suite case counts, baseline and after each package        | `phpunit --list-tests` per suite and per file                                                                            | a data provider whose row count depends on the filesystem would move with the tree                      |
| `pinned-paths-impact.txt`                                                  | 346 pinned paths, 125 touched across stages                   | enumerating the generator's literals                                                                                     | paths built at runtime; directory pins counted as touched are an upper bound                            |

**Three witnesses, and they disagreed usefully.** The mechanical import scan
produced 17 false accusations out of 21 and still found 4 real controls the
readers missed — both because of `require_once`. The body-hash pass found 56
duplicates no reader reported. The readers found tautologies no script can see.
No single list was right.

**What two review rounds corrected, recorded because the mistakes recur.** Round
one: the relocation map was built from the wrong witness and wrong in 68 of 96
rows; the ledger was one source rather than the union, missing 63 rows including
both specimens the plan cited; the pinned-path cost was one special case rather
than 123; the controls criterion split files making the same claim while hiding
an unwritten third rule; G2's justification asserted something false.

Round two, on the repairs themselves: the glob config adopted to simplify
registration breaks the suite partition and the inventory generator's second copy
of the suite map, and was withdrawn; the rebuilt map left its `witness` column
empty, so the counts it claimed could not be checked and its 12 undecidable rows
could not be selected; the snapping rule resolved multi-subject files onto
taxonomy nodes ADR 0022 forbids; stage 05 was still declared independent while
78 of its files are moved by other stages; and stage 03's registration claims
were wrong in three places, including two files that would have left PHPStan and
cs-fixer entirely.

**The recurring shape is one mistake, not five:** a claim about a set accepted
from a measurement narrower than the set. It survived into the repairs, which is
why the withdrawal of D6 is written out rather than deleted.

**Blind spot, named rather than closed:** nothing here was established by
running the suite. Stage 01 is what converts static reading into something
executable.
