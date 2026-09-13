# 0049. A Rule Option Key Is Recognised at Every Depth, or Refused

**Date:** 2026-09-09
**Status:** Accepted

## Context

A rule's options are written at two depths. `rules: {complexity.ccn: {...}}` is
the first; `rules: {complexity.ccn: {callable: {...}}}` is the second. Only the
first was ever compared against anything, and what it was compared against was
not the set the rule reads.

Both halves were measured before this decision was taken, on a two-class probe,
each run separating `stdout` from `stderr`:

**The two depths behaved differently, and the deeper one was silent.** A level
name the rule does not have — `method:` where the rule takes `callable:` —
produced a warning on `stderr` and no threshold. A *typo inside* a level slot —
`callable: {warnign: 1, error: 2}` — produced nothing at all: the `warning`
half was dropped, the neighbouring `error: 2` applied, and the run looked
configured. `RuleOptionsFactory::warnAboutUnknownKeys()` iterated
`array_keys($merged)`, which is the top level and nothing below it.

The silence had already cost the project its own documentation: `README.md` and
`qmx.yaml.example` taught `method:` — a spelling that does nothing — for long
enough that the error was found by re-measuring the product, not by using it.

**The set a key was compared against was not the set the rule reads.** The
declared set was derived by reflection over constructor parameters, plus two
opt-in interfaces (`ShorthandOptionKeysInterface`,
`AdditionalOptionKeysInterface`) for what reflection could not see. Reflection
cannot see into a method body, and `fromArray()` is a method body. The gap ran
in both directions and neither direction was benign:

- `coupling.cbo` read `warning`/`error` in the *condition* of its flat branch,
  so the keys worked — and warned, because they were undeclared.
- The three complexity wrappers read the same two spellings *inside* a branch
  whose condition never opens on them, so the keys did nothing — and warned
  with an identical sentence. One message, two opposite meanings.
- All ten level classes read `threshold` — through `ThresholdParser::parse()`'s
  default `$thresholdKey`, named by no constructor — while the website teaches
  `callable: {threshold: N}` and it works. A comparison built from the old
  derivation would have refused a documented, working key in ten places.

The population is 45 production classes (35 rule options plus 10 level options)
and 16 test implementations, enumerated by an AST walk of `src/` and `tests/`
cross-checked against the container's rule registry.

## Decision

**An unrecognised rule option key is refused with exit 3 at every depth it can
be written at, and the set it is compared against is declared by the class that
reads it.**

Four parts.

**1. The key set becomes a declaration, following ADR 0038's pattern.** A class
that holds something says what it is, and the reader asks instead of guessing.
`RuleOptionsInterface` and `LevelOptionsInterface` gain

```php
public static function acceptedOptionKeys(): RuleOptionKeySet;
```

and `HierarchicalRuleOptionsInterface` gains

```php
/** @return array<string, class-string<LevelOptionsInterface>> */
public static function levelOptionsClasses(): array;
```

which names the level options class behind each slot, keyed by the slot name
the user writes. It is declared rather than derived because nothing in the tree
makes a constructor parameter's name and its type agree.

`RuleOptionKeySet` holds two disjoint halves. *Accepted* keys are read here and
printed in the "options here" sentence. *Answered by the class* keys are
recognised only so that `fromArray()` may refuse them in its own words — the
`architecture.unassigned-class` and `architecture.layer-violation` cases, which
carry migration text a generic sentence cannot. A reader neither warns nor
refuses on that half.

`ShorthandOptionKeysInterface` and `AdditionalOptionKeysInterface` are deleted.
They existed to patch a derivation that no longer happens.

**2. The comparison acquires a second depth, and the allowed set is per (class,
slot).** There is no single list of "keys allowed at a level". `complexity.ccn`
takes `warning`/`error` at `callable` and `max-warning`/`max-error` at `class`;
each slot's set comes from the class that slot names. A slot holding something
that is not a map of options is refused too, `false` with a sentence of its own
(`callable: false` reads like an off-switch and is not one).

**3. The refusal is `ConfigLoadException`, not a new exception class.** It
inherits `check`'s existing routing for that type: `Configuration error: …` on
`stderr`, exit 3. `-q` does not lower the exit code. Reusing the type keeps the
error-routing mechanism whole; repairing that mechanism is a separate subject
and is not started here.

**4. `RetiredSuppressionOptions` keeps its own named refusal and keeps running
first.** ADR 0047 established a refusal for the five retired `exclude_*`
spellings, and its message carries migration text ("write `suppress_paths`")
that "unknown key here, allowed keys are …" cannot. Specialisation before
generalisation, one mechanism, not two.

**5. The retired refusal keeps a framing of its own, and this decision does not
unify the two.** The generic refusal above is a `ConfigLoadException`, which
`check` prints as `Configuration error: <message>`. The retired one is raised in
two places with two types: `YamlConfigLoader` refuses it before folding, through
`RetiredSuppressionOptions::refuseInRules()`, as a `ConfigLoadException` — so it
carries the prefix; `--rule-opt` refuses it in `RuleOptionsParser`, through
`RetiredSuppressionOptions::refuseRuleOption()`, as an `InvalidArgumentException`,
which `check` prints verbatim. The same mistake therefore reads as a
configuration error through the file and as a bare sentence through the flag.
`RuleOptionsFactory` calls `refuseRuleOption()` once more as a backstop. Measured,
nothing reaches it first today: every file — `qmx.yaml`, a `--config` document and
a preset alike — passes the loader's check, `--rule-opt` passes the parser's, and
no `#[CliAlias]` the product ships names a retired key at all.

Left as it is rather than repaired. Unifying the framing means changing which
exception type a live refusal path raises, which is the error-routing subject
decision 3 above already declines to open here. Recorded so the asymmetry is a
decision on the record rather than an accident nobody named; the door-symmetry
test pins the two framings as they are.

### Why a new ADR rather than a widening of ADR 0047

0047's subject is the rename of two suppression mechanisms; its refusal clause
is scoped by name to the five spellings that step retired. Widening it in place
would make an accepted ADR say something its context, decision and consequences
never argued for. The decision here has two halves 0047 does not touch — the
key set becomes a declaration, and the comparison acquires a second depth — so
it earns a record of its own, and 0047 stays true about what it decided.

### Alternatives rejected

**Declare every key that is read anywhere.** Rejected on measurement: for six
keys (`warning`/`error` on the three complexity wrappers) the read sits inside
a branch whose condition never opens on them, so declaring them would advertise
a key that does nothing. The identical warning text on `coupling.cbo`, where
the same spellings *do* work, is what made this visible.

**Remove the reads instead, so that "declared ⊇ read" holds everywhere.**
Rejected: a behaviour-bearing edit to three classes, bought for a stronger
*static* check of six keys that a behavioural test already pins.

**Keep warning at depth 1 and add warning at depth 2.** Rejected: a warning
leaves the exit code at 0, `-q` silences the line, and CI runs quiet — so a
configuration that does not apply stays invisible, which is the defect this
decision exists to close. A refusal under `-q` is silent too, but it exits 3.

**Add a "did you mean" suggestion.** Rejected: at a rule-option position the
widest set any class declares is eight keys, and six inside a slot. The
sentence at depth 1 prints three more than the class declared — the framework
keys belong at that position and nothing declares them — so the widest refusal
a user can see names eleven keys at depth 1 and six inside a slot. It is
printed in full, which is strictly more information than a single guess. The project's
three existing Levenshtein hints stay where they are; no fourth is added.

**Keep the seven legacy aliases.** Rejected under CLAUDE.md's *Backward
Compatibility Policy*: fewer surfaces is the goal, and a removed option beats
an alias. Each of the seven has a declared spelling that does the same thing.

### The named limit: the refusal answers in the folded spelling

The refusal prints the key **as the factory received it**, which is not
necessarily as the user typed it. Both doors fold separators before the factory
is reached — the YAML loader and `--rule-opt` alike, through
`ConfigKeySpelling::normalize()`, so `callable.max_warning` arrives as
`callable.maxWarning`. Measured, both doors:

```
class: {max_warnign: 1}                          → Option "maxWarnign" is not an option …
--rule-opt="complexity.ccn:callable.max_warnign=1" → Option "maxWarnign" is not an option …
```

There is no authored spelling left to quote and no inverse worth guessing.
The letters — which is what a typo gets wrong — survive the fold intact, so the
sentence still identifies the key the user meant. The *allowed* set is printed
in the canonical kebab spelling classes declare, because that is the spelling
users type.

This is a spelling-normalisation asymmetry that this decision deliberately does
not treat. It does not reopen ADR 0044's rule that identifier-keyed options keep
the author's spelling: a rule option key is not identifier-keyed.

One consequence of the same mechanism, in the opposite direction: snake, camel
and kebab spellings of one option key are equivalent on input at both depths
(`max_warning` / `maxWarning` / `max-warning` all apply). That was true before
and undocumented; it is now stated on the configuration page.

## Consequences

### For a consumer: what a `qmx.yaml` written against the old contract does now

A document that was correct before is still correct. A document that contained
one of the spellings below used to run — silently or with a warning — and now
stops with `Configuration error:` and exit 3, naming the key and listing the
options allowed at that exact position.

**The ten changed spellings, each with its mechanical edit:**

| written before                                 | did                  | write instead                                        |
| ---------------------------------------------- | -------------------- | ---------------------------------------------------- |
| `complexity.ccn: {warning_threshold: N}`       | worked (alias)       | `complexity.ccn: {callable: {warning: N}}`           |
| `complexity.ccn: {error_threshold: N}`         | worked (alias)       | `complexity.ccn: {callable: {error: N}}`             |
| `complexity.cognitive: {warning_threshold: N}` | worked (alias)       | `complexity.cognitive: {callable: {warning: N}}`     |
| `complexity.cognitive: {error_threshold: N}`   | worked (alias)       | `complexity.cognitive: {callable: {error: N}}`       |
| `complexity.npath: {warning_threshold: N}`     | worked (alias)       | `complexity.npath: {callable: {warning: N}}`         |
| `complexity.npath: {error_threshold: N}`       | worked (alias)       | `complexity.npath: {callable: {error: N}}`           |
| `coupling.distance: {project_namespaces: […]}` | worked (alias)       | `coupling.distance: {include_namespaces: […]}`       |
| `complexity.ccn: {warning: N, error: M}`       | **nothing** (warned) | `complexity.ccn: {callable: {warning: N, error: M}}` |
| `complexity.cognitive: {warning: N, error: M}` | **nothing** (warned) | `complexity.cognitive: {callable: {…}}`              |
| `complexity.npath: {warning: N, error: M}`     | **nothing** (warned) | `complexity.npath: {callable: {…}}`                  |

One detail the table cannot carry: on the three complexity rules the retired
aliases opened the *flat* branch, and that branch disables the class level as a
side effect. Moving to `callable:` leaves the class level at its defaults. A
consumer who relied on the side effect writes it out —
`class: {enabled: false}` — which is what it always meant. `threshold: N` is
the shorter replacement where warning and error were being set to one value;
it opens the same flat branch and keeps the class level off.

`coupling.distance: {include_namespaces: […]}` is an exact replacement: the two
spellings were arms of one `??` chain and produced identical results.

Two further shapes change without being renamed:

- **A level slot written as `false`** (`callable: false`) used to be a silent
  no-op. It is refused, with the sentence naming the working form,
  `callable: {enabled: false}`. An empty or omitted slot (`callable:` with no
  body) is unchanged and still means "leave this level at its defaults".
- **Any other unrecognised key, at either depth**, used to warn (depth 1) or
  vanish (depth 2). It now stops the run. Where the old behaviour was a warning
  and the configuration was in fact doing nothing, this converts a line nobody
  read into a message that must be answered.

Reading the refusal: the sentence lists the options allowed **at that
position**, and adds "Other levels of this rule take different options" inside
a slot, because the measured mistake is one level's vocabulary written into
another level's slot.

Two keys become newly documented rather than newly broken:
`coupling.cbo: {warning, error}` and `coupling.instability: {max-warning,
max-error}` worked at the top level all along while their docblock called them
deliberately unadvertised. They are now declared and documented.

### For the project

- The declaration and its oracle come from two different places: a guard test
  derives the read side by parsing `fromArray()` bodies and the declared side
  by calling `acceptedOptionKeys()`, and asserts declared ⊇ read outside a
  branch condition. A key read only inside a branch condition is invisible to
  that guard, by construction; those six keys are pinned by a behavioural test
  instead, which asserts that top-level `warning` is refused on `complexity.ccn`
  and accepted-and-effective on `coupling.cbo`.
- A new options class must declare its keys or the guard reddens. A
  reintroduction of either deleted interface is a red test, not a review catch.
- Every configuration document this repository tracks — `qmx.yaml`,
  `qmx.yaml.example`, the three presets and all seventeen gate corpus cases —
  was checked against the strictest form of the rule before it was taken: zero
  unknown keys at either depth.
- The refusal inherits whatever routing its command already has.
  `RuleOptionsFactory::create()` is also reached from `baseline:explain`, where
  a `ConfigLoadException` from the same document exits 1 on `stdout`. That
  divergence is mechanism M6 of the enumeration and is untouched here; only the
  `check` route is pinned by a test.
