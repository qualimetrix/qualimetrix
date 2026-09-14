# Stage 02 — repository controls leave `tests/`

40 files assert facts about the repository rather than about product behaviour,
and 17 more do so in part (33 non-product methods inside them). They leave
`tests/`. The verdict per file is in
[`measurement/controls-verdict.tsv`](measurement/controls-verdict.tsv); the
criterion that produced it is in
[`measurement/controls-taxonomy.md`](measurement/controls-taxonomy.md).

## The criterion, restated after review

The first draft discriminated by **mechanism**: an assertion reaching the
filesystem was a control, one reaching the compiled DI container was a test.
Review showed the axis was wrong — it split files that make the same kind of
claim and joined files that do not — and the plan was silently using a third,
unwritten criterion ("the SUT lives in `scripts/`") for eleven verdicts.

The criterion is now about the **subject of the assertion**, in one question:

> Is the expected side invented by the test, or copied from the repository?

Container, reflection, glob, parser, tracked fixture are all just reading
devices and none of them decides anything.

| Class          | Claim                                                                                                                 | Count |
| -------------- | --------------------------------------------------------------------------------------------------------------------- | ----- |
| `product-test` | this named thing behaves so, on input the test built                                                                  | 15    |
| `repo-control` | every registered X has property Y — a *census* (must be edited when the population changes) or a *law* (never edited) | 40    |
| `tooling-test` | this repository tool behaves so (`scripts/`, `finding-gate/`, `Qualimetrix\PhpStan\Rules`)                            | 9     |
| `mixed`        | both, split by method                                                                                                 | 17    |

35 of the 81 verdicts changed. The pair that the old rule hid:
"one named thing behaves so" is a product test; "every registered X has property
Y" is a control, whichever device it reads through.

## The root and its axis

Review's charge against the first layout was that
`Documentation/ GeneratedArtifacts/ SourceLayout/ TestSuite/` is the mechanism
list renamed into nouns — grouping by the *carrier* of the artefact. **This was
settled by measurement, not argument:** three real commits about the channel
subject (`40ae4019`, `0d8ee47d`, `887c8fb6`) each touch controls whose carriers
are simultaneously `src/`, `website/docs/` and a fixture under `tests/`. Under a
carrier axis every such commit must visit three directories — ADR 0016's
co-change test fails.

The axis is the **guarded subject**:

```
governance/
├── Channel/                 12
├── RuleDeclaration/          7
├── SolePrimitiveOwnership/   6
├── ThresholdKeys/            5
├── ModularOwnership/         4
├── TestSuiteHygiene/         4 + 3  ← 4 existing, plus G1, G2, G3 from stage 01
├── ConsoleComposition/       3
├── RepositoryEntrypoints/    3
├── RuleOptionKeys/           2
├── Occurrence/               2
└── (9 named singletons)
```

All files of each of the three channel commits land in `Channel/`.

**Two open points, stated rather than hidden:** `SolePrimitiveOwnership` has not
been checked against co-change, and `DocumentationConsistencyTest` does not fit
any single group — it is one file asserting several unrelated subjects and
should be split by subject, not filed whole.

## Registration — the part that leaks

**Stage 01 already created and fully registered this root** (see its closing
section); this stage only moves files into it. The table below is what stage 01
must have done, and what this stage verifies rather than performs:

| Set                   | Current state                                                                                                                                                              | Requirement                                                                                                                                                           |
| --------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PHPUnit config        | 53 enumerated directories                                                                                                                                                  | a suite covering the root                                                                                                                                             |
| Aggregate             | `scripts/phpunit-aggregate.py:32` hardcodes `SUITES = ("Unit","Integration","Functional","Infrastructure")`, and its docstring *proves* those four partition the aggregate | a fifth suite must be added **and** the partition proof updated, or the aggregate refuses                                                                             |
| PHPStan               | `phpstan.neon:13` has `tests`; `:32` ignores a path that moves                                                                                                             | add the root; move the ignore with its file                                                                                                                           |
| PHP-CS-Fixer          | finder has `/tests` and already has `/scripts`                                                                                                                             | add the root                                                                                                                                                          |
| Composer autoload-dev | `Qualimetrix\Tests\` → `tests/` (the `ArchitectureStaticAnalysis/Unit/Fixtures/` classmap belongs to stage 03, which moves that directory)                                 | new PSR-4 prefix; move the classmap entry                                                                                                                             |
| Composer group        | four groups named by what invalidates them                                                                                                                                 | `Channel/`, `RuleDeclaration/` etc. are invalidated by a code change, not by a claim about the repo — `check:self` fits some groups and not others; choose explicitly |
| Inventory generator   | 346 pinned `tests/` paths, **56 touched by this stage**                                                                                                                    | edit and regenerate, or `architecture:check` reddens                                                                                                                  |

**A control's own scan scope must move with it.**
`ScratchPathIsolation/Unit/ScratchPathsCarryRealEntropyTest.php:42` declares
`private const ROOTS = ['tests', 'scripts'];`. After 40 controls move to a third
root, this control silently stops covering them — narrower scope, green run.
That is the failure mode this whole stage exists to prevent, reproduced by the
stage itself.

**One control already does not run under `composer check`.**
`SuppressionSnapshotFreshnessTest:23` and
`ModularArchitectureGovernanceIntegrationTest:19` carry
`#[Group('live-freshness')]`, which the aggregate excludes. Whatever happens to
them, the neighbouring assertion *about that routing* must change in the same
commit or it reddens.

## Delete rather than move

Controls that duplicate an existing mechanism should be deleted, not relocated:
two implementations of one rule disagree eventually and nothing says which is
right. Candidates and the explicit keep-list are in
[`measurement/controls-verdict-notes.md`](measurement/controls-verdict-notes.md).
Note one correction from the re-ruling: `ChannelRenameTsvGateAgreementTest` is
**not** a control — it drives a `scripts/finding-gate` reader over a test
fixture and never opens the real `channels.tsv`.

## Definition of Done

- Every `repo-control` path is out of `tests/`; every `product-test` path is
  still in it; every `mixed` file kept its product methods and lost its control
  methods. Checked by script against the TSV.
- Executed-test count: stage-01 baseline minus the moved controls, predicted
  first and compared. A green aggregate that quietly stopped running 40 files
  looks exactly like success.
- `composer architecture:check` green.
- `composer check` green — the aggregate, not a subset.
