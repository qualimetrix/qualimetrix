# 04 — The standing guard, and publication

## Goal

Stages 01–03 make the declaration correct once. This stage makes it stay
correct, and tells the outside world what changed.

## The guard: declared ⊇ read *outside a branch condition*, checked by a second witness

`measurement/option-declared-vs-read.tsv` and
`measurement/level-declared-vs-read.tsv` are snapshots. The invariant behind
them becomes a test:

> For every options class and every level class, the key set declared by
> `acceptedOptionKeys()` (both halves) contains every key its `fromArray()`
> reads **outside a branch condition** — the `read_unguarded` column of the two
> measurements, not `read_unguarded ∪ read_branch_guarded`.

**Why the invariant is narrowed to that column, and what it therefore cannot
see.** The wider form — declared ⊇ every key read anywhere — is not satisfiable
together with stage 02's decisions, and the contradiction is structural rather
than a wording slip. Six pairs (#4/#5/#9/#10/#14/#15) decide **refuse** for
top-level `warning`/`error` on the three complexity wrappers, so those keys are
in neither half of the declaration; and `ComplexityOptions::fromArray()` keeps
reading them at `:44` through `ThresholdParser::parse()`, inside the flat
branch, after П3.2 as before it. Declared ⊇ read and *refuse* cannot both hold
for one key. The measurement already carries the distinction the resolution
needs: `warning`/`error` appear in `read_branch_guarded` for those three classes
and in `read_unguarded` for `CboOptions`, where the decision is **declare**.

The narrowing costs one thing, and it is named here rather than discovered
later: **a key read only inside a branch is invisible to this guard.** Six keys
are in that position. They are pinned by behaviour instead — the Fact 1
discriminator of `03-refusal-at-every-depth.md` asserts that top-level `warning`
is refused on `complexity.ccn` and accepted-and-effective on `coupling.cbo`, in
one test with two halves. That is a second witness of a different kind: the AST
reader cannot produce it, and it fails if either verdict moves.

The alternative was to make the wider invariant true by deleting the reads —
dropping `RuleOptionKey::WARNING`/`ERROR` from the three wrappers'
`ThresholdParser::parse()` call, which after П3.2 opens on `threshold` alone and
so would need the call replaced by a direct read. Rejected: it is a
behaviour-bearing edit to three classes, in the same package as the alias
removal, bought for a strictly stronger *static* check of six keys that the
behavioural test already pins — and CLAUDE.md's validation order puts the cheap
signal first, not the invasive one. If the flat branch is ever removed for its
own reasons, the invariant can widen with it.

**The oracle is not a hand-written list** — a hand-written list is the same
author filling in both the claim and its check, and it passes its own guard
(`MEMORY.md`, *Two-witness enumeration*). It is the AST reader already written
for the measurement: `scripts/enumerate-rule-option-keys.php` derives the read
side by parsing `fromArray()` bodies, and the declared side by calling
`acceptedOptionKeys()` (П3.1 rewrites `declaredKeys()` to do that when the two
old interfaces die). The declaration and its oracle then come from two different
places.

**Where the reader lives.** It moves out of the script into
`tests/Analysis/Finding/RuleConfiguration/Support/`, under
`Qualimetrix\Tests\` (composer `autoload-dev`, already covering `tests/`), and
the script requires it from there. This follows CLAUDE.md's rule that tests
follow their owning subject with support as a subdivision inside it: the
invariant is Finding's — it is the factory's contract that is being kept
honest — and the reader has exactly two consumers, the guard and the script
that regenerates the measurement.

**The reader carried one cross-class leak, and it is fixed in this revision
rather than inherited.** `FromArrayReader::read()` reset `reading`, `methods`,
`visited` and `guarded` but not `$locals`, so a literal bound to a local
variable in one class could leak into the *next* class read by the same
instance — a class whose own unresolvable `$config[$var]` read would silently
report the previous class's literal instead of a blind spot. `$this->locals = []`
was added to the reset block and all four measurement tables were regenerated:
**byte-identical**, so the defect was real and this population never triggered
it (every read in the tree resolves without needing another class's leftover
local). The guard inherits a reader without it, and П4.1 carries a control
built from two classes, not one: the first binds a local variable to a
literal, the second reads `$config[$var]` for its own non-literal `$var`, and
the control asserts the second class's blind spot does not carry the first
class's key. Reading the *same* class twice cannot exercise this defect — a
repeated read starts from the same locals every time — so the control must
read two distinct classes in one reader instance, in that order, for the
assertion to be capable of failing.

**The guard's blind spots, stated as its limit rather than discovered later.**
`measurement/option-enumeration-blind-spots.tsv` names 16 sites the reader
cannot resolve to a literal key, **across the 35 options classes only**: 14
`nested-delegation` (a wrapper handing `$config` to a level class, five classes)
and 2 `dynamic-key` (`LayerViolationOptions` line 120, the `foreach` over a
constant map that hid pairs #24–#26). For the ten level classes the same reader
reports **zero** blind spots of any kind, and that is now measured rather than
assumed: `measurement/level-declared-vs-read.tsv` carries a `blind_spots` column
per level class, and every row is `-`. Consequences the guard must state in its
own failure message:

- nested delegation is *why* the level classes are walked separately; with
  `LevelOptionsInterface::acceptedOptionKeys()` in place, the 14 sites are
  covered by the child's own declaration rather than by the parent's reader.
- a dynamic key remains invisible. The guard therefore asserts
  declared ⊇ read, not equality: a class may declare a key the reader cannot
  see (which is what #24–#26 are), and a *second* assertion — declared keys
  that neither the reader saw nor a test exercises — is deliberately not made,
  because it would fail on exactly the honest case.
- **the reader resolves a class to a file, then takes the first `Class_` node
  in that file, not the node named by the class it was asked for.**
  `classNode()` locates the file through `ReflectionClass::getFileName()` and
  then does `findFirstInstanceOf($ast, Class_::class)` — the requested class
  name plays no part in node selection. On this population it is silent: the
  script's own `files-holding-more-than-one-class` count is zero, so every one
  of the 45 classes is alone in its file. It stops being silent the moment two
  options classes (or an options class and an anonymous test fixture) share a
  file, at which point the reader would attribute the second class's reads to
  the first's declaration, with no blind spot raised. **The guard itself must
  assert this precondition rather than assume it**: П4.1 moves the
  `files-holding-more-than-one-class` count out of the script and into the
  guard test as `assertSame(0, ...)`, so a future file that violates it fails
  the guard instead of silently mis-attributing reads.

A companion assertion keeps the interfaces П3.1 deleted dead:
`ShorthandOptionKeysInterface` and `AdditionalOptionKeysInterface` must not
exist, so a reintroduction is a red test rather than a review catch.

**The invariant is false on the tree stage 02 leaves behind, and stays false
until П3.2 closes.** `declared ⊇ read_unguarded` is the property this guard
checks, but it is not the property stage 02's own tree has: by
`measurement/option-declared-vs-read.tsv` rows 12, 13, 14 and 19,
`warningThreshold`/`errorThreshold` on the three complexity wrappers and
`projectNamespaces` on `DistanceOptions` sit in `read_unguarded` and are
**not** declared — stage 02 resolves those seven keys as *remove-then-refuse*
(`02-declarations-per-capability.md`, *Test plan*), which means "not declared
here, made unreadable in П3.2," not "declared here." The invariant becomes
true for all 45 classes only at the close of П3.2, when `ThresholdParser::parse()`
and `DistanceOptions::fromArray()` stop reading those seven keys unguarded.
This does not produce a red run: no test in this plan asserts the guard
before stage 04, and П4.1 — the package that writes the guard — lands after
П3.2. An implementer who runs the guard's assertion against a checkout frozen
between stages 02 and 03, or who tries to write the declaration test early,
should read a red result here as this documented gap, not as a defect in
their own package.

## Regression cases: one per position where a key can go unrecognised

The population is not invented here; it is the list in
`03-refusal-at-every-depth.md` and `03-alias-removals.md` plus the pairs table
of `02-declarations-per-capability.md`:

| group                                            | cases | source                                                               |
| ------------------------------------------------ | ----- | -------------------------------------------------------------------- |
| depth-2 positions closed                         | 10    | E48, E53–E59, E61, E73                                               |
| slot values that are not a map                   | 2     | E60 (`null`, accepted) and `false` — E59 is counted in the row above |
| the 36 pairs                                     | 36    | `02`, one per row                                                    |
| Fact 1 / Fact 2 / Fact 3 / Fact 4 discriminators | 4     | `03`, *Test plan*                                                    |
| the two refusal routes and their stderr framing  | 1     | `03`, *Test plan* — `ConfigLoadException` vs not                     |
| the three framework keys at depth 1              | 3     | `03`, *Test plan* — correct, typo'd, in a slot                       |
| door symmetry and the folded spelling            | 3     | `03`, *Test plan*                                                    |
| routing (`-q`, `--format=json`, `--workers=2`)   | 3     | rows 51, 52, and the unmeasured worker question                      |
| the universal off-switch over every rule         | 1     | `02`, *Test plan* — `rules: {<rule>: false}`                         |
| retired refusal precedence, both depths          | 2     | ADR 0047 interplay                                                   |

The 19 *declare* pairs assert the positive: the key works, changes the outcome,
**and** no line is written to stderr. The 6 *refuse* and 7 *remove-then-refuse*
pairs assert exit 3 and the sentence. The 4 *answered-by-the-class* pairs assert
exactly one sentence, the class's own.

The ten level-class pairs (#27–#36) are the group most easily faked: a test that
asserts only "`threshold` is not refused" passes against a declaration that
accepts the key and drops it. Each asserts the finding it produces, not the
absence of a refusal.

## Documentation, and what its scope grew to

- **`website/docs/rules/`, EN and RU together** — `complexity.md` gains a
  statement that top-level `warning`/`error` are not options of a hierarchical
  rule; a sweep of `website/` found zero occurrences of `warning_threshold` /
  `error_threshold` there today, so there is nothing to remove, only the
  statement to add. `coupling.md` gains the four keys pairs #17/#18/#20/#21
  make public. That last item is a cost of the decision, not an afterthought:
  those keys are documented nowhere today because `CboOptions`' docblock
  declares them deliberately unadvertised, and the docblock is rewritten in
  П2.2 rather than left contradicting the declaration.
- **`website/docs/getting-started/configuration.md`, EN and RU** — the
  configuration page states the three equivalent spellings (row 61), that an
  empty level block is the same as an omitted one (row E60), and that an
  unknown option key is a configuration error at any depth.
- **`qmx.yaml.example`** — the commented examples at lines 57, 252–258 and 333
  are uncommented one at a time and run, because a commented example is invisible
  to both checkers used in the overview's reddening measurement.
- **`src/Core/README.md`, lines 304–351** — it documents `RuleOptionsInterface`,
  `HierarchicalRuleOptionsInterface`, `LevelOptionsInterface` and a section for
  `AdditionalOptionKeysInterface`, naming `ShorthandOptionKeysInterface` beside
  it. Two of those four no longer exist after П3.1 and the other two gain a
  static, so this file is in П4.3's file set. It is the one documentation site
  the earlier revision of this plan missed, and it is outside every capability
  README, which is why a sweep of capability READMEs did not reach it.
- **Capability READMEs** are updated by the П2.x package that owns them, not
  here — they describe the classes that package declares. `src/Analysis/Finding/README.md`
  belongs to П1.1 and П3.1: the paragraph describing
  `ThresholdAwareOptionsInterface::warningBoundary()` gains its sibling
  paragraph about `acceptedOptionKeys()`, in the same shape.
- **`CHANGELOG.md`, `Breaking`** — four entries, each naming old and new
  surface: the seven removed aliases; top-level `warning`/`error` on the three
  complexity rules becoming an error; a level slot written as `false` becoming
  an error instead of a silent no-op; and an unknown rule option key at any
  depth becoming exit 3 instead of a warning or silence.
- **ADR** — the new one described in `00-overview.md`. Its migration section is
  written from the consumer's side: what a `qmx.yaml` written against the old
  contract does now, and the mechanical edit for each of the ten changed
  spellings.

## Work packages

Three packages in parallel plus one that closes the branch. **Each package's
file set is its row group in `measurement/packages.tsv`**, and the intersection
of the three parallel ones was taken machine-wise and is empty.

**П4.1 — the guard.** Three files: the reader moved out of the script into
`tests/Analysis/Finding/RuleConfiguration/Support/FromArrayKeyReader.php`, the
guard itself in
`tests/Analysis/Finding/RuleConfiguration/Unit/DeclaredOptionKeysCoverReadKeysTest.php`
(including the dead-interface assertion, the two-class cross-contamination
control for `$locals`, and the `files-holding-more-than-one-class == 0`
assertion), and `scripts/enumerate-rule-option-keys.php`, which now requires the
moved reader through composer's `autoload-dev`. The script's `declaredKeys()`
was already rewritten onto `acceptedOptionKeys()` in П3.1; this package moves
the reader and nothing else about it.

**П4.2 — the regression cases.** Three new files, named individually rather than
by directory: the refusal cases and the declaration-coverage cases under
`tests/Analysis/Finding/RuleConfiguration/Unit/`, and the CLI-door cases under
`tests/Infrastructure/Console/Unit/`. `tests/Analysis/Finding/RuleConfiguration/`
does not exist today — it is created by П4.1 and П4.2 together, and the file
names are given so that "every other file under that directory" cannot silently
mean a file another package owns. `RuleOptionsFactoryTest.php` is **not** in
this package: it lives at `tests/Analysis/Finding/Unit/` and belongs to П3.1.

**П4.3 — documentation and ADR.** The website pages (EN and RU),
`qmx.yaml.example`, `CHANGELOG.md`, the new ADR, and `src/Core/README.md`.
Capability READMEs are not here; each belongs to its П2.x package.

**П4.4 — validation and gate.** No files of its own. Runs after 4.1–4.3 land.

## Validation, in the order a failure is cheapest to read

1. Per package: `composer cs-check`, scoped PHPStan, the package's own tests.
2. Before the aggregate: full PHPStan, `composer architecture:check`, and
   `bin/qmx check src/ --workers=0 --fail-on=warning --format=json`. The last
   one is the dogfooding check that the cure did not redden this tree — the
   planning-time measurement says it will not, and this is where that
   prediction is settled rather than assumed.
3. `composer check` once, whole.
4. `composer gate -- --reference=<the commit this plan starts from>`, expected
   GREEN with empty maps and no declared delta: no channel, finding field or
   published name moves in this plan. A GREEN run here proves the normalization
   list is complete for these steps, not that any single step was safe — each
   stage's own gate run, against the previous stage's commit, is what proves
   the step.
5. `composer gate:controls` is **not** re-run: the comparator is untouched.
6. Review per `dvizh-vr-workflow:review` — contract change, so both the plan
   review that precedes execution and the execution review that follows it.

## What this stage leaves broken

Nothing intended. Two things it explicitly does not do, restating the
overview so a reader of this file alone is not misled:

- the eleven other mechanisms of `measurement/merged-enumeration.md`, and in
  particular M6's exception routing — `validateNumericFields()` still throws
  `RuntimeException` and still exits 1 under `Unexpected error:`, in the very
  method this plan rewrites;
- the two crash defects (`computed_metrics` with `levels:` as a map; a
  non-numeric threshold routed as a tool crash), and the narrowed
  `suppress_namespace_channels` question, which after its remeasure is ADR
  0044's open follow-up and not a defect of this seam.

`measurement/merged-enumeration.md` stays as it is. This directory's
`README.md` gains one line under *Status* naming which mechanisms the plan
closed and pointing at the ADR — the enumeration is evidence and is not edited
to match a treatment.
