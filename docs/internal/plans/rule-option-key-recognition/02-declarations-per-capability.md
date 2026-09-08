# 02 — What each class declares, and the 36 pairs that decide it

## The population, and which measurement decides each part of it

**61 classes gain a declaration**, and they are not one population measured
once. The overview's *The population* table names all three; this stage states
what each one's declaration is transcribed **from**, because a declaration
transcribed from the wrong source is exactly how a working key gets refused.

| population                   | count | declaration transcribed from                                                                                     |
| ---------------------------- | ----- | ---------------------------------------------------------------------------------------------------------------- |
| rule options classes         | 35    | `measurement/option-declared-vs-read.tsv` — `declared` plus the decisions below for its `read_not_declared` keys |
| level (slot) options classes | 10    | `measurement/level-declared-vs-read.tsv` — `declared` plus `threshold`, pairs #27–#36 below                      |
| test implementations         | 16    | nothing to measure: a fixture declares the keys its own `fromArray()` reads, and six are anonymous classes       |

For **27 of the 35**, the declaration is a transcription with no decision:
`read_not_declared` and `declared_not_read` are both `-`, so the declared set is
exactly the constructor parameters plus whatever the two deleted interfaces
said, and `acceptedOptionKeys()` restates it. Those rows are not argued below.

The other seven carry the 23 measured `read_not_declared` pairs (lines 12, 13,
14, 17, 19, 20, 35). An eighth class, `LayerViolationOptions` (line 34), carries
three more that the measurement could not see — see the overview's Fact 3 and
`measurement/option-enumeration-blind-spots.tsv` row `LayerViolationOptions
dynamic-key 2 (line 120)`. **All ten level classes** carry one each, measured in
`level-declared-vs-read.tsv` and argued in the overview's Fact 4.

23 + 3 + 10 = **36 pairs**.

**`option-level-slots.tsv` and `level-declared-vs-read.tsv` are the source for a
level class's declaration; `option-declared-vs-read.tsv` is not, and never
carried a level-class row.** The first names each slot's key set with the
`file:line` it is defined at; the second is the same declared-versus-read
question asked of the level class itself. A package that transcribed a level
class from "constructor parameters plus the two interfaces" would omit
`threshold` ten times and turn a documented, working key into exit 3 — which is
Fact 4, and the reason these ten rows exist as decisions rather than as
transcription.

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

## The 36 pairs

For #1–#26, `ref` is the line of `measurement/option-declared-vs-read.tsv`;
for #27–#36 it is the line of `measurement/level-declared-vs-read.tsv`.
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

| 27  | `complexity.cognitive` → `MethodCognitiveComplexityOptions` | `threshold` | L2 | read unguarded through `ThresholdParser::parse()`'s default `$thresholdKey`; declared by no constructor; documented and working — Fact 4 | **declare** |
| 28  | `complexity.cognitive` → `ClassCognitiveComplexityOptions` | `threshold` | L3 | read unguarded through `ThresholdParser::parse()`'s default `$thresholdKey`; declared by no constructor; documented and working — Fact 4 | **declare** |
| 29  | `complexity.ccn` → `MethodComplexityOptions` | `threshold` | L4 | read unguarded through `ThresholdParser::parse()`'s default `$thresholdKey`; declared by no constructor; documented and working — Fact 4 | **declare** |
| 30  | `complexity.ccn` → `ClassComplexityOptions` | `threshold` | L5 | read unguarded through `ThresholdParser::parse()`'s default `$thresholdKey`; declared by no constructor; documented and working — Fact 4 | **declare** |
| 31  | `complexity.npath` → `MethodNpathComplexityOptions` | `threshold` | L6 | read unguarded through `ThresholdParser::parse()`'s default `$thresholdKey`; declared by no constructor; documented and working — Fact 4 | **declare** |
| 32  | `complexity.npath` → `ClassNpathComplexityOptions` | `threshold` | L7 | read unguarded through `ThresholdParser::parse()`'s default `$thresholdKey`; declared by no constructor; documented and working — Fact 4 | **declare** |
| 33  | `coupling.cbo` → `ClassCboOptions` | `threshold` | L8 | read unguarded through `ThresholdParser::parse()`'s default `$thresholdKey`; declared by no constructor; documented and working — Fact 4 | **declare** |
| 34  | `coupling.cbo` → `NamespaceCboOptions` | `threshold` | L9 | read unguarded through `ThresholdParser::parse()`'s default `$thresholdKey`; declared by no constructor; documented and working — Fact 4 | **declare** |
| 35  | `coupling.instability` → `ClassInstabilityOptions` | `threshold` | L10 | read unguarded through `ThresholdParser::parse()`'s default `$thresholdKey`; declared by no constructor; documented and working — Fact 4 | **declare** |
| 36  | `coupling.instability` → `NamespaceInstabilityOptions` | `threshold` | L11 | read unguarded through `ThresholdParser::parse()`'s default `$thresholdKey`; declared by no constructor; documented and working — Fact 4 | **declare** |

`ref` for rows #27–#36 is the line of `measurement/level-declared-vs-read.tsv`,
whose row order is the same as the table above; every one of the ten has
`declared_not_read` empty and zero blind spots, so `threshold` is the only
decision a level class needs.

Counts: 19 declare, 6 refuse, 7 remove-then-refuse, 4 answered-by-the-class.

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
  get an effect. They are package П3.2, which touches exactly four Options
  files and lands **after** П3.1 rather than beside it — stage 03 states why.
  The declarations written here already omit the seven keys, so nothing else
  moves with them.
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

Six packages, all parallel, all depending on П1.1 and on nothing else. **Each
package's file set is its row group in `measurement/packages.tsv`**, and the
intersection of the six was taken machine-wise and is empty (the command is in
the overview, under *Work packages and their file sets*). None is independently
green — see *What this stage leaves broken* in `01-key-set-contract.md`: the
union of П1.1 and П2.1–П2.6 is the landing unit, and the aggregate gate runs
once over that union.

**No package here touches `docs/internal/modular-architecture-manifest.json` or
`docs/internal/generated/modular-architecture/`.** П1.1 owns both for this
landing unit, including the consumer rows for the imports these six packages
add. That is what keeps the six parallel: a shared manifest would make every
one of them edit one JSON file.

### П2.1 — Complexity

The three hierarchical wrappers, `WmcOptions`, and the six level classes
(`Method*`/`Class*` for each of the three wrappers), plus the capability README.
Carries the declaration half of pairs #1–#15 and pairs #27–#32; the removals of
#2/#3/#7/#8/#12/#13 belong to П3.2. Also carries `levelOptionsClasses()` for its
three wrappers.

### П2.2 — Coupling

`CboOptions`, `InstabilityOptions`, `DistanceOptions`, `ClassRankOptions` and
the four level classes, plus the capability README. Carries pairs #16–#21, the
declaration half of #22 (whose alias removal is П3.2), pairs #33–#36, and
`levelOptionsClasses()` for its two wrappers.

### П2.3 — CodeSmell, Cohesion, Size

Transcription only, twelve classes: `option-declared-vs-read.tsv` lines 3–10
(`CodeSmell`, eight classes), line 11 (`LcomOptions`), lines 31–33 (`Size`,
three classes). `LongParameterListOptions` (line 8) and the others here that
implement `ShorthandOptionKeysInterface` move their `threshold` /
`vo-threshold` declarations into the new method. Also carries the five test
implementations that live under these capabilities' own tests — three in
`CodeSmell/Unit`, two in `Size/Unit`, all five anonymous classes.

### П2.4 — Design, Maintainability, Security, Duplication, CircularDependency, ComputedMetrics

Transcription only, twelve classes: lines 2 (`CircularDependency`), 16
(`ComputedMetricRuleOptions`), 21–25 (`Design`, five classes), 26
(`Duplication`), 27 (`Maintainability`) and 28–30 (`Security`, three classes).
`GodClassOptions` (line 22, eight accepted keys) is the widest set in the tree
and is what fixes the "at most eight" claim in the overview. Also carries the
one anonymous test implementation under `Design/Unit/TypeCoverage`.

### П2.5 — Policy

`LayerViolationOptions`, `UnassignedClassOptions` and `InlineDirectiveOptions`
(lines 34–36), plus the two Policy READMEs. Carries pairs #23–#26, i.e. the
whole answered-by-the-class half.

### П2.6 — the test implementations that belong to no capability

Ten files: the four `tests/Analysis/Configuration/Fixtures/TestRuleOptions*`
classes, five under `tests/Analysis/Finding/` (one integration, four unit, two
of them anonymous) and `tests/Infrastructure/Unit/RulesCommandTest.php`. They
are a package rather than a footnote because they are the half of the
implementer population a production-only sweep does not see, and because six of
the sixteen are anonymous classes that no grep for a class name would find.

## What this stage leaves broken

- Between П1.1 and the end of П2.6 the tree does not compile. Only the union is
  offered for validation; no package here reports "green" on its own.
- After П2.6 the declarations are complete and **inert**: the factory has not
  been taught to read them, the two old interfaces are still implemented and
  still read, and no key changes what it does. That is the assertion this
  stage's gate run makes, and it is now true — the seven alias removals moved to
  П3.2 precisely so that it is.

## Test plan (no tests written here)

- One declaration test per capability package, table-driven over that package's
  classes: for each, the declared accepted set contains every key the class's
  `fromArray()` reads **outside a branch condition** — the `read_unguarded`
  column, not `read_unguarded ∪ read_branch_guarded`. Stage 04 states why the
  invariant is narrowed to that column and what it therefore cannot see. The
  oracle is the AST reader of `scripts/enumerate-rule-option-keys.php`, not a
  hand-typed list — stage 04 turns this into the standing guard.
- One declaration test over the ten level classes, with the same invariant and
  `measurement/level-declared-vs-read.tsv` as its subject; `threshold` is the
  key it exists to catch.
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
