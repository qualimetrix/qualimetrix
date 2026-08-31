# Architecture audit — three defect detectors

Run over all 832 files in `src/` on 2026-08-22 with a php-parser based script.
The counts below are **upper bounds that need triage**, not defect counts: a
string literal equal to an enum value is legitimate when the string is itself
the external contract (a YAML key, a CLI value, a serialized field).

## Detector A — a literal re-spelling a value that an enum already owns

### A1. The level axis is modelled three times, and the three never meet

| Enum          | Where                                    | Cases                                             |
| ------------- | ---------------------------------------- | ------------------------------------------------- |
| `SymbolType`  | `Core/Symbol`                            | method, function, class, file, namespace, project |
| `SymbolLevel` | `Analysis/Evidence/Measurement/Contract` | callable, class, file, namespace, project         |
| `RuleLevel`   | `Analysis/Finding/Contract/Rule`         | callable, class, namespace                        |

`RuleLevel` is a strict subset of `SymbolLevel`; `SymbolLevel` is `SymbolType`
with `method`+`function` collapsed into `callable`. Imported by 12, 31 and 56
files respectively.

There is exactly **one** conversion anywhere between them
(`NamespaceMetricContributions.php:123`, `SymbolType` → `SymbolLevel`), and
`RuleLevel` converts to neither. A rule declares its levels as `RuleLevel`, the
metric is stored under `SymbolLevel`, and the finding's channel name spells the
same level as a string literal. The three agree only because the strings happen
to be equal.

### A2. Hand-spelled level suffixes in channel names

20 literals of the form `'.class'` / `'.namespace'` / `'.callable'` across the
rule classes. The sharpest one is `Coupling/CboRule.php:248`:

```php
$violationCode = self::NAME . ($level === RuleLevel::Namespace_ ? '.namespace' : '.class');
```

The enum is in hand and the string is still written out. A third level added to
this rule would silently emit `.class`.

### A3. Other enums whose values are also written as literals

Upper bounds, before triage: severity-like literals (`info`/`warning`/`error`)
232 occurrences in 72 files; level-like literals 200 occurrences. `WorseDirection`
(`higher`/`lower`) 16, concentrated in `HealthDimensionCatalog`.

## Detector B — a sum type expressed as nullable fields

Classes with 2+ nullable constructor parameters and 2+ named static factories,
where "exactly one is set" is a convention rather than a type:

| Class                                                       | Shape                          |
| ----------------------------------------------------------- | ------------------------------ |
| `Core/Symbol/SymbolPath.php`                                | 4 nullable params, 8 factories |
| `Core/Symbol/MetricSubject.php`                             | 3 nullable params, 3 factories |
| `Analysis/Run/Contract/Collection/FileProcessingResult.php` | 3 nullable params, 2 factories |
| `Analysis/Policy/Baseline/InertBaselineEntry.php`           | 2 nullable params, 2 factories |

`MetricSubject::toCanonical()` throws `LogicException` at run time when none of
the three is set — the invariant has no compile-time expression.

`ViolationChannel` is the same class of defect in a different shape: a stored
pair whose left half no consumer reads.

## Detector C — directories named for a role rather than a subject

| Directory                                              | Files directly inside     |
| ------------------------------------------------------ | ------------------------- |
| `Analysis/Evidence`, `Analysis/Policy`                 | 0 (navigation taxonomies) |
| `Analysis/Finding/Contract/Rule`                       | 25                        |
| `Analysis/Evidence/DependencyModel/Extraction/Handler` | 11                        |
| `Analysis/Finding/Contract/Filter`                     | 8                         |
| `Reporting/Formatter/Support`                          | 7                         |
| `Analysis/Evidence/Measurement/Visitor`                | 6                         |
| `Infrastructure/Rule`                                  | 5                         |
| `Core/Util`                                            | 3                         |
| `Analysis/Policy/Baseline/Filter`                      | 3                         |
| `Analysis/Finding/Rule`                                | 2                         |
| `Reporting/Filter`                                     | 1                         |

`Infrastructure/Rule` holds `ChannelUniverse` — the implementation of channel
identity, whose contract belongs to `Analysis/Finding`.

## Found while closing the level enumeration (Ш0), out of scope here

Five defects surfaced by running the tool rather than reading it. None is a
vocabulary defect, so none is in scope; each is recorded so it is not
rediscovered.

1. **The documented group-selector syntax is not the implemented one.**
   `--only-rule=complexity` fails with `Rule selector "complexity" does not match
   any registered producer, group, or channel`, for every one of the 13 families.
   The working form is `complexity.*` — `NameSelector::GROUP_SUFFIX` is `.*`, the
   grammar ADR 0024 and ADR 0025 fixed — and it does select the whole family
   (verified: `--only-rule='complexity.*'` reports on all four complexity rules,
   `code-smell.*` on all of code-smell). What is wrong is the documentation:
   `bin/qmx check --help` says "or group by prefix (e.g., complexity,
   code-smell)" and the footer of `bin/qmx rules` repeats it, both naming a form
   that does not parse. The error message is also unhelpful — it says the
   selector matched no "group" without saying what a group looks like.

   Recorded here as the correction it is: an earlier revision of this file
   claimed the feature did not exist. That was wrong, and wrong in an instructive
   way — the documented form was tried, it failed, and the conclusion jumped from
   "this form does not work" to "the feature is absent" without reading the
   grammar. Verified 2026-08-23.

2. **`complexity.npath.class` is disabled by default and its two siblings are
   not.** `ClassNpathComplexityOptions::$enabled = false`, while the class
   channels of `complexity.cognitive` and `complexity.cyclomatic` are on. The
   channel is declared, documented and baselineable, and fires only with an
   explicit `complexity.npath:class.enabled=true`. Either the default is wrong
   or the asymmetry needs a stated reason.
3. **Doc rot in `ChannelDeclarationFixtureDriftTest`.** Its docblock and its
   failure messages name `tests/Fixtures/Channels/declared.txt` and
   `tests/Integration/Infrastructure/Rule/ChannelDeclarationFixtureDriftTest.php`;
   the real paths are `tests/Analysis/Finding/Fixtures/Channels/declared.txt` and
   `tests/Analysis/Finding/Integration/...`. The fixture's own header comment
   repeats the stale paths. A reader following the failure message looks in a
   directory that does not exist.

4. **`--show-suppressed` corrupts every machine-readable format.** ~~The
   suppressed-findings report is written to stdout as plain text *before* the
   formatter's own output, whatever the format is. With `--format=json` the
   artifact is a 186-byte text preamble followed by a JSON document, so it does
   not parse; the JSON violation payload itself is byte-identical with and
   without the flag (25 findings either way on the `smells` corpus case).
   Suppression is therefore observable on the text surface only, which is what
   the equivalence gate captures. Consequence beyond the gate: Ш3's DoD, which
   asks for the split into suppressible and non-suppressible findings to be
   identical before and after, can be gated on the text surface alone until this
   is fixed.~~ **Half-closed incidentally, 2026-08-24, one day after this was
   measured:** `5526676d` (`refactor(finding): a violation becomes a finding,
   and its dead level goes`) routed diagnostics through
   `DiagnosticOutput::stream()`, which prints to the error output on a real
   console and is what `FindingFilterOrchestrator::filterAndReport()` now calls
   first. The suppressed-findings prose lands on stderr, `--format=json` parses,
   and `bin/qmx check src --format=json --show-suppressed` and the same command
   without the flag are today byte-identical on stdout. That commit's message
   does not mention this — it is a renaming commit — so the fix was not
   discoverable from history; it was found by rerunning the corpus case this
   entry measured. What is still true, and is Ш6's own subject: nothing about
   *what* was suppressed is machine-readable anywhere, on any surface — routing
   the prose off stdout is not the same as publishing the composition. Measured
   2026-08-23 while building the Ш1 corpus; reverified 2026-08-29.
5. **The documented custom-computed-metric example is rejected by the
   resolver.** ~~`website/docs/reference/health-scores.md:159` shows
   `computed.code-density:`; a run with exactly that config exits 3 with
   `Computed metric name segment "code-density" ... must match
   [a-zA-Z][a-zA-Z0-9_]*`. The validator is right and the documentation is
   wrong — a hyphen is legal in a rule name and illegal in a computed metric
   name segment, and the one page teaching the feature uses the illegal form.~~
   **Closed by Ш5e3, 2026-08-28:** the computed-metric name grammar is kebab, so
   the documented example is the legal form. Verified by running exactly that
   config — `computed.code-density` is published in `--format=metrics`. The two
   sides met by the name grammar moving, not by the page changing.
   Measured 2026-08-23.

## Found while preparing the validator extraction (Ш3), out of scope here

**SARIF result order is insertion order, and nothing pins it.**
`SarifFormatter::format()` passes `$report->violations` straight into
`formatResults()` with no sort, and `SarifRuleCollector` builds the
`tool.driver.rules` array — and therefore every `ruleIndex` — in first-seen
order. Text, JSON and the baseline all re-sort by content
(`ViolationSorter`, `JsonViolationSection::identitySortKey()`,
`BaselineWriter::orderingKey()`); SARIF alone does not. Consequences, in the
order they will bite:

- any change to rule registration order silently reorders a published artifact,
  and the finding-equivalence gate compares surfaces as text, so such a change
  reads as a behaviour change with no behaviour behind it;
- the order is not even producer order end to end: `FindingProjector` withholds
  configuration errors from every filter stage and rejoins them at the tail, so
  the artifact is "everything else, then the configuration errors". Measured on
  the `layers` corpus case, SARIF prints `architecture.unassigned-class` before
  `architecture.coverage` although `LayerViolationRule` emits them the other way
  round.

Not fixed here: sorting SARIF results is an observable change to a published
artifact, and Ш3 carries a zero delta by decision. It is what makes the
validator extraction safe, so it is recorded rather than relied on silently.
Measured 2026-08-23.

## Found while moving the level vocabulary (Ш5e2b), out of scope here

**Nothing about a suppressed finding is machine-readable, and that is why a
delta on that surface cannot be caught by a test.** `--show-suppressed` changes
only the text renderer: `bin/qmx check src/ --format=json` and the same command
with `--show-suppressed` produce byte-identical JSON, 234 violations either way.
`RuleExclusionStats` counts what was silenced and `FindingFilterOrchestrator`
prints it, and that is the whole published surface — a line of prose plus a
count.

The consequence measured in Ш5e2b: removing a point threshold inside a
namespace whose channel is excluded moved the suppression count from 55 to 56
and added a hub to that list, and no test, no format and no exit code could see
it. Three separate probes missed it because all three read the published
report; an external reviewer found it by reading the text output by hand.

**`annotation.unused-directive` does not audit `@qmx-threshold` at all.**
`InlineDirectivePolicy::auditDirectiveUsage()` walks `$this->suppressions` only;
`$this->thresholdOverrides` is never read there, although
`UnusedDirectiveRule`'s own docblock promises "a suppression **or override**
that addressed something real and simply did not fire". So a threshold that has
outlived its reason is silent by construction — the exact failure mode the
channel exists to catch, and the sibling case (`@qmx-ignore health.cohesion`
outliving its reason) is what the channel did catch in Ш5e2.

The two belong to one step, not two: auditing a threshold means asking "would
removing this directive change any decision?", and answering that for a channel
excluded by namespace requires knowing what was suppressed — which is exactly
the surface that does not exist. ~~Five of the 136 `@qmx-threshold` directives
in `src` sit inside such a namespace today (`SymbolLevel` ×2, `RelativePath`
×2, `ThresholdOverride`), and none of them is dead.~~ **Corrected, 2026-08-29:**
four, not five — `ThresholdOverride` contributes no authored directive at all;
every `@qmx-threshold` token on that class sits inside a backtick-delimited
documentation example (its docblock is *teaching* the annotation grammar), and
the product's own parsing strips backtick regions before matching (AGENTS.md
§8), so an authored-site count built the product's way never counts it. The
four real sites are `SymbolLevel` ×2 (`coupling.cbo`, `coupling.class-rank`)
and `RelativePath` ×2 (the same pair), and none of them is dead.

**Corrected again, 2026-08-30 (Ш8):** «none of them is dead» держалось на
строгом критерии «директива меняет вердикт», и по нему было верно. Измерение
якорей показало другое: у трёх из четырёх величина уже прошла поднятый порог
(`CBO: 105 (threshold: 105)`, `CBO: 85 (threshold: 80)`,
`ClassRank: 0.0483 (threshold: 0.0340)`), то есть вердикт не менялся не по
замыслу, а потому что послабление не сработало. Эти три сняты; из четвёрки
остался `SymbolLevel` class-rank. Разбор — «Ш8. Исполнен» в `PLAN.md`.

136 was never a count of authored directives — it is a naive
`grep -rn '@qmx-threshold' src | wc -l`, which also matches every mention of
the tag in prose, including the very documentation examples the paragraph
above is about, and including this file's own use of the string above. Counted
the product's way — `token_get_all()` restricted to `T_DOC_COMMENT`, the same
backtick-stripping, and `ThresholdOverrideExtractor`'s own matching pattern —
`scripts/enumerate-inline-directives.php src` finds 41 authored
`@qmx-threshold` sites today (`docs/internal/plans/rule-vocabulary/PLAN.md`'s
П2 retrospective already recorded this count moving 37 → 39 as a side effect
of that package; it has since moved to 41, past this file's original
measurement). The two counts disagree by roughly 3.5x, which is the cost of
asking a text grep a question about an annotation grammar.

Not fixed here: both add published output, so they carry a declared delta and a
corpus fixture, and Ш5e3 renames 82 metric keys — a new observable channel in
between would be compared against maps written for the rename. Scheduled after
Ш5e3. Measured 2026-08-26.

## Found while documenting the `suppressed` format (Ш6), out of scope here

**`--show-suppressed` prints nothing when the command runs against a
non-console `OutputInterface`.** `DiagnosticOutput::stream()`
(`src/Infrastructure/Console/DiagnosticOutput.php`) routes to
`$output->getErrorOutput()` only when `$output instanceof
ConsoleOutputInterface`; every other `OutputInterface` — a `BufferedOutput`, a
plain `StreamOutput`, anything a caller hands `CheckCommand::run()` outside a
real terminal invocation — gets a fresh `NullOutput` instead. Both the flag's
own text rendering (`FindingFilterOrchestrator`) and every other diagnostic
line go through this same gate, so a caller that embeds the command
programmatically and passes such an output silently gets none of that prose:
not an error, not a warning, just an absent section where `--show-suppressed`
was asked for. The machine-readable `suppressed` format this step adds is
unaffected — it is `Report`/`FormatterInterface` output on the command's normal
`OutputInterface`, not routed through `DiagnosticOutput` at all. Verified by
reading `DiagnosticOutput.php`; not fixed here — nothing in this repository's
own CLI path constructs a non-`ConsoleOutputInterface` output, so no test on
`src` observes it. Measured 2026-08-29.

## Consumers that read the level from the channel NAME

Found in round 1 of the plan review, verified against the code. Not defects of
today's behaviour — defects that the level's removal from the name will create,
so they belong to the enumeration the plan owes rather than to a later pass.

`Infrastructure/Console/Command/BaselineConfiguredThresholds.php` is the one
found so far, and it reads the level twice:

- `thresholdFor()` derives the level from the channel name
  (`axisOf($channel)` -> `RuleLevel::tryFrom($axis)`), then uses it to pick the
  level's options. Once the level leaves the name, `$axis` is null, the
  hierarchical branch never runs, and `baseline:explain` silently stops printing
  the configured boundary for those channels. Nothing fails; a line disappears.
- `levelOptions()` catches `Throwable` and returns null, commented as "a
  mismatch worth no more than a missing line here". That is defensible while the
  addressable domain is three values; when it widens to five, the same catch
  swallows a user's unsupported `channel:level` instead of rejecting it.

The lesson generalises past this file: a grep for level *literals* does not find
a consumer that parses the level out of the channel code. That needs its own
probe — grep for the code-splitting helpers, not for `'.class'`.

## Found while renaming the metric keys (Ш5e3), out of scope here

### One collector publishes three families, and is named after one of them

`Analysis/Evidence/Size/MethodCountCollector` writes the method and property
counters (`size.*`), the seven class-shape facts (`design.is-readonly`,
`design.is-data-class`, `design.is-abstract`, `design.is-interface`,
`design.is-exception`, `design.is-promoted-properties-only`) and `design.woc`.
Ш5e3 gave every key the family its meaning belongs to rather than the family of
its producer, so the mismatch is now visible in the names: a `Size` collector
publishes `design.*`. The name is right and the placement is not — the shape
facts are a Design subject that happens to be cheap to read while counting
methods. Moving them needs a collector split and a look at what else reads the
same visitor.

### `MetricName` holds two kinds of key and nothing tells them apart

`security.hardcoded-credentials`, `security.sensitive-parameter` and the three
`code-smell.unused-private.{method,property,constant}` halves are not metrics.
They are keys of the structured-entry store (`MetricBag::withEntry`), never
present in `MetricBag::all()` and therefore never published in any format. They
sit in `MetricName` beside 77 keys that are published, with the same shape and
no marker. Measured while the gate refused their map rows as stale: the rows
declared a rename no surface could ever show, which is how the difference was
found at all. A reader — or a tool building the published vocabulary from this
class, as the gate's `MetricVocabulary` does — has nothing to filter on.

### Two copies of the strategy list were short, and both are fixed

`hints.js::resolveBaseKey` and `MetricHintCatalog` each kept their own copy of
the aggregation suffixes, and both were missing `count` — so a hint for
`<key>.count` resolved to nothing and the report showed none. The PHP copy is
gone (it reads `MetricName::base()`); the JavaScript one is completed rather than
removed, because nothing crosses from the enum into the bundle. That leaves one
list restated in one place, which is the smallest this can be made without a
generated constant.

### `--exclude-health` loses a custom fallback when it rebuilds the overall score

`HealthFormulaExcluder::parseWeightsFromFormula` reads `(m["health.x"] ?? 75) * w`
with `\d+` for the fallback and `buildWeightedFormula` writes `?? 75` back
unconditionally. A user whose overall formula falls back to anything but 75 has
that replaced on exclusion, and a fractional fallback (`?? 75.5`) does not match
the pattern at all, so the whole formula is refused as "not the canonical
weighted-sum shape". Predates Ш5e3 — the same `\d+` and the same literal were
there before the vocabulary changed — and it is the third mechanism in this file
that reads a formula by matching its text.

### `collect-benchmark-data.php` cannot run under PHP's default memory limit

It builds the whole output document in memory and `implode`s it, which exhausts
128 MB on this project alone (measured 2026-08-28: fatal at line 102, 103 MB
resident). It runs under `php -d memory_limit=2G`. The composer script sets no
limit, so the documented invocation fails on a default PHP; every other script
in `composer.json` that needs headroom passes `--memory-limit`.

### `size.class-count` never looks at a non-leaf namespace

`ClassCountRule::analyze` skips a namespace whose `NamespaceTree` node is not a
leaf (`ClassCountRule.php:93`). The check is on tree shape, not on the counted
value, and the value it skips is the namespace's own `size.class-count`
aggregate — so a namespace that holds many classes *directly* and also has one
child namespace is never judged, at any count. Measured 2026-08-31 on this
repository at the default `warning: 15`: `Analysis/Evidence/CodeSmell` holds 37
classes directly, `Analysis/Evidence/Security` 21, `Analysis/Evidence/Coupling`
19, and none of the three produces a finding — none appears in
`qmx-baseline.json` under `size.class-count`, and `composer selfcheck` is green
with `--fail-on=warning`.

The rule is right to distinguish the two cases: a parent's aggregate sums its
children and would double-count. What it lacks is the direct-count reading that
makes the parent judgeable on its own classes. Fixing it means giving the rule a
non-recursive class count per namespace, not removing the leaf test.

Not load-bearing for the `Design` split: the accepted `ns:…Evidence\Design`
ratchet entry (`size.class-count` 24) was removed because the four subject
folders leave **zero** classes in the root, so the namespace's own count is
`None` and no finding exists to accept. The blind spot would only have been
load-bearing in the rejected one-folder variant, which left 16 classes in the
root against a threshold of 15.

## Disposition

In scope for the rules-and-metrics pass: A1, A2, the `ViolationChannel` half of
B, and the `Evidence`/`Policy` rows of C.

Recorded for later, deliberately not in scope: `SymbolPath`, `MetricSubject`,
`FileProcessingResult`, `InertBaselineEntry`, `Core/Util`, `Reporting/Filter`,
`Reporting/Formatter/Support`, and the severity-literal triage.
