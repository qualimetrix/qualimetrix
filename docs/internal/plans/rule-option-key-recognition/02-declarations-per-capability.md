# 02 — What each options class declares, and the 26 pairs that decide it

## The population

35 options classes (`measurement/option-declared-vs-read.tsv`, 35 data rows)
and 10 level classes (`measurement/option-level-slots.tsv`, the five
`hierarchical=yes` rows — file lines 12, 13, 14, 17 and 20, the same numbering
as `option-declared-vs-read.tsv` since both carry a header line — two slots each, each slot's class named with
`file:line` in column `slot_key_set_defined_at`). Every one of the 45 gains
`acceptedOptionKeys()`; the five hierarchical wrappers also gain
`levelOptionsClasses()`.

For **27 of the 35**, the declaration is a transcription: `read_not_declared`
and `declared_not_read` are both `-`, so the declared set is exactly the
constructor parameters plus whatever the two deleted interfaces said, and
`acceptedOptionKeys()` restates it. Those rows are not argued below; they are
listed by line in the package tables at the end.

The other seven carry the 23 measured `read_not_declared` pairs (lines 12, 13,
14, 17, 19, 20, 35). An eighth class,
`LayerViolationOptions` (line 34), carries three more that the measurement
could not see — see the overview's Fact 3 and
`measurement/option-enumeration-blind-spots.tsv` row `LayerViolationOptions
dynamic-key 2 (line 120)`. **26 pairs.**

## The four decision kinds, and why the question has four answers and not two

The subject was posed as a two-way sort: a working key to be declared, or a
dead key to be refused. Measurement forces two more:

- **answered-by-the-class** — the class reads the key *in order to refuse it*,
  in words the generic refusal cannot produce (a migration instruction, or the
  distinction between `enabled: false` meaning "leave it off" and `enabled:
  true` promising something the class cannot do). Declaring it accepted would
  destroy the message; refusing it generically would print two contradictory
  sentences, which is the observed defect. Four pairs.
- **remove-then-refuse** — the key works, but only as an undeclared legacy
  alias of a key that is declared and does the same thing. Declaring it would
  enshrine an undocumented alias as public surface, against CLAUDE.md's
  *Backward Compatibility Policy* ("a removed option beats an alias"). Seven
  pairs.

## The 26 pairs

`ref` is the line of `measurement/option-declared-vs-read.tsv`.
`E##` is a row of `measurement/merged-enumeration.md`.

| #   | rule                            | key                          | ref | today                                                                                      | decision                       |
| --- | ------------------------------- | ---------------------------- | --- | ------------------------------------------------------------------------------------------ | ------------------------------ |
| 1   | `complexity.ccn`                | `enabled`                    | 13  | read unguarded at `ComplexityOptions.php:34`; disables both levels; warns (E39)            | **declare**                    |
| 2   | `complexity.ccn`                | `warning-threshold`          | 13  | opens the legacy-flat branch (`:43`); alias of `threshold` semantics                       | **remove, then refuse** (П3.2) |
| 3   | `complexity.ccn`                | `error-threshold`            | 13  | same branch, same line                                                                     | **remove, then refuse**        |
| 4   | `complexity.ccn`                | `warning`                    | 13  | branch-guarded (`:44`); alone it does nothing — Fact 1                                     | **refuse**                     |
| 5   | `complexity.ccn`                | `error`                      | 13  | branch-guarded (`:44`); alone it does nothing — Fact 1                                     | **refuse**                     |
| 6   | `complexity.cognitive`          | `enabled`                    | 12  | as #1                                                                                      | **declare**                    |
| 7   | `complexity.cognitive`          | `warning-threshold`          | 12  | as #2                                                                                      | **remove, then refuse**        |
| 8   | `complexity.cognitive`          | `error-threshold`            | 12  | as #3                                                                                      | **remove, then refuse**        |
| 9   | `complexity.cognitive`          | `warning`                    | 12  | as #4                                                                                      | **refuse**                     |
| 10  | `complexity.cognitive`          | `error`                      | 12  | as #5                                                                                      | **refuse**                     |
| 11  | `complexity.npath`              | `enabled`                    | 14  | as #1                                                                                      | **declare**                    |
| 12  | `complexity.npath`              | `warning-threshold`          | 14  | as #2                                                                                      | **remove, then refuse**        |
| 13  | `complexity.npath`              | `error-threshold`            | 14  | as #3                                                                                      | **remove, then refuse**        |
| 14  | `complexity.npath`              | `warning`                    | 14  | as #4                                                                                      | **refuse**                     |
| 15  | `complexity.npath`              | `error`                      | 14  | as #5                                                                                      | **refuse**                     |
| 16  | `coupling.cbo`                  | `enabled`                    | 17  | read unguarded at `CboOptions.php:35`; disables both levels; warns (E39)                   | **declare**                    |
| 17  | `coupling.cbo`                  | `warning`                    | 17  | in the branch **condition** (`:63`); works alone — Fact 1                                  | **declare**                    |
| 18  | `coupling.cbo`                  | `error`                      | 17  | in the branch **condition** (`:64`); works alone — Fact 1                                  | **declare**                    |
| 19  | `coupling.instability`          | `enabled`                    | 20  | as #16 (`InstabilityOptions.php:34`)                                                       | **declare**                    |
| 20  | `coupling.instability`          | `max-warning`                | 20  | in the branch condition (`:48`); works alone                                               | **declare**                    |
| 21  | `coupling.instability`          | `max-error`                  | 20  | in the branch condition (`:49`); works alone                                               | **declare**                    |
| 22  | `coupling.distance`             | `project-namespaces`         | 19  | alias of `include-namespaces` (`DistanceOptions.php:55-58`); works; warns                  | **remove, then refuse**        |
| 23  | `architecture.unassigned-class` | `enabled`                    | 35  | read to refuse or to accept the "already off" idiom (`:88-111`) — Fact 3                   | **answered by the class**      |
| 24  | `architecture.layer-violation`  | `unreachable-layer-severity` | 34  | read through the constant map at `:119-120`; refused in its own words — Fact 3, blind spot | **answered by the class**      |
| 25  | `architecture.layer-violation`  | `potential-shadow-severity`  | 34  | as #24                                                                                     | **answered by the class**      |
| 26  | `architecture.layer-violation`  | `empty-template-severity`    | 34  | as #24                                                                                     | **answered by the class**      |

Counts: 9 declare, 6 refuse, 7 remove-then-refuse, 4 answered-by-the-class.

### Consequences that follow from specific rows, and are not obvious

- **#4/#5/#9/#10/#14/#15.** Once top-level `warning`/`error` are refused on the
  three complexity wrappers, `ThresholdParser`'s same-layer "threshold mixed
  with warning/error" conflict is unreachable *at that position* — the keys
  never reach `parse()`. The conflict remains reachable, and remains tested, at
  every position the parser is actually used from: inside each slot
  (`MethodComplexityOptions`/`ClassComplexityOptions`), and at the top level of
  `CboOptions`/`InstabilityOptions`, where #17/#18/#20/#21 keep those keys.
  The package moves the wrapper-level conflict case of
  `tests/…/RuleConfiguration` to the `CboOptions` top level rather than deleting
  it, so the mode-conflict assertion keeps a live witness.
- **#2/#3/#7/#8/#12/#13/#22 land in stage 03, not here.** The seven
  remove-then-refuse pairs are the only rows of this table that change
  behaviour, and landing them a stage before the refusal that explains them
  would give a user reaching the intermediate state silence where they used to
  get an effect. They are package П3.2, which touches four Options files and is
  therefore parallel with П3.1's `RuleOptionsFactory.php`. The declarations
  written here already omit the seven keys, so nothing else moves with them.
- **#2/#3/#7/#8/#12/#13.** Removing the two legacy names narrows the
  legacy-flat branch's entry condition to `threshold` alone. It does **not**
  change what the branch does — including enumeration row 66, a top-level
  `threshold` disabling the `class` level, which stays as it is and is named
  out of scope in the overview.
- **#17/#18/#20/#21.** Declaring these makes four keys public that
  `CboOptions`' own docblock says are "intentionally NOT declared". The
  docblock's reason — that advertising them beside `threshold` would suggest two
  ways to say one thing — is real, and the cost is paid in stage 04: the
  website rule pages for `coupling.cbo` and `coupling.instability` (EN and RU)
  must document them, and the docblocks must be rewritten rather than left
  contradicting the declaration. The alternative, removing them for symmetry
  with the complexity wrappers, was rejected on measurement: they *work alone*
  (Fact 1), so removing them would break a configuration that today does
  something, to buy consistency with three classes where the same spelling does
  nothing.
- **#23–#26.** These four keep printing their class's own sentence and stop
  printing the generic one above it. Nothing about their refusal text changes.

## Work packages

All five declaration packages touch disjoint file sets and are **parallel**.
All depend on П1.1 and on nothing else. None is independently green — see
*What this stage leaves broken* in `01-key-set-contract.md`: the union of
П1.1 and П2.1–П2.5 is the landing unit, and the aggregate gate runs once over
that union.

### П2.1 — Complexity

`src/Analysis/Evidence/Complexity/`: `ComplexityOptions.php`,
`CognitiveComplexityOptions.php`, `NpathComplexityOptions.php`,
`WmcOptions.php` (lines 12–15), and the six level classes named in
`option-level-slots.tsv` lines 12–14 (`Method*`/`Class*` for each of the three).
Carries the declaration half of pairs #1–#15; the removals of #2/#3/#7/#8/#12/#13
belong to П3.2. Also carries `levelOptionsClasses()` for its three wrappers.

### П2.2 — Coupling

`src/Analysis/Evidence/Coupling/`: `CboOptions.php`, `InstabilityOptions.php`,
`DistanceOptions.php`, `ClassRankOptions.php` (lines 17–20), and the four level classes
(`ClassCboOptions`, `NamespaceCboOptions`, `ClassInstabilityOptions`,
`NamespaceInstabilityOptions`; `option-level-slots.tsv` lines 17 and 20). Carries pairs #16–#21 and the declaration half of #22 (whose alias removal is
П3.2), plus `levelOptionsClasses()` for its two wrappers.

### П2.3 — CodeSmell, Cohesion, Size

Transcription only, twelve classes: `option-declared-vs-read.tsv` lines 3–10
(`CodeSmell`, eight classes), line 11 (`LcomOptions`), lines 31–33 (`Size`,
three classes). `LongParameterListOptions` (line 8) and the others here that
implement `ShorthandOptionKeysInterface` move their `threshold` /
`vo-threshold` declarations into the new method.

### П2.4 — Design, Maintainability, Security, Duplication, CircularDependency, ComputedMetrics

Transcription only, twelve classes: lines 2 (`CircularDependency`), 16
(`ComputedMetricRuleOptions`), 21–25 (`Design`, five classes), 26
(`Duplication`), 27 (`Maintainability`) and 28–30 (`Security`, three classes).
`GodClassOptions` (line 22, eight accepted keys) is the widest set in the tree
and is what fixes the "at most eight" claim in the overview.

### П2.5 — Policy

`src/Analysis/Policy/Architecture/LayerViolation/LayerViolationOptions.php` and
`UnassignedClassOptions.php`, `src/Analysis/Policy/Inline/Directive/InlineDirectiveOptions.php`
(lines 34–36).
Carries pairs #23–#26, i.e. the whole answered-by-the-class half. Also updates
each capability README that lists these classes.

## What this stage leaves broken

- Between П1.1 and the end of П2.5 the tree does not compile. Only the union is
  offered for validation; no package here reports "green" on its own.
- After П2.5 the declarations are complete and **inert**: the factory has not
  been taught to read them, the two old interfaces are still implemented and
  still read, and no key changes what it does. That is the assertion this
  stage's gate run makes, and it is now true — the seven alias removals moved to
  П3.2 precisely so that it is.

## Test plan (no tests written here)

- One declaration test per capability package, table-driven over that package's
  classes: for each, the declared accepted set equals the union of constructor
  parameter names and the keys the class's `fromArray()` reads. The oracle is
  the AST reader of `scripts/enumerate-rule-option-keys.php`, not a hand-typed
  list — stage 04 turns this into the standing guard.
- Per hierarchical wrapper: `levelOptionsClasses()` keys equal
  `getSupportedLevels()` values as strings, and each named class implements
  `LevelOptionsInterface`.
- Behavioural equivalence for the stage as a whole: `composer gate --
  --reference=<commit before П1.1>` GREEN with empty maps, run once. Nothing in
  this stage can move a finding.
- **The universal off-switch, over every registered rule.**
  `normalizeScalarConfig()` (`RuleOptionsFactory.php:252-268`) turns
  `rules: {X: false}` into `{enabled: false}` for *any* rule, so once unknown
  means refuse, a class that neither accepts nor answers `enabled` turns the
  product's universal off-switch into a hard error. All 35 satisfy it today —
  27 by constructor parameter, three by pairs #1/#6/#11, three by #16/#19 and
  `ComputedMetricRuleOptions`, one by #23 answering it — but that is a property
  of the population, not of the design, so it becomes an invariant test:
  `rules: {<rule>: false}` refuses for no registered rule, table-driven over the
  container's registry rather than over a written list.
- Pairs #23–#26 keep a case each asserting their bespoke refusal text is
  unchanged; the generic line above it is not yet gone (stage 03 owns that).
