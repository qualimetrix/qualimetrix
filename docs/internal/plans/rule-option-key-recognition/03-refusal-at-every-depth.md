# 03 — The walk, and what the refusal says

## What changes in one sentence

`RuleOptionsFactory::warnAboutUnknownKeys()` becomes a two-depth walk that
throws instead of logging, comparing each depth against the set the class at
that depth declared in stage 02.

## Which enumeration rows this closes, one by one

`measurement/merged-enumeration.md` groups 16 positions under M1, but that count
double-lists: rows 59, 60 and 64 also appear under M11, row 71 under M8, and
rows 83–87 under M5, while row 45 was refuted by the remeasure recorded in this
directory's `README.md`. The rows are therefore sorted here rather than
inherited.

**Closed by this stage** — the key is compared and refused:

| row | input                                                      | after                                                                                           |
| --- | ---------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| E48 | `{callable: {warning: 1, error: 2, exclude_paths: ["*"]}}` | refuse 3: `exclude-paths` is not in `callable`'s set                                            |
| E53 | `{callable: {warnign: 1, error: 2}}`                       | refuse 3                                                                                        |
| E54 | `{callable: {warnign: 1, errro: 2}}`                       | refuse 3, on the first key in document order                                                    |
| E55 | `{callable: {warn: 1, errors: 2}}`                         | refuse 3                                                                                        |
| E56 | `{callable: {WARNING: 1, ERROR: 2}}`                       | refuse 3 — upper case is not folded, so the key is genuinely unknown                            |
| E57 | `{callable: {max_warning: 1, max_error: 2}}`               | refuse 3, and the sentence names `callable`'s own set                                           |
| E58 | `{class: {warning: 1, error: 2}}`                          | refuse 3, symmetrically                                                                         |
| E59 | `{callable: 10}`                                           | refuse 3: a level slot takes a map of options                                                   |
| E61 | `class: {max_warning}` / `{maxWarning}` / `{max-warning}`  | all three accepted, and now **stated** — the declaration is kebab, the comparison is normalised |
| E73 | `--rule-opt='complexity.ccn:callable.warnign=1'`           | refuse 3, identical text to E53                                                                 |

**Closed as a byproduct, not aimed at:** enumeration row 34
(`{CALLABLE: {…}}` warning under the mangled name `c-a-l-l-a-b-l-e`) — the new
refusal prints the key exactly as the factory received it (`cALLABLE`) instead
of de-camelising it, so the mangling has no site left. Row 34 stays an M3 row;
only its text moves, and *The spelling the refusal answers in* below states why
`cALLABLE` and not `CALLABLE` is the honest answer.

**Decided, and deliberately not a refusal:** row E60, `{callable:}` (null).
An empty level block means the same thing as an omitted one, and refusing it
would refuse a harmless YAML idiom. Accepted in silence, stated in the website
configuration page (stage 04), and — because "accepted in silence" is exactly
the shape this plan is removing elsewhere — **carried as a regression case**, so
that a later tightening of the not-a-map branch has to delete a green test
rather than merely not notice.

**Decided, and it is a refusal:** `{callable: false}`, which the enumeration did
not address and which the not-a-map rule would otherwise swallow into E59's
generic sentence. Today `ComplexityOptions.php:57-60` tests
`isset($config[$callableKey]) && \is_array(...)`, so `false` is silently the
same as an omitted slot. A rule has a universal off-switch
(`rules: {X: false}` → `enabled: false`, `normalizeScalarConfig()`); a slot has
none, so `callable: false` is a plausible thing to write and today it does
nothing. It refuses, with the one sentence that says what to write instead:

```
Configuration error: Level "<slot>" of rule "<rule>" takes a map of options,
got bool. To switch one level off write "<slot>: {enabled: false}".
```

The hint is part of the `false` case only; `callable: 10` keeps the bare
not-a-map sentence, because no `{enabled: false}` was plausibly meant.

**Not closed, handed off by name:** E45 (refuted; what remains is ADR 0044's
open follow-up), E64 (`true` coerced to 1 — M11), E65/E66 (layer eviction and
top-level `threshold` disabling `class` — M11), E71 (`--rule-opt` without `=`
— M8), E83–E87 (`computed_metrics` entry keys — M5). Each is a *recognised*
key doing something unexpected, or a different reader entirely; none is a
recognition failure.

**M2's rows** are closed by stage 02's declarations plus this stage's refusal:
E39 (`enabled: false` warned falsely) stops warning; E40 (`enable: false`) and
E41 (`severity:` on a rule with no severity) become refusals; the
`project_namespaces` row of *Расхождения* п.2 becomes a refusal after the alias
removal.

## The walk

```php
// RuleOptionsFactory::create(), replacing step 5
private function refuseUnknownKeys(
    array $userConfig,          // NOT $merged — see below
    string $ruleName,
    string $optionsClass,
): void {
    $topLevel = $optionsClass::acceptedOptionKeys();
    $slots = is_a($optionsClass, HierarchicalRuleOptionsInterface::class, true)
        ? $optionsClass::levelOptionsClasses()
        : [];
    // ... walk depth 1; for a key naming a slot, walk depth 2 against
    //     $slots[$key]::acceptedOptionKeys(); implementation details
}
```

There is no authored-spelling side map among its arguments, and *The spelling
the refusal answers in* below is why: by this point every door has folded the
key, so there is nothing left to carry.

Five decisions inside it, each with its reason:

**It validates `$userConfig`, not `$merged`.** At `RuleOptionsFactory.php:124`,
`$merged = $userConfig === [] ? $defaults : $userConfig`, and `$defaults` comes
from `extractDefaults()`, which reads constructor default *values*. For a
hierarchical wrapper those values are objects —
`ComplexityOptions::__construct()` defaults `callable` to
`new MethodComplexityOptions()`. A depth-2 walk over `$merged` would either
fault on an object or validate the defaults against themselves. `$userConfig`
is what the user wrote and is the only correct subject; the current code
validates `$merged` only because at depth 1 the two agree whenever the user
wrote anything.

**The framework keys stay a top-level-only exception, and the walk owns their
list.** `suppress_paths`, `suppress_namespaces` and
`suppress_namespace_channels` are stripped by
`extractSuppressNamespaces()`/`extractSuppressPaths()` at
`RuleOptionsFactory.php:96-97`, before this point, so a correctly spelled one
never reaches the comparison. A *misspelled* one does, and becomes a hard
refusal. They are declared by no options class and must not be, so their source
at depth 1 is a constant of the factory beside the walk, and it is both compared
against and printed — see *Where the printed set comes from* below. Inside a
slot they stay unknown, and that is the right answer: E48's retired
`exclude_paths` at depth 2 is refused by the generic sentence because its
*replacement* is not valid there either.

**A slot's value that is not a map is answered before its keys are.** `null` is
accepted as an omitted slot, `false` refuses with the `{enabled: false}` hint,
and anything else refuses with the bare not-a-map sentence. This branch runs
before the depth-2 key walk, because a non-map has no keys to walk.

**`RetiredSuppressionOptions::refuseRuleOption()` runs first and stays at
depth 1.** It is called at line 95, before this walk, and its message names the
replacement key — migration text the generic sentence cannot produce. ADR 0047
owns it and this plan does not widen it: there is no depth-2 retired map,
because at depth 2 the retired key and its replacement are both invalid and one
sentence covers both.

**The refusal is thrown, not collected.** The first unknown key in document
order refuses, matching `validateStructure()`'s existing behaviour (enumeration
*Что осталось неизмеренным*, item 10). Collecting every unknown key first was
considered and rejected as a second reporting convention for one document;
if the product ever gains one, it gains it for the whole configuration file,
not for rule options alone.

## The refusal's code, stream and text

The exception class is chosen by the catch site, not by precedent.
`CheckCommand::execute()` catches, in order:
`ConfigLoadException|ArchitectureConfigurationException` → exit 3 with a
`Configuration error: ` prefix (`CheckCommand.php:172`);
`ArchitecturePreparationException` → exit 3 with its own prefix (`:179`);
`InvalidArgumentException` → exit 3, message verbatim and **without any prefix**
(`:190`); `Throwable` → exit 1, `Unexpected error: ` (`:201`).

**A correction to what an earlier revision of this plan claimed.**
`RetiredSuppressionOptions::refuseRuleOption()` throws
`InvalidArgumentException` (`src/Analysis/Configuration/RetiredSuppressionOptions.php:136`),
not `ConfigLoadException`. So the two halves of this mechanism do **not**
already share one route, and the plan may not lean on that as its reason. The
two routes reach the same exit code and print different framing, and this plan
does not unify them: doing so would edit ADR 0047's refusal for the sake of the
new one's symmetry, and the framing difference is small next to the migration
text that refusal carries. Instead both stderr texts become pinned by test, so
the difference is a decision on the record rather than a discovery.

**`ConfigLoadException`, chosen on its own merits.** It is the route for an
error *in the configuration document*, and it carries the `Configuration error: `
prefix that says so; an unknown key in `qmx.yaml` is exactly that.
`InvalidArgumentException` is the route the *classes* use for their own bespoke
refusals (pairs #23–#26), and keeping the generic and the bespoke
distinguishable is worth one import.

The three-way choice is about `check`. `create()` is also reached from
`baseline:explain` (`BaselineConfiguredThresholds.php:136`), where enumeration
row 129's measurement of `baseline:generate` says a `ConfigLoadException` from
the same document exits **1 on stdout**. The new refusal inherits that; it is
mechanism M6 and out of scope, and the test plan pins the `check` route only.

Text, at depth 1:

```
Configuration error: Option "<key>" is not an option of rule "<rule>".
Options here: <kebab list>.
```

at depth 2:

```
Configuration error: Option "<key>" is not an option of rule "<rule>" at level
"<slot>". Options at that level: <kebab list>. Other levels of this rule take
different options.
```

**Where the printed set comes from, at each depth.** At depth 2 it is exactly
`<level class>::acceptedOptionKeys()->acceptedForDisplay()`. At depth 1 it is
that of the options class **plus the three framework keys**
(`suppress_paths`, `suppress_namespaces`, `suppress_namespace_channels`), which
no options class declares and none ever will: they are stripped from
`$userConfig` at `RuleOptionsFactory.php:96-97`, before this walk, and never
reach `fromArray()`. They are legal at depth 1 and illegal inside a slot, so
their source is a factory constant beside the walk, and the walk adds them at
depth 1 only.

This is not cosmetic. A correctly spelled framework key never reaches the
comparison, but a typo in one does — `suppress_path` — and under this plan it
stops being a warning and becomes exit 3. A refusal that lists the allowed keys
without listing the three keys that are allowed would name the fix nowhere.
Today's warning has the same hole (`$availableOptions` is built from
`$defaults` plus `$acceptedExtraKeys`, with no framework keys), which is
survivable for a warning and not for a hard error.

No "did you mean". The printed set is the whole answer: at most eight keys at
depth 1 (`GodClassOptions`) and six at depth 2
(`NamespaceInstabilityOptions`), from `measurement/option-declared-vs-read.tsv`
and `option-level-slots.tsv` respectively. The three existing Levenshtein
implementations are named in the overview as out of scope; adding a fourth to
disambiguate a printed six-item list would be a copy bought for nothing.

The last clause of the depth-2 sentence exists for E57/E58 specifically: the
measured mistake is writing one level's vocabulary into another's slot, and a
reader who sees only "not an option at level `callable`" will try the same key
at `class`.

## The spelling the refusal answers in

An earlier revision of this plan promised that depth 1 would quote the author's
spelling and that `--rule-opt` preserved it. **Both halves are wrong, measured:**

- YAML and presets: `rules` is `PRESERVE_IMMEDIATE_CHILDREN`
  (`SectionNormalizationPolicy`, ADR 0009), which preserves the *rule slug* and
  resumes camel folding at every depth below it. So the option key is folded in
  the loader, at depth 1 as well as depth 2.
- `--rule-opt`: `RuleOptionsParser::parseRuleOption()` calls
  `ConfigKeySpelling::normalize()` on the option (`:153-156`) before the value
  ever reaches the factory. The dot does not shield the tail:
  `normalize('callable.max_warning')` returns `callable.maxWarning`, so
  `expandDotNotation()` at `RuleOptionsFactory.php:75` receives an already
  folded key at both depths.

So the door table has one row, not four: **every door folds separators and
lower-cases the first character before the factory exists; the letters
survive.** Two consequences, both stated rather than promised away:

- For a key whose letters are wrong — `warnign`, `errro`, `warn` — the folded
  spelling *is* the authored spelling, and the refusal is exact.
- For a key whose separators are wrong — `max_warnign` — the refusal says
  `maxWarnign`. That is the ADR 0044 limit at this seam, and the new ADR records
  it, pointing at the same open follow-up
  `docs/internal/plans/rule-vocabulary/FOLLOWUPS.md` already tracks. Making it
  survive would need a third `SectionNormalizationPolicy` case, which ADR 0044
  considered and rejected as "an exception keyed by an option's name inside a
  model whose unit is a section", **and** a spelling side-channel through the
  CLI parser. This plan reopens neither.

The refusal therefore prints the key **verbatim as it reaches the factory** and
applies no inverse transformation to it. `ConfigKeySpelling::rewriteLike()`
exists and is not used here: with no authored string to imitate, it has nothing
to key on.

Printing verbatim is also what retires the mangling in enumeration row 34.
Today the generic warning runs the unknown key through
`toCanonicalDisplayName()`, which de-camelises, so `CALLABLE` — folded to
`cALLABLE` — is printed `c-a-l-l-a-b-l-e`. Dropping that call for the *unknown*
key leaves `cALLABLE`, which is what the product actually received.
`toCanonicalDisplayName()` stays in use for printing the *allowed* set, where
the input is a declared kebab spelling and the transformation is exact. Row 34
stays an M3 row: only its text moves, and the folding itself is untouched.

Consequence for the comparison, and for the tests: both sides are normalised
before comparison — the declared kebab and the incoming key alike. Enumeration
row 61 measured the three spellings' equivalence on the YAML door only; the test
plan below adds the CLI door.

## Work packages

Two packages, **sequential**: П3.2 lands after П3.1. Their file sets are the
`П3.1` and `П3.2` row groups of `measurement/packages.tsv`, and their
intersection was taken machine-wise and is empty.

An earlier revision called them parallel with disjoint file sets. They were
neither. The four Options files П3.2 edits are four of the twenty that carry
`implements ShorthandOptionKeysInterface` / `AdditionalOptionKeysInterface`, so
a П3.1 that sheds those interfaces everywhere and a П3.2 that edits four of
those same files are two agents in one class. Sequencing and reassigning fix the
two halves of that with one move: **П3.1 excludes the four files, and П3.2 owns
their interface cleanup along with the alias removal.**

**П3.1 — the walk, the refusal, and the death of the two interfaces.**

Twenty-seven paths, in six groups:

- `src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php`;
- `src/Analysis/Finding/Contract/Rule/RuleOptionRefusalWording.php` (new — the
  sentences above, beside `ChannelLevelRefusalWording`, for the reason that
  file's own docblock gives: a refusal that names a level is a formulation that
  belongs next to the judge) and `src/Analysis/Finding/README.md`;
- the two deleted interface files, and the sixteen production Options classes
  that reference them outside П3.2's four;
- `tests/Analysis/Finding/Unit/RuleOptionsFactoryTest.php` and
  `tests/Analysis/Policy/Architecture/Unit/UnassignedClassOptionsTest.php`;
- `scripts/enumerate-rule-option-keys.php`, and the manifest pair
  (`docs/internal/modular-architecture-manifest.json` plus
  `docs/internal/generated/modular-architecture/`);
- `src/Analysis/Finding/Contract/Rule/ThresholdParser.php` — prose-only, no
  import to remove, added to this row group so the boundary stays checkable.

The script is in this package and not in stage 04, and it is not a cosmetic
inclusion: its `declaredKeys()` imports both deleted interfaces and calls their
statics, and `phpstan.neon` analyses `scripts` beside `src` and `tests`. Left
out, stage 03 could not claim a green `composer check`, and the file that
regenerates `measurement/` would be the one file in the tree that cannot run.
П3.1 rewrites `declaredKeys()` to read `acceptedOptionKeys()`, which also makes
the script and the guard agree on one source before stage 04 moves the reader.
The manifest pair is in this package for the reason the overview gives: this is
the landing unit's single manifest owner, and it carries both the two deleted
declarations and the new `RuleOptionRefusalWording`. The JSON is written here;
the generated directory is regenerated **after П3.2**, at the unit's close, by
this package's owner — until then four files still import declarations this
package deleted, and a generator run would measure that intermediate tree.

`LongParameterListOptions.php` is one of the sixteen production Options
classes above; `ThresholdParser.php` is the one prose-only file, listed on its
own above.

Depends on: all of stage 02.

**П3.2 — the seven alias removals** — is the subject of
`03-alias-removals.md`, which states its files, its two-edit shape and its own
test plan. It depends on П3.1 and lands after it.

## What this stage leaves broken

- Nothing structurally: after П3.1 and П3.2 the tree compiles and
  `composer check` is expected green. Between the two the tree does not
  compile — П3.1 deletes two interfaces that П3.2's four files still implement
  — which is the same landing-unit discipline stage 01 states, and neither
  package is offered for validation alone.
- At that intermediate commit the seven aliases are already **refused** rather
  than silently dropped: stage 02's declarations omit them, so П3.1's walk
  refuses them while the code that reads them is still present. That ordering
  is deliberate. The reverse order — removing the reads first — would leave a
  window in which the alias does nothing and only a warning says so, and `-q`
  silences a warning (enumeration row 51). A user who checks out the
  intermediate commit gets exit 3 and a sentence, in every verbosity.
- The website and `CHANGELOG.md` still describe the old contract; stage 04 owns
  that and must follow before the branch is offered for review.
- Enumeration row 51 — a warning silenced by `-q` — becomes moot for these
  positions, since a refusal is not a log line. Any other position that still
  warns keeps that behaviour, and the plan does not audit them.

## Test plan (no tests written here)

Regression case per closed position — E48, E53, E54, E55, E56, E57, E58, E59,
E61, E73 — plus E60 (`callable:` null, accepted) and `callable: false`
(refused with the `{enabled: false}` hint), plus:

- **The Fact 1 discriminator, as one test with two halves.** Top-level
  `warning` on `coupling.cbo` is accepted *and* changes the findings; the same
  key on `complexity.ccn` is refused with exit 3. Same input shape, opposite
  verdicts, both asserted, so a future simplification that unifies them
  reddens.
- **The Fact 2 discriminator.** `{class: {max_warning: 1}}` accepted and
  effective; `{class: {warning: 1}}` refused; `{callable: {warning: 1}}`
  accepted and effective; `{callable: {max_warning: 1}}` refused. Four
  assertions on one rule.
- **The Fact 3 pairs.** For each of #23–#26, exactly one sentence on stderr —
  the class's own — and no `Unknown option` line above it.
- **The Fact 4 slots.** `{callable: {threshold: N}}` and `{class: {threshold: N}}`
  accepted *and effective* on a complexity rule and on `coupling.cbo` — the ten
  pairs #27–#36 are the reason depth 2 does not refuse a documented key, and a
  test that only asserts "not refused" would pass against a declaration that
  accepted the key and dropped it.
- **Two refusal routes, both pinned.** The generic refusal prints
  `Configuration error: …` (`ConfigLoadException`, `CheckCommand.php:172`); the
  retired-key refusal prints its message with no prefix
  (`InvalidArgumentException`, `:190`). Both exit 3. The two texts are asserted
  in one test so that a later unification has to change an assertion rather than
  quietly reframe one of them.
- **The framework keys at depth 1.** `suppress_paths` is accepted; the typo
  `suppress_path` is refused **and the printed set contains `suppress-paths`**;
  `suppress_paths` written inside a slot is refused.
- **Door symmetry, and the spelling the refusal prints.** The same unknown
  depth-2 key written as YAML (`callable: {max_warnign: 1}`) and as
  `--rule-opt` (`complexity.ccn:callable.max_warnign=1`) refuses identically,
  and both print `maxWarnign` — the folded spelling, asserted verbatim, because
  that is the stated limit and an accidental improvement to it should redden.
  Separately, `max_warning` / `maxWarning` / `max-warning` are accepted through
  **both** doors (row 61 measured YAML only).
- **Routing.** The refusal exits 3 on stderr; it still exits 3 under `-q`
  (row 51 — originally measured with both streams empty, the sentence fully
  eaten by quiet; the sentence now survives on stderr under `-q` instead, per
  `01-refusal-envelope.md` §2.3), and it now answers with a parseable
  `{error, exit_code}` envelope on stdout under `--format=json` (row 52
  originally measured stdout empty, nothing to parse — the envelope is this
  round's addition, not a preexisting invariant); and it
  exits 3 under `--workers=2`. The last one matters because the enumeration left
  worker-process diagnostics unmeasured (item 7 of *Что осталось неизмеренным*);
  a `--workers=2` run of the warning case was taken while planning and the two
  warnings appeared once each on the parent's stderr, so options are built in
  the parent — the test pins that rather than trusting it.
- **The retired refusal still wins at depth 1.** `exclude_paths` as a rule
  option keeps ADR 0047's message naming `suppress_paths`, not the generic one;
  at depth 2 it gets the generic one.
- `composer gate -- --reference=<the commit stage 02 ended on>` GREEN with
  empty maps.
