# Output Formats

Qualimetrix supports 12 output formats (including the deprecated
`text-verbose`). Choose the one that fits your workflow.

```bash
bin/qmx check src/ --format=<format>
```

---

## summary (default)

Health-oriented overview showing project health scores, worst offenders, and violation summary. This is the default CLI output designed for quick project assessment.

**When to use:** Local development, quick project health overview.

**Key features:**

- An overall health bar plus one bar per dimension (complexity, cohesion, coupling, typing, maintainability), each followed by a `↳` decomposition breakdown of the metrics behind that score
- Scores as percentages, not raw point values
- One-line `Worst namespaces` / `Worst classes` lists, each entry prefixed by its score, with a trailing `+N more (use ...)` when the list was truncated
- A `Top issues by impact` section: a ranked list of the highest-impact individual violations, each with severity, impact score, file, estimated fix time, rule channel, message, and symbol
- Violation count with tech debt estimate (including debt density per 1K LOC)
- Multiple contextual `Hints:` for next steps

**Example output:**

```
Qualimetrix 0.26.0 — 62 files analyzed, 0.8s

Analysis complete: 62 analyzed, 0 generated file(s) excluded.

Health █████████████████████░░░░░░░░░ 71.4% Fair

  Complexity      ████████████████████████░░░░░░ 79.2% Good
                   ↳ Cyclomatic (avg): 3.1 (target: below 4) — manageable branching
                   ↳ Cognitive (avg): 2.4 (target: below 5) — straightforward control flow
                   ↳ Cyclomatic: 14 (target: below 4) — too many code paths
                   ↳ Cognitive: 18 (target: below 5) — deeply nested, hard to follow
  Cohesion        ████████████████░░░░░░░░░░░░░░ 54.8% Poor
                   ↳ TCC: 0.4 (target: above 0.5) — methods rarely share fields
                   ↳ LCOM4: 3 (target: 1 or less) — class splits into unrelated clusters
  Coupling        ███████████████████░░░░░░░░░░░ 63.1% Fair
                   ↳ Ce (avg): 4.7 (target: below 3) — elevated outgoing coupling
                   ↳ Ce pkg (avg): 1.8 (target: below 1) — wide package dependencies
                   ↳ Distance: 0.52 (target: below 0.3) — poor balance of abstraction and stability
  Typing          ███████████████████████████░░░ 91.3% Excellent
                   ↳ Parameter types: 91 (target: 100%) — 251 of 275 typed (91.3%)
                   ↳ Return types: 88 (target: 100%) — 231 of 262 typed (88.2%)
                   ↳ Property types: 95 (target: 100%) — 118 of 124 typed (95.2%)
  Maintainability █████████████████████████░░░░░ 82.0% Good
                   ↳ MI (avg): 71.4 (target: above 65) — code is maintainable
                   ↳ MI (p5): 52.6 (target: above 50) — even worst methods are maintainable
                   ↳ MI: 41.8 (target: above 65) — code is hard to change safely
  * Labels reflect per-dimension scales (e.g., Typing requires >80% for Acceptable)

Worst namespaces
  48.2 App\Billing\Invoice (6 classes, 11 violations, 3.8/100 LOC)
  55.9 App\Service\Order (4 classes, 7 violations, 2.1/100 LOC)
  61.3 App\Repository (9 classes, 5 violations, 0.9/100 LOC)
  +5 more (use --format=html or --format-opt=top=8)

Worst classes
  38.4 App\Billing\Invoice\InvoiceCalculator — low cohesion
  45.1 App\Service\Order\OrderService — high coupling
  52.7 App\Repository\OrderRepository
  +9 more (use --format=html or --format-opt=top=10)


Top issues by impact
  1. [ERR] 4.12  src/Billing/Invoice/InvoiceCalculator.php  [45min]
         complexity.cognitive: Cognitive complexity: 24 (threshold: 15). Top: nested if +4 L88, nested foreach +3 L74, nested if +2 L91 — deeply nested, hard to follow (InvoiceCalculator::recalculate)
  2. [ERR] 3.65  src/Service/Order/OrderService.php  [30min]
         coupling.cbo: CBO is 21 (threshold: 15) — too many collaborators (OrderService)
  3. [WRN] 2.90  src/Repository/OrderRepository.php  [20min]
         complexity.ccn: Cyclomatic complexity: 13 (threshold: 10) — too many code paths (OrderRepository::findByCriteria)
82 violations (19 errors, 63 warnings) | Tech debt: 6h 20min (54.3 min/kLOC to fix)

Hints: --detail to list violations (up to 200; --detail=all for every one) | --namespace='subtree:App\Billing\Invoice' to drill down | --format=html -o report.html for full report
Docs: https://qualimetrix.dev · AI agents: https://qualimetrix.dev/llms.txt
```

See [CLI Options](cli-options.md) for the flag that controls how many `Top issues by impact` entries are shown.

**Drill-down with `--namespace` and `--class`:**

```bash
# Show violations for a specific namespace subtree
bin/qmx check src/ --namespace='subtree:App\Service'

# Show violations for a specific class
bin/qmx check src/ --class=App\\Service\\UserService
```

A drill-down narrows what the report shows, not what decides the exit code.
The finding-count line therefore speaks about the selection ("… in this
scope"), names the violations outside it that decide the exit code, and takes
its colour from the whole run: a clean subtree of a failing project prints
`No violations in this scope. 9 outside it (5 errors, 4 warnings) decide the exit code`,
never a green `No violations found.`. `--format=text` does the same in its
closing summary line.

**Detail mode with `--detail`:**

```bash
# Append grouped violation list (default limit: 200)
bin/qmx check src/ --detail

# Show all violations (no limit)
bin/qmx check src/ --detail=all

# Custom limit
bin/qmx check src/ --detail=50
```

`--detail` switches the violation list on, with an optional cap; it does not
rank. `--detail=N` lists the first N violations in the order the list is printed
(by file, unless `--group-by` says otherwise), so they are always the first N
that `--detail=all` would print. `--detail=0` is the same as `--detail=all`. Any
other value (`--detail=abc`, `--detail=-1`) is refused with exit code 3 before
the analysis runs. The ranked `Top issues by impact` section is `--top`'s.

!!! note
    `--detail` is auto-enabled when using `--namespace` or `--class`. It also works with `--format=text` to append a grouped violation list after the one-line-per-violation output.

---

## text

Compact, one-line-per-violation output. Compatible with GCC/Clang error format, so violations are clickable in most terminals and IDEs.

**When to use:** Local development, quick checks, piping to `grep` or `wc`.

**Example output:**

```
src/Repository/OrderRepository.php: error[coupling.class-rank]: ClassRank is 0.5000, exceeds threshold of 0.3536 (scaled for 2 classes). This class is a critical hub — changes have wide impact (OrderRepository)
src/Service/UserService.php: error[coupling.class-rank]: ClassRank is 0.5000, exceeds threshold of 0.3536 (scaled for 2 classes). This class is a critical hub — changes have wide impact (UserService)
src/Repository/OrderRepository.php: warning[complexity.ccn]: Cyclomatic complexity is 10, exceeds threshold of 10. Consider extracting methods or simplifying conditions (OrderRepository::findByCriteria)
src/Service/UserService.php:9: warning[code-smell.error-suppression]: Error suppression (@) on file_get_contents() - handle errors explicitly
src/Service/UserService.php: warning[complexity.ccn]: Cyclomatic complexity is 14, exceeds threshold of 10. Consider extracting methods or simplifying conditions (UserService::calculate)

Qualimetrix 0.26.0: 2 error(s), 3 warning(s) in 2 file(s)
Analysis complete: 2 analyzed, 0 generated file(s) excluded.
Technical debt: 2h 10min
Docs: https://qualimetrix.dev · AI agents: https://qualimetrix.dev/llms.txt
```

**Format:** there are three line shapes, depending on what the finding is about.

- A violation pinned to a specific statement carries a line number: `file:line: severity[violationCode]: message (symbol)`.
- A class- or method-level finding whose rule judges the whole declaration rather than one statement — for example `complexity.ccn`, `complexity.wmc`, `coupling.class-rank` — omits the line segment instead: `file: severity[violationCode]: message (symbol)`, even though the same finding carries a `line` in `--format=json`.
- A project-level finding (no owning file at all — e.g. an `architecture.unreachable-layer` finding) drops the file segment too: `[project]: severity[violationCode]: message`, with no trailing `(symbol)`. On this project's own self-analysis this third form is common, not an edge case: `bin/qmx check src/Analysis/Evidence/Complexity --format=text` prints project-level lines for a majority of the output.

---

## text-verbose

<!-- llms:skip-begin -->
!!! warning "Deprecated"
    `text-verbose` is deprecated. Use `--format=text --detail` instead, which provides the same grouped, multi-line violation output alongside the compact one-line format.

    ```bash
    # Replaces: bin/qmx check src/ --format=text-verbose
    bin/qmx check src/ --format=text --detail
    ```
<!-- llms:skip-end -->
<!-- llms-only
Deprecated. Use `--format=text --detail` instead.
-->

---

## json

Machine-readable JSON output. Summary-oriented format with health scores, worst offenders, and all violations.

**When to use:** Custom scripts, dashboards, programmatic processing.

**Top-level keys:** `meta`, `summary`, `coverage`, `health`, `worstNamespaces`, `worstClasses`, `topIssues`, `violations`, `violationsMeta`, plus `violationGroups` when `--group-by` is passed — without it, the key is absent entirely, not an empty object.

`meta` identifies the tool that wrote the document: `version`, `package`, `timestamp`, and two documentation addresses — `docs`, the documentation site, and `llmsTxt`, the index written for AI agents. Every JSON report with an envelope object carries the same two addresses; see the exceptions in [Documentation addresses in JSON reports](#documentation-addresses).

<!-- llms:skip-begin -->
**Example output:**

```json
{
    "meta": {
        "version": "1.0.0",
        "package": "qmx",
        "timestamp": "2025-01-15T10:30:00+00:00",
        "docs": "https://qualimetrix.dev",
        "llmsTxt": "https://qualimetrix.dev/llms.txt"
    },
    "summary": {
        "filesAnalyzed": 45,
        "filesSkipped": 0,
        "duration": 1.234,
        "violationCount": 3,
        "errorCount": 2,
        "warningCount": 1,
        "infoCount": 0,
        "techDebtMinutes": 270,
        "debtPer1kLoc": 2.1
    },
    "health": {
        "complexity": {
            "score": 78.0,
            "label": "Excellent",
            "threshold": {"warning": 50, "error": 25},
            "coverage": {
                "state": "measured",
                "measured": 2263,
                "eligible": 2263,
                "ratio": 1.0,
                "unit": "callables",
                "basis": "complexity.ccn.count",
                "reason": null
            },
            "decomposition": [
                {
                    "metric": "complexity.ccn.sum",
                    "humanName": "Cyclomatic complexity",
                    "value": 412,
                    "good": true,
                    "direction": "lower-is-better"
                }
            ],
            "worstContributors": [
                {
                    "symbolPath": "App\\Service\\UserService",
                    "className": "App\\Service\\UserService",
                    "metrics": {"complexity.ccn.sum": 96}
                }
            ]
        },
        "overall": {
            "score": 72.0,
            "label": "Fair",
            "threshold": {"warning": 50, "error": 25},
            "coverage": {
                "state": "not-applicable",
                "measured": null,
                "eligible": null,
                "ratio": null,
                "unit": null,
                "basis": null,
                "reason": "health.overall composes the other dimensions; each of them publishes its own coverage"
            },
            "decomposition": [],
            "worstContributors": []
        }
    },
    "worstNamespaces": [
        {
            "symbolPath": "App\\Service",
            "healthOverall": 52.0,
            "label": "Poor",
            "reason": "high coupling",
            "violationCount": 15,
            "size.class-count": 8,
            "healthScores": {}
        }
    ],
    "worstClasses": [
        {
            "symbolPath": "App\\Service\\UserService",
            "healthOverall": 45.0,
            "label": "Poor",
            "reason": "low cohesion",
            "violationCount": 8,
            "file": "src/Service/UserService.php",
            "metrics": {},
            "healthScores": {}
        }
    ],
    "topIssues": [
        {
            "rank": 1,
            "file": "src/Service/UserService.php",
            "line": 42,
            "symbol": "App\\Service\\UserService::calculate",
            "rule": "complexity.ccn",
            "severity": "error",
            "message": "Cyclomatic complexity: 15 (threshold: 10) — too many code paths",
            "recommendation": null,
            "impactScore": 3.71,
            "coupling.class-rank": 0.1237,
            "debtMinutes": 30
        }
    ],
    "violations": [
        {
            "file": "src/Service/UserService.php",
            "line": 42,
            "subject": "declaration:callable:App\\Service\\UserService::calculate@src/Service/UserService.php",
            "symbol": "App\\Service\\UserService::calculate",
            "channel": "complexity.ccn",
            "occurrence": null,
            "edge": null,
            "namespace": "App\\Service",
            "rule": "complexity.ccn",
            "code": "complexity.ccn",
            "severity": "error",
            "message": "Cyclomatic complexity: 15 (threshold: 10) — too many code paths",
            "recommendation": null,
            "metricValue": 15,
            "threshold": 10,
            "techDebtMinutes": 30,
            "acceptedLevel": null
        }
    ],
    "violationsMeta": {
        "total": 3,
        "shown": 3,
        "limit": null,
        "truncated": false,
        "byRule": {
            "complexity.ccn": 2,
            "coupling.cbo": 1
        }
    },
    "violationGroups": {}
}
```
<!-- llms:skip-end -->

The `worstNamespaces` and `worstClasses` entries include a `violationDensity` field -- violations per 100 lines of code -- providing a size-normalized view of code quality.

`topIssues` is the same ranked list the `summary` format prints as "Top issues
by impact"; no other format renders it. Each entry names the rule-specific
`impactScore` used for ranking and the estimated `debtMinutes`. The
`coupling.class-rank` key is always present, but its value is `null` unless the
rule producing the issue reads a coupling-hub signal; `file` and `line` are
nullable too, because a project-level finding has no source position.

Each violation carries `acceptedLevel`: the baseline ceiling this finding was
measured against, or `null` whenever the finding has no accepted level of its
own. That covers two distinct cases the value cannot tell apart: no baseline
is configured for the run at all, or a baseline is configured but this
particular finding is new and the baseline never judged it. Neither the JSON
payload nor `acceptedLevel` itself exposes which case applies.

When it is not null, `acceptedLevel` is an object — a finding whose own
identity group exceeded its accepted level is reported as a breach and carries
`{"shape": "occurrence", "describe": "2 occurrences", "count": 2}`. `shape`
names what the ceiling counts, `count` is the accepted number, and `describe`
is that number in words. A breach is also raised to `error` severity,
whatever the rule would otherwise have reported.

`violationsMeta`
also reports `shown` — the number of violations actually included in this
payload, which can be lower than `total` when `--format-opt=violations=N`
truncates the list. A truncated list is the first N in the identity order
described below.

`message` and `recommendation` mean the same in `violations` and in
`topIssues`: the finding's message, and its recommendation or `null`. Under
`--namespace`/`--class` the `summary` object keeps all its keys; `debtPer1kLoc`
is `null` there, because the selection's debt over the whole project's lines
would mix two scopes.

When a symbol name from the analysed source is not valid UTF-8 (the parser
accepts any byte above 0x7F in an identifier), each invalid byte is published
as U+FFFD and the document gains a top-level `invalidUtf8Replaced` key counting
the repaired strings. `metrics`, `suppressed` and the `html` payload do the
same; `sarif` reports it as a `QMX-PUBLICATION-INVALID-UTF8` tool notification,
`gitlab` as a `publication.invalid-utf8` issue, and `checkstyle` as an error
under the synthetic file `[publication]`.

For machine identity, use `channel + subject + optional occurrence + optional
edge`. `symbol` is the logical display projection; source line, message, and
display order are not stable identity. `subject` distinguishes exact
declarations from logical and aggregate subjects, `occurrence` distinguishes
semantic evidence within one channel, and `edge` contains a required dependency
target plus an optional reference `type`. An untyped edge is
`{"target": "class:App\\Dependency"}`; a typed edge is
`{"type": "new", "target": "class:App\\Dependency"}`. Formatter fingerprints
use the same tuple, so target-only edges differ by target and from a typed edge
to the same target. Existing no-edge and fully typed fingerprints are
unchanged.

When using `--group-by=class` or `--group-by=namespace`, violations are organized into a `violationGroups` object. Each group is `{count, violations}` — a violation count and the violations array; it does not carry its own `errorCount`, `warningCount`, or `violationDensity`.

The group keys are not always a class FQCN or namespace. For `--group-by=class`: the key is the class FQCN for a class-scoped finding, the file path for a file-level finding with no class context, and an empty string `""` for a project-level finding (which has neither a class nor a file). For `--group-by=namespace`: the key is the namespace for a namespaced class, `<global>` for a class with no namespace, and `__PROJECT__` for a project-level finding.

<!-- llms:skip-begin -->
```json
{
    "violationGroups": {
        "App\\Service\\UserService": {
            "count": 3,
            "violations": [...]
        }
    }
}
```
<!-- llms:skip-end -->

**Options:**

```bash
# Limit violations in output (default: all)
bin/qmx check src/ --format=json --format-opt=violations=50

# Control number of worst offenders (default: 10)
bin/qmx check src/ --format=json --format-opt=top=20
```

Every `--format-opt` value is parsed before the analysis runs, by one grammar
per key whichever format reads it: `violations` and `limit` take a whole number
or `all`, `top` a whole number of 1 or more, `contributors` a whole number,
`rank-by` `count` or `density`, `project-name` a non-empty name. A value that
does not parse is refused with exit code 3 instead of falling back to a
default.

```bash
# Group violations by class or namespace
bin/qmx check src/ --format=json --group-by=class
bin/qmx check src/ --format=json --group-by=namespace
```

**CI usage:**

```bash
bin/qmx check src/ --format=json --no-progress > report.json
```

---

## metrics

Raw metric values for every symbol (file, class, namespace, method, function, project). Unlike `json` which outputs violations, `metrics` exports the underlying metric data that rules evaluate.

**When to use:** Custom dashboards, trend analysis, data science pipelines, or building your own quality gates on raw metrics.

**Top-level keys:** `version`, `toolVersion`, `package`, `timestamp`, `docs`, `llmsTxt`, `symbols[]` (each with `type`: file/class/namespace/method/function/project, `name`, `file`, `line`, `metrics: {...}`), `coverage`, `summary`. There is no `callable` type; a single `project` entry aggregates project-wide statistical metrics (min/max/avg/p95 across all symbols) and has a `null` `line`. Here `version` is the version of this export format and `toolVersion` the version of Qualimetrix; `docs` and `llmsTxt` are the documentation addresses `json` carries in its `meta`.

<!-- llms:skip-begin -->
**Example output (abbreviated):**

```json
{
    "version": "1.0.0",
    "toolVersion": "0.26.0",
    "package": "qmx",
    "timestamp": "2025-01-15T10:30:00+00:00",
    "docs": "https://qualimetrix.dev",
    "llmsTxt": "https://qualimetrix.dev/llms.txt",
    "symbols": [
        {
            "type": "file",
            "name": "src/Service/UserService.php",
            "file": "src/Service/UserService.php",
            "line": 1,
            "metrics": {
                "size.loc": 150,
                "size.lloc": 120,
                "size.class-count": 1
            }
        },
        {
            "type": "class",
            "name": "App\\Service\\UserService",
            "file": "src/Service/UserService.php",
            "line": 10,
            "metrics": {
                "size.method-count": 8,
                "size.property-count": 3,
                "cohesion.lcom": 2,
                "complexity.wmc": 35,
                "coupling.ca": 5,
                "coupling.ce": 12,
                "coupling.cbo": 17,
                "coupling.instability": 0.71
            }
        },
        {
            "type": "method",
            "name": "App\\Service\\UserService::calculate",
            "file": "src/Service/UserService.php",
            "line": 42,
            "metrics": {
                "complexity.ccn": 15,
                "complexity.cognitive": 22,
                "maintainability.halstead.volume": 384.5,
                "size.loc": 35
            }
        }
    ],
    "summary": {
        "filesAnalyzed": 45,
        "filesSkipped": 0,
        "duration": 1.234,
        "violations": 3,
        "errors": 2,
        "warnings": 1,
        "info": 0
    }
}
```
<!-- llms:skip-end -->

**Usage:**

```bash
bin/qmx check src/ --format=metrics --no-progress > metrics.json
```

!!! note
    The `metrics` format exports **all collected metrics**, not just those that triggered violations. This makes it useful for tracking metric trends over time, even for code that passes all rules.

!!! info "Line counts at namespace and project level measure different lines"
    `size.loc`, `size.lloc` and `size.cloc` count different populations at the two aggregate levels. A namespace counts the lines inside its `namespace` statement; the project counts every analysed file in full, including the opening tag, file header comments and `declare` above the namespace. The project's `size.loc.sum` is therefore larger than the sum of the namespaces' `size.loc`, and it is not a rounding or aggregation error. Within the namespace tree the sums do add up: a namespace's `size.loc.sum` equals its own `size.loc` plus its children's `size.loc.sum`.

---

## checkstyle

Checkstyle XML format. Widely supported by CI tools.

**When to use:** Jenkins, SonarQube, or any tool that accepts Checkstyle XML.

Checkstyle 3.0 XML: `<file name="...">` with nested `<error line="" severity="error|warning|info" message="" source="qmx.<rule>"/>`.

<!-- llms:skip-begin -->
**Example output:**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<checkstyle version="3.0">
  <file name="src/Service/UserService.php">
    <error line="42"
           severity="error"
           message="Cyclomatic complexity is 15, max allowed is 10"
           source="qmx.complexity.ccn"/>
    <error line="87"
           severity="warning"
           message="Class has 22 methods, max recommended is 20"
           source="qmx.size.method-count"/>
  </file>
</checkstyle>
```

**CI usage (Jenkins):**

```bash
bin/qmx check src/ --format=checkstyle --no-progress > checkstyle.xml
```
<!-- llms:skip-end -->

---

## sarif

SARIF (Static Analysis Results Interchange Format) 2.1.0. A standard for static analysis tools adopted by GitHub, Microsoft, and many IDE vendors.

**When to use:** GitHub Security tab, VS Code (with SARIF Viewer extension), JetBrains IDEs, Azure DevOps.

SARIF 2.1.0 spec — `runs[].results[]` entries with `ruleId`, `ruleIndex` (position of the rule in `tool.driver.rules`), `level` (error/warning/note), `message.text`, `partialFingerprints.primaryLocationLineHash`, and `locations[].physicalLocation.{artifactLocation.{uri,uriBaseId}, region.{startLine,startColumn}}`. `locations` is not present on every result: a project-level finding with no source position (e.g. `architecture.unreachable-layer`) has no `locations` array at all.

`runs[].invocations[0]` reports `executionSuccessful` (see the coverage table below), and `runs[].originalUriBaseIds` declares the `%SRCROOT%` base referenced by every `artifactLocation.uriBaseId`, resolving it to the analyzed project root as a `file://` URI. Every `artifactLocation.uri` is a percent-encoded relative reference (a space is `%20`, `#` is `%23`), encoded the same way as that base. A related location without a file carries its `message` and no `physicalLocation`.

`runs[].tool.driver` describes the tool itself: `name`, `version`,
`informationUri` (the documentation site, `https://qualimetrix.dev`), a
`properties` bag whose `properties.llmsTxt` is the index written for AI agents,
and a `rules[]` catalogue that every result's `ruleIndex` points into. The
address rides in `properties` because SARIF closes `driver` to keys it does not
define, and `properties` is its extension point. Each rules entry is
`{"id": "...", "name": "...", "shortDescription": {"text": "..."}, "fullDescription": {"text": "..."}, "helpUri": "...", "defaultConfiguration": {"level": "..."}}`.

Each `runs[].invocations[]` entry is
`{"executionSuccessful": true, "toolExecutionNotifications": []}`, and every
notification in it is
`{"descriptor": {"id": "..."}, "level": "...", "message": {"text": "..."}}` —
this is where a file that failed to parse is reported. `runs[].originalUriBaseIds`
maps `%SRCROOT%` to `{"uri": "file:///path/to/project/"}`.

`partialFingerprints.primaryLocationLineHash` matters beyond spec completeness:
it is what GitHub's code-scanning alert de-duplication keys on across uploads,
so an alert stays the same tracked alert (not a new one) as long as its
fingerprint is stable between runs.

<!-- llms:skip-begin -->
**Example output (abbreviated):**

```json
{
    "$schema": "https://raw.githubusercontent.com/oasis-tcs/sarif-spec/main/sarif-2.1/schema/sarif-schema-2.1.0.json",
    "version": "2.1.0",
    "runs": [
        {
            "tool": {
                "driver": {
                    "name": "Qualimetrix",
                    "version": "0.26.0",
                    "informationUri": "https://qualimetrix.dev",
                    "properties": {
                        "llmsTxt": "https://qualimetrix.dev/llms.txt"
                    },
                    "rules": [...]
                }
            },
            "results": [
                {
                    "ruleId": "complexity.ccn",
                    "ruleIndex": 0,
                    "level": "error",
                    "message": {
                        "text": "Cyclomatic complexity is 15, max allowed is 10"
                    },
                    "partialFingerprints": {
                        "primaryLocationLineHash": "complexity.ccn:declaration:callable:App\\Service\\UserService::calculate@src/Service/UserService.php:4e6e45ba70fb46d4"
                    },
                    "locations": [
                        {
                            "physicalLocation": {
                                "artifactLocation": {
                                    "uri": "src/Service/UserService.php",
                                    "uriBaseId": "%SRCROOT%"
                                },
                                "region": {
                                    "startLine": 42,
                                    "startColumn": 1
                                }
                            }
                        }
                    ]
                }
            ]
        }
    ]
}
```

**CI usage (GitHub Actions):**

```yaml
- name: Run Qualimetrix
  run: bin/qmx check src/ --format=sarif --no-progress > results.sarif

- name: Upload SARIF to GitHub Security
  uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: results.sarif
```

Results appear in the **Security** tab of your repository and as inline annotations on pull requests.
<!-- llms:skip-end -->

---

## gitlab

GitLab Code Quality JSON format. Shows violations directly in Merge Request diffs.

**When to use:** GitLab CI/CD with Code Quality reports.

Array of objects with `description`, `check_name`, `fingerprint`, `severity` (critical/major/info), `location.{path,lines.begin}`. Severity mapping: error → critical, warning → major, info → info.

<!-- llms:skip-begin -->
**Example output (abbreviated):**

```json
[
    {
        "description": "Cyclomatic complexity is 15, max allowed is 10",
        "check_name": "complexity.ccn",
        "fingerprint": "a1b2c3d4e5f6...",
        "severity": "critical",
        "location": {
            "path": "src/Service/UserService.php",
            "lines": {
                "begin": 42
            }
        }
    }
]
```

**CI usage (GitLab CI):**

```yaml
code_quality:
  stage: test
  script:
    - bin/qmx check src/ --format=gitlab --no-progress > gl-code-quality-report.json
  artifacts:
    reports:
      codequality: gl-code-quality-report.json
```

Violations appear inline in the **Changes** tab of your Merge Request.
<!-- llms:skip-end -->

---

## github

GitHub Actions workflow command format. Produces inline annotations that appear directly in PR diffs when running in GitHub Actions.

**When to use:** GitHub Actions CI. Simpler setup than SARIF — no upload step needed.

Workflow command format: `::<level> file=<path>,line=<n>,title=<rule>::<message>` (one line per violation). Mapping: warning → `::warning`, error → `::error`.

Only `title=` appears on every line. A project-level finding has no source
position, so it is annotated as `::<level> title=<rule>::<message>` — without
`file=` and `line=`, which GitHub then shows against the workflow run rather
than against a line in the diff.

<!-- llms:skip-begin -->
**Example output:**

```
::warning file=src/Service/UserService.php,line=87,title=size.method-count::Class has 22 methods, max recommended is 20
::error file=src/Service/UserService.php,line=42,title=complexity.ccn::Cyclomatic complexity is 15, max allowed is 10
```

**CI usage (GitHub Actions):**

```yaml
- name: Run Qualimetrix
  run: vendor/bin/qmx check src/ --format=github --no-progress
```

Annotations appear directly on the changed lines in your pull request — no SARIF upload needed. Only errors cause a non-zero exit code by default.
<!-- llms:skip-end -->

!!! tip
    Use `--format=github` for quick inline annotations. Use `--format=sarif` if you also want results in the GitHub Security tab.

---

## health

Text table of health scores for terminal output. Shows each dimension with its score, status label, thresholds, and decomposition details.

**When to use:** Quick health check from CLI, AI agent workflows, pipeline diagnostics.

**Key features:**

- Tabular display of all health dimensions (complexity, cohesion, coupling, typing, maintainability)
- Status labels with color coding (green/yellow/red)
- Threshold visibility (warning and error levels)
- Decomposition breakdown for each dimension
- A `Coverage` column, and one `Computed over N of M ...` line per dimension in the decomposition — the share of the subject that score speaks for (see [What a Score Covers](../reference/health-scores.md#what-a-score-covers))
- Supports `--namespace` and `--class` drill-down

**Worst contributors per dimension:**

The health output includes worst contributors for each dimension -- the classes or namespaces that drag down each health score the most. Control the number of contributors shown with `--format-opt=contributors=N` (default: 3):

```bash
bin/qmx check src/ --format=health --format-opt=contributors=5
```

**Usage:**

```bash
bin/qmx check src/ --format=health
bin/qmx check src/ --format=health --namespace='subtree:App\Service'
```

---

## html

Interactive treemap report with D3.js visualization. Generates a self-contained single HTML file with namespace/class hierarchy.

**When to use:** Project-wide visualization, stakeholder reports, team reviews.

**Key features:**

- Namespace/class hierarchy with LOC-proportional sizing
- Color-coded health scores per node
- Click to drill down into namespaces
- Detail panel with metrics, violations, and decomposition
- Health coverage beside each project health bar (`n/a` when coverage is undefined), from the `summary.healthCoverage` object the payload carries next to `summary.healthScores`
- Every violation of the report sits on a node of the tree, so the tree's counts agree with `summary.totalViolations`: a violation with no class or namespace node of its own — a project-level finding, a file-level one in a file that declares no class or several, a global function outside any namespace — is listed on the project root
- The report is named after the analysed project: `--format-opt=project-name=...`, else the `name` in its `composer.json`, else its directory name
- Self-contained single HTML file (no external dependencies)

**Usage:**

```bash
bin/qmx check src/ --format=html -o report.html
```

**Example workflow:**

```bash
# Generate and open the report
bin/qmx check src/ --format=html -o report.html
open report.html  # macOS
xdg-open report.html  # Linux
```

!!! note
    The `-o` (output) flag is recommended with `html` format. Without it, HTML content is written to stdout.

---

## suppressed

Machine-readable JSON composition of what a run held back from its report and
why. A separate format rather than a section of `json`: an ordinary `check`
payload never changes shape for a feature you did not ask for, no matter which
format you selected it with.

**When to use:** Auditing why an expected finding is missing, reviewing what a
`qmx.yaml` exclusion actually silences, spotting a dead `suppress_paths`/
`suppress_namespaces` entry (a typo'd path, a file the project deleted).

**Capture is armed the same way by two independent routes** — passing
`--show-suppressed`, or selecting `--format=suppressed` itself, including via
`format: suppressed` in `qmx.yaml`. Either route arms the same per-rule
exclusion capture, so the counts each surface reports for that mechanism never
disagree.

**The two surfaces are not otherwise equivalent.** `--show-suppressed` on
`--format=text` prints inline `@qmx-ignore` suppressions and per-rule
exclusions as prose. Global `path-suppression` and `namespace-suppression` appear
there only as `-v` counts, not per finding; `baseline` and `git-scope`
removals are not listed at all; and there is no text equivalent of
`neverMatched`. `suppressed` is the only surface that publishes all seven
mechanisms as individual findings.

**The composition is a multiset, not a set of findings.** One finding can be
removed by more than one mechanism — for example, a finding an inline
`@qmx-ignore` would suppress may already have been removed earlier by a
namespace exclusion. There are seven mechanisms: `suppression` (inline
`@qmx-ignore`/`@qmx-ignore-file`/`@qmx-ignore-next-line`), `path-suppression` and
`namespace-suppression` (global `suppress_paths`/`suppress_namespaces`),
`baseline` (the accepted-level ceiling), `git-scope` (`--report=git:*`
narrowing), and the two halves of the per-rule exclusion ledger configured
under `rules: {<rule-name>: {...}}` — `rule-namespace-suppression` and
`rule-path-suppression`. `byMechanism` counts entries per mechanism; because the
same finding can appear under more than one, those counts **do not sum** to
the number of distinct findings suppressed — the format's own `note` field
says so.

A separate `neverMatched` list reports configured suppressors that excluded
nothing this run: without it, a stale `suppress_paths` entry pointing at a
deleted file is indistinguishable from one that was never written.

`meta` is the same block `json` carries, including `docs` and `llmsTxt`.

**Top-level keys:** `meta`, `note`, `coverage` (the same object `json`
carries, so an audit of an incomplete run says so), `mechanisms` (all seven,
always present), `byMechanism` (count per mechanism, including zero),
`suppressed` (the multiset), `neverMatched`.

Each `suppressed` entry carries the identity `json` publishes — `channel`
(here the finding's code), `subject`, `occurrence`, `edge` — so it can be
joined to a `json` record by machine, and the finding's `message` and
`recommendation` under the same keys as `json`. It does not carry `metricValue`,
`threshold`, `techDebtMinutes` or `acceptedLevel`: the format audits what held
a finding back, and the identity leads to the finding's own record.

<!-- llms:skip-begin -->
**Example output (abbreviated, from this project's own self-analysis):**

```json
{
    "meta": {
        "version": "dev-main",
        "package": "qmx",
        "timestamp": "2026-08-29T09:14:02+00:00",
        "docs": "https://qualimetrix.dev",
        "llmsTxt": "https://qualimetrix.dev/llms.txt"
    },
    "note": "suppressed is a multiset of mechanism x finding, not a set of findings: one finding can appear under more than one mechanism, so byMechanism counts do not sum to the number of distinct findings suppressed.",
    "coverage": {
        "complete": true,
        "discovered": 1204,
        "analyzed": 1204,
        "generatedExcluded": 0,
        "failed": 0,
        "failures": []
    },
    "mechanisms": [
        "suppression",
        "path-suppression",
        "namespace-suppression",
        "baseline",
        "git-scope",
        "rule-namespace-suppression",
        "rule-path-suppression"
    ],
    "byMechanism": {
        "suppression": 12,
        "path-suppression": 0,
        "namespace-suppression": 0,
        "baseline": 0,
        "git-scope": 0,
        "rule-namespace-suppression": 58,
        "rule-path-suppression": 131
    },
    "suppressed": [
        {
            "mechanism": "suppression",
            "suppressor": "src/Infrastructure/Ast/CachedFileParser.php:15",
            "rule": "code-smell.empty-catch",
            "channel": "code-smell.empty-catch",
            "subject": "aggregate:file:src/Infrastructure/Ast/CachedFileParser.php",
            "occurrence": "6f1c0e9b2a4d7e35",
            "edge": null,
            "file": "src/Infrastructure/Ast/CachedFileParser.php",
            "line": 73,
            "symbol": "src/Infrastructure/Ast/CachedFileParser.php",
            "severity": "error",
            "message": "Empty catch block detected - exceptions should not be silently ignored",
            "recommendation": "Log the exception or add a comment explaining why it is safe to ignore."
        },
        {
            "mechanism": "rule-path-suppression",
            "suppressor": "code-smell.constructor-overinjection",
            "rule": "code-smell.constructor-overinjection",
            "channel": "code-smell.constructor-overinjection",
            "subject": "declaration:callable:Qualimetrix\\Analysis\\Run\\Contract\\Collection\\SuccessfulFileProcessing::__construct@src/Analysis/Run/Contract/Collection/SuccessfulFileProcessing.php",
            "occurrence": null,
            "edge": null,
            "file": "src/Analysis/Run/Contract/Collection/SuccessfulFileProcessing.php",
            "line": 28,
            "symbol": "Qualimetrix\\Analysis\\Run\\Contract\\Collection\\SuccessfulFileProcessing::__construct",
            "severity": "warning",
            "message": "Constructor of SuccessfulFileProcessing has 8 parameters (threshold 8). Consider using a parameter object or splitting responsibilities",
            "recommendation": "Constructor parameters: 8 (threshold: 8) — consider splitting responsibilities"
        }
    ],
    "neverMatched": [
        {
            "mechanism": "rule-path-suppression",
            "suppressor": "coupling.cbo: src/Analysis/Evidence/Design/*Visitor.php"
        }
    ]
}
```

For the two ledger mechanisms (`rule-namespace-suppression`,
`rule-path-suppression`), `suppressor` names the producer rule; for
`path-suppression`/`namespace-suppression` it is the matched configured pattern;
for `suppression` it is the directive's `file:line`; for `baseline` it is the
accepted entry's description; for `git-scope` it is the configured git
reference.
<!-- llms:skip-end -->

**Usage:**

```bash
bin/qmx check src/ --format=suppressed --no-progress > suppressed.json
```

---

## Documentation addresses in JSON reports {#documentation-addresses}

Each JSON report in the table below names where its documentation lives, so a
script or an AI agent that has only the output can find the rest: `docs` is the
documentation site and `llmsTxt` is the index written for AI agents.

| Document                                 | Where the addresses are                                                             |
| ---------------------------------------- | ----------------------------------------------------------------------------------- |
| `check --format=json`                    | `meta.docs`, `meta.llmsTxt`                                                         |
| `check --format=suppressed`              | `meta.docs`, `meta.llmsTxt`                                                         |
| `check --format=metrics`                 | Top-level `docs`, `llmsTxt`, beside `version` (the export format) and `toolVersion` |
| `check --format=sarif`                   | `runs[].tool.driver.informationUri` and `runs[].tool.driver.properties.llmsTxt`     |
| `directives --format=json`               | `meta.docs`, `meta.llmsTxt`                                                         |
| `baseline:rename-channels --format=json` | `meta.docs`, `meta.llmsTxt`                                                         |
| `debug:layer-assignment --format=json`   | `meta.docs`, `meta.llmsTxt`                                                         |
| `graph:export --format=json`             | `meta.docs`, `meta.llmsTxt`, beside its own `meta.version` (the graph format)       |

The three commands outside `check` open their document with the same `meta`
object `json` does — `version`, `package`, `timestamp`, `docs`, `llmsTxt` — ahead
of the keys that are their own. `graph:export --format=json` extends the same
`meta` block its envelope already carried, keeping its own `version` (the graph
format, not the tool's) the way `metrics` keeps its own.

Not every JSON output carries the addresses. `gitlab` is a bare array with no
object to hold them; `graph:export`'s DOT output has no envelope at all; a
refusal is always exactly `{"error": ..., "exit_code": ..., "position": ...}`,
`position` being `null` when the refusal names no key; and the baseline
file — written by `baseline:generate`, `update`, `cleanup`, and rewritten in
place by `baseline:rename-channels` — is a versioned input artifact the tool
reads back, with its own schema, not a report.

## Analysis coverage in every format

Every discovered entry is classified as analyzed, intentionally excluded as
generated, or failed. An entry is a PHP file the run measured, a PHP file it
could not read, or a filesystem entry it never opened at all — a directory it
may not list, a link it does not descend into. Generated exclusions are a
complete run; any failure makes the analysis incomplete and the policy result
non-authoritative. Zero discovered files still pass through the selected
formatter instead of being replaced with command prose.

| Format         | Coverage representation                                                                                        |
| -------------- | -------------------------------------------------------------------------------------------------------------- |
| `summary`      | Human coverage sentence after the header                                                                       |
| `text`         | Human coverage sentence after the violation summary                                                            |
| `text-verbose` | Same projection as `text --detail`                                                                             |
| `health`       | Human coverage sentence after the header                                                                       |
| `json`         | Top-level `coverage` object: `complete`, `discovered`, `analyzed`, `generatedExcluded`, `failed`, `failures[]` |
| `metrics`      | The same top-level `coverage` object as `json`                                                                 |
| `sarif`        | `runs[0].invocations[0].executionSuccessful`; failures in `toolExecutionNotifications[]`                       |
| `gitlab`       | One blocker issue per failed file with `check_name: analysis.<kind>`; a complete empty run is `[]`             |
| `checkstyle`   | Failed files are errors under synthetic file `[analysis]`, with source `qmx.analysis.<kind>`                   |
| `github`       | One `::error` annotation per failed file; complete zero-finding runs emit no annotation                        |
| `html`         | Embedded `coverage` data; incomplete runs also show a visible warning banner                                   |
| `suppressed`   | Top-level `coverage` object: `complete`, `discovered`, `analyzed`, `generatedExcluded`, `failed`, `failures[]` |

For `json` and `metrics`, each `failures[]` item has `path`, `kind`, and
`message`. Human formats distinguish no discovered files, generated-only input,
complete analysis, and incomplete analysis.

`kind` is one of five values:

| `kind`                 | The entry                                                                                            |
| ---------------------- | ---------------------------------------------------------------------------------------------------- |
| `parse`                | a PHP file that could not be parsed                                                                  |
| `processing`           | a PHP file that failed while being measured                                                          |
| `directory-symlink`    | a symbolic link to a directory, found inside a scanned tree and not followed                         |
| `not-regular-file`     | a `*.php` entry that is not a regular file — a FIFO, a socket, a device, a link whose target is gone |
| `unreadable-directory` | a directory the process may not list                                                                 |

The last three name an entry that never became a unit of analysis, so they carry
the path of that entry rather than of a PHP file. Treat a value you do not
recognize as an entry the run did not read: the list can grow, and refusing the
whole document to learn that is a worse trade than reporting the run as
incomplete.

## Comparison table

| Format         | Readable    | Machine   | Grouping                     | CI Integration             |
| -------------- | ----------- | --------- | ---------------------------- | -------------------------- |
| `summary`      | Best        | No        | Health scores, drill-down    | Any (exit code)            |
| `text`         | Good        | Parseable | `--group-by`                 | Any (exit code)            |
| `text-verbose` | Good        | No        | `--group-by` (default: file) | Any (exit code)            |
| `json`         | No          | Yes       | Built-in (by file)           | Custom scripts             |
| `metrics`      | No          | Yes       | Built-in (by symbol)         | Custom scripts, dashboards |
| `checkstyle`   | No          | Yes       | Built-in (by file)           | Jenkins, SonarQube         |
| `sarif`        | No          | Yes       | Built-in                     | GitHub, VS Code, JetBrains |
| `gitlab`       | No          | Yes       | Flat list                    | GitLab MR widget           |
| `github`       | No          | No        | Flat list                    | GitHub Actions annotations |
| `health`       | Good        | No        | Health dimensions            | Quick checks, CI           |
| `html`         | Interactive | No        | Treemap hierarchy            | Reports, reviews           |
| `suppressed`   | No          | Yes       | Flat multiset by mechanism   | Suppression auditing       |

### Exit codes

All formats use the same exit codes:

| Exit code | Meaning                                                               |
| --------- | --------------------------------------------------------------------- |
| 0         | No violations (or only warnings, under the default `--fail-on=error`) |
| 1         | At least one warning, under `--fail-on=warning`                       |
| 2         | At least one error-severity violation                                 |
| 3         | Configuration or input error                                          |
| 4         | Analysis incomplete; policy result is not authoritative               |

By default (`--fail-on=error`), warnings no longer cause exit code 1 — only errors trigger a non-zero exit. Use `--fail-on=warning` for the stricter behavior where warnings also fail. Exit 4 takes precedence over warning/error policy codes.

A run is incomplete when it could not read part of the tree it was pointed at: a file it failed to parse, a directory it may not list, a symbolic link to a directory found inside the tree, or a `*.php` entry that is not a regular file. Such an entry used to be dropped silently, so these runs used to answer 0 or 2.

A symbolic link **named as a scanned path** is the one exception: `qmx check src/linked` analyzes what the link points at and the run is complete, while the same link met while walking `src/` is reported as an entry that was not read. Naming a path is a request to analyze what is behind it; a link met inside a tree was never asked for, and following it would change which files the run measures, could leave the project root, and would not terminate on a cycle.

!!! note
    All `check` diagnostics outside the selected report payload (configuration notices and errors, deprecation messages, logging, and output-file notices) are written to **stderr**, not stdout. This means you can safely pipe the analysis output to a file or another tool without interference: `bin/qmx check src/ --format=json > results.json`.
