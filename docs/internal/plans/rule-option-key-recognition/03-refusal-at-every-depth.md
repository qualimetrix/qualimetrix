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
refusal quotes the authored spelling at depth 1 instead of de-camelising it,
so the mangling has no site left. Row 34 stays an M3 row; only its text moves.

**Decided, and deliberately not a refusal:** row E60, `{callable:}` (null).
An empty level block means the same thing as an omitted one, and refusing it
would refuse a harmless YAML idiom. Accepted in silence, and stated in the
website configuration page (stage 04).

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
    array $authoredSpellings,   // normalised key => the spelling the author wrote
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

Four decisions inside it, each with its reason:

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

**The framework keys stay a top-level-only exception.** `suppress_paths`,
`suppress_namespaces` and `suppress_namespace_channels` are stripped by
`extractSuppressNamespaces()`/`extractSuppressPaths()` before this point and are
read at depth 1 only. So inside a slot they are unknown, and that is the right
answer — E48's retired `exclude_paths` at depth 2 is refused by the generic
sentence because its *replacement* is not valid there either.

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
`InvalidArgumentException` → exit 3, message verbatim (`:190`);
`Throwable` → exit 1, `Unexpected error: ` (`:201`).

The three-way choice is about `check`. `create()` is also reached from
`baseline:explain` (`BaselineConfiguredThresholds.php:136`), where enumeration
row 129's measurement of `baseline:generate` says a `ConfigLoadException` from
the same document exits **1 on stdout**. The new refusal inherits that; it is
mechanism M6 and out of scope, and the test plan pins the `check` route only.

**`ConfigLoadException`.** It is the class `RetiredSuppressionOptions` already
throws from inside this same factory, so the two halves of one mechanism take
one route and print one framing. `InvalidArgumentException` would also reach
exit 3 but is the route the *classes* use for their own bespoke refusals
(pairs #23–#26), and keeping those distinguishable is worth one import.

Text, at depth 1:

```
Configuration error: Option "<authored>" is not an option of rule "<rule>".
Options here: <kebab list>.
```

at depth 2:

```
Configuration error: Option "<key>" is not an option of rule "<rule>" at level
"<slot>". Options at that level: <kebab list>. Other levels of this rule take
different options.
```

and for a slot whose value is not a map (E59):

```
Configuration error: Level "<slot>" of rule "<rule>" takes a map of options,
got <type>.
```

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

## Authored spelling — where it survives and where it does not

ADR 0047 requires a refusal to answer in the spelling its author wrote. Where
that spelling still exists at this point differs by door, and the plan states
both rather than pretending one rule:

| door                | depth 1                                                       | depth 2                                                                                                                        |
| ------------------- | ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `qmx.yaml` / preset | lost at `RuleOptionsFactory.php:71` (`normalizeKeys`)         | already lost in the loader — `rules` is `PRESERVE_IMMEDIATE_CHILDREN` (ADR 0009), so everything under a rule slug is camelised |
| `--rule-opt`        | preserved — `expandDotNotation()` at `:75` does not normalise | preserved, same reason                                                                                                         |

So: **depth 1 carries the authored spelling**, obtained by having
`normalizeKeys()` return the normalised map together with a
`normalised => authored` side map instead of discarding it, and the refusal
quotes it. That alone retires the `c-a-l-l-a-b-l-e` mangling, because the
lossy `toCanonicalDisplayName()` stops being applied to the *unknown* key (it
stays in use for printing the *allowed* set, where the input is a constructor
parameter name and the transformation is exact).

**Depth 2 from YAML quotes the camelised key**, because the section
normalisation policy consumed the author's spelling before the factory existed.
Making it survive means a third `SectionNormalizationPolicy` case, which ADR
0044 considered and rejected as "an exception keyed by an option's name inside a
model whose unit is a section". This plan does not reopen that. The new ADR
records the limit explicitly, so it is a stated cost rather than a bug report
waiting to be filed.

Consequence for the comparison, and for the tests: the same written key arrives
snake from `--rule-opt` and camel from YAML at depth 2, so **both sides are
normalised before comparison** — the declared kebab and the incoming key alike.
Enumeration row 61 measured the three spellings' equivalence on the YAML door
only; the test plan below adds the CLI door.

## Work packages

**П3.1 — the walk and the refusal.** Sequential; both files are the same file
group and cannot be split without two agents editing one method.

Files:

- `src/Analysis/Finding/RuleConfiguration/RuleOptionsFactory.php`
- `src/Analysis/Finding/Contract/Rule/RuleOptionRefusalWording.php` (new — the
  sentences above, beside `ChannelLevelRefusalWording`, for the reason that
  file's own docblock gives: a refusal that names a level is a formulation that
  belongs next to the judge)
- `src/Analysis/Finding/README.md`
- `src/Analysis/Finding/Contract/Rule/ShorthandOptionKeysInterface.php` (deleted)
- `src/Analysis/Finding/Contract/Rule/AdditionalOptionKeysInterface.php` (deleted)
- the twenty Options classes and two test files that reference them, for the
  `implements`/`use` lines only — enumerated in `01-key-set-contract.md`
- `tests/Analysis/Finding/Unit/RuleOptionsFactoryTest.php`

The two interfaces die here rather than in stage 01 because the factory is
their last reader, and `is_a()` against a class string that no longer exists
returns `false` without a word — deleting them earlier would have silently
emptied the shorthand set. `ThresholdParser.php:27` and
`LongParameterListOptions.php:32` mention them in prose and are updated with
it.

Depends on: all of stage 02. Parallel with: П3.2.

**П3.2 — the seven alias removals.** Pairs #2, #3, #7, #8, #12, #13 (the
`warning_threshold`/`error_threshold` entry condition of the three complexity
wrappers) and #22 (`project_namespaces` on `coupling.distance`).

Files: `src/Analysis/Evidence/Complexity/ComplexityOptions.php`,
`CognitiveComplexityOptions.php`, `NpathComplexityOptions.php`,
`src/Analysis/Evidence/Coupling/DistanceOptions.php`, and the website pages
those four teach from.

Depends on: all of stage 02. **Parallel with П3.1** — disjoint file sets. It
lands in this stage rather than stage 02 so that the removal and the refusal
that explains it reach a user together; stage 02's declarations already omit
the seven keys, so the removal moves nothing else.

## What this stage leaves broken

- Nothing structurally: after П3.1 and П3.2 the tree compiles and
  `composer check` is expected green. Between the two, whichever lands first
  leaves the other's half unexplained for the length of one commit; both are on
  one branch and neither is offered for validation alone.
- The website and `CHANGELOG.md` still describe the old contract; stage 04 owns
  that and must follow before the branch is offered for review.
- Enumeration row 51 — a warning silenced by `-q` — becomes moot for these
  positions, since a refusal is not a log line. Any other position that still
  warns keeps that behaviour, and the plan does not audit them.

## Test plan (no tests written here)

Regression case per closed position, and the list is the table at the top of
this file — E48, E53, E54, E55, E56, E57, E58, E59, E61, E73 — plus:

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
- **Door symmetry.** The same unknown depth-2 key written as YAML
  (`callable: {max_warning: 1}`) and as `--rule-opt`
  (`complexity.ccn:callable.max_warning=1`) refuses identically; and the three
  accepted spellings `max_warning` / `maxWarning` / `max-warning` are accepted
  through **both** doors (row 61 measured YAML only).
- **Routing.** The refusal exits 3 on stderr with the `Configuration error: `
  prefix; it still exits 3 under `-q` (row 51) and under `--format=json` with
  stdout left parseable (row 52); and it exits 3 under `--workers=2`. The last
  one matters because the enumeration left worker-process diagnostics
  unmeasured (item 7 of *Что осталось неизмеренным*); a `--workers=2` run of
  the warning case was taken while planning and the two warnings appeared once
  each on the parent's stderr, so options are built in the parent — the test
  pins that rather than trusting it.
- **The retired refusal still wins at depth 1.** `exclude_paths` as a rule
  option keeps ADR 0047's message naming `suppress_paths`, not the generic one;
  at depth 2 it gets the generic one.
- `composer gate -- --reference=<the commit stage 02 ended on>` GREEN with
  empty maps.
