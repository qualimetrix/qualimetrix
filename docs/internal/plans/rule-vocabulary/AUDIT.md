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

## Disposition

In scope for the rules-and-metrics pass: A1, A2, the `ViolationChannel` half of
B, and the `Evidence`/`Policy` rows of C.

Recorded for later, deliberately not in scope: `SymbolPath`, `MetricSubject`,
`FileProcessingResult`, `InertBaselineEntry`, `Core/Util`, `Reporting/Filter`,
`Reporting/Formatter/Support`, and the severity-literal triage.
