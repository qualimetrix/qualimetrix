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

4. **`--show-suppressed` corrupts every machine-readable format.** The
   suppressed-findings report is written to stdout as plain text *before* the
   formatter's own output, whatever the format is. With `--format=json` the
   artifact is a 186-byte text preamble followed by a JSON document, so it does
   not parse; the JSON violation payload itself is byte-identical with and
   without the flag (25 findings either way on the `smells` corpus case).
   Suppression is therefore observable on the text surface only, which is what
   the equivalence gate captures. Consequence beyond the gate: Ш3's DoD, which
   asks for the split into suppressible and non-suppressible findings to be
   identical before and after, can be gated on the text surface alone until this
   is fixed. Measured 2026-08-23 while building the Ш1 corpus.
5. **The documented custom-computed-metric example is rejected by the
   resolver.** `website/docs/reference/health-scores.md:159` shows
   `computed.code-density:`; a run with exactly that config exits 3 with
   `Computed metric name segment "code-density" ... must match
   [a-zA-Z][a-zA-Z0-9_]*`. The validator is right and the documentation is
   wrong — a hyphen is legal in a rule name and illegal in a computed metric
   name segment, and the one page teaching the feature uses the illegal form.
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

## Disposition

In scope for the rules-and-metrics pass: A1, A2, the `ViolationChannel` half of
B, and the `Evidence`/`Policy` rows of C.

Recorded for later, deliberately not in scope: `SymbolPath`, `MetricSubject`,
`FileProcessingResult`, `InertBaselineEntry`, `Core/Util`, `Reporting/Filter`,
`Reporting/Formatter/Support`, and the severity-literal triage.
