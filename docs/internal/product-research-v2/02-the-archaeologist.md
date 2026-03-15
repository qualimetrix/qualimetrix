# 02 — The Archaeologist: Documentation Accuracy Audit

**Persona:** Technical writer verifying docs against reality
**Projects:** Symfony Console (`benchmarks/vendor/symfony/console`), AIMD self-analysis (`src/`)
**Focus:** Does the documentation match actual behavior?

---

## Summary

The documentation is broadly accurate in structure and intent, but contains several significant discrepancies: the JSON format documentation describes a completely different output schema than what the tool actually produces, the `text-verbose` format is documented as a first-class feature but is actually deprecated, and the `cli-options.md` example output contains wrong default threshold values for cognitive complexity. The thresholds in `default-thresholds.md` are accurate; inaccuracies are concentrated in the format-specific documentation and the CLI options example.

---

## Findings

### CRITICAL

#### C1 — JSON format documentation describes a completely different schema

**File:** `website/docs/usage/output-formats.md`, lines 143–175

**Docs claim:**

```json
{
    "version": "1.0.0",
    "package": "aimd",
    "timestamp": "...",
    "files": [
        {
            "file": "src/Service/UserService.php",
            "violations": [...]
        }
    ],
    "summary": {
        "filesAnalyzed": 45,
        "filesSkipped": 0,
        "violations": 3,
        "errors": 2,
        "warnings": 1,
        "duration": 1.234
    }
}
```

**Actual output (`bin/aimd check src/ --format=json`):**

```json
{
    "meta": { "version": "dev-main", "package": "aimd", "timestamp": "..." },
    "summary": {
        "filesAnalyzed": 415,
        "filesSkipped": 0,
        "duration": 1.6,
        "violationCount": 1288,
        "errorCount": 365,
        "warningCount": 923,
        "techDebtMinutes": 95265,
        "debtPer1kLoc": 684.9
    },
    "health": { ... },
    "worstNamespaces": [...],
    "worstClasses": [...],
    "violations": [...],
    "violationsMeta": { ... }
}
```

The top-level keys are entirely different. Docs show a PHPMD-compatible `files`-based structure; actual output is a summary-oriented structure. The `summary` key exists in both but with different fields (`violations` in docs, `violationCount` in actual). The per-violation structure also differs: docs show `beginLine`/`endLine`/`rule`/`priority` fields, while actual has `file`/`line`/`symbol`/`namespace`/`humanMessage`/`techDebtMinutes`/`threshold` fields.

**Impact:** Any script written against the documented JSON schema will break.

---

### HIGH

#### H1 — `text-verbose` format documented as current but is deprecated

**Files:** `website/docs/usage/cli-options.md` (line 66, 75, 130), `website/docs/usage/output-formats.md` (section `## text-verbose`, comparison table)

**Docs claim:** `text-verbose` is listed as a valid format alongside `summary`, `text`, `json`, etc. The docs provide a full example, describe its default grouping behavior, and include it in the comparison table.

**Actual behavior:**

```
$ bin/aimd check src/ --format=text-verbose --only-rule=size.class-count --no-progress
Warning: --format=text-verbose is deprecated. Use --format=text --detail instead.
...
```

The format works but emits a deprecation warning. It is not listed in `--help`:

```
-f, --format=FORMAT   Output format (summary, text, json, checkstyle, sarif, gitlab, github, metrics-json, html). Default: summary
```

`text-verbose` is missing from both the help string and the available options list. The docs do not mention the deprecation, and the recommended replacement (`--format=text --detail`) is not mentioned anywhere in the `text-verbose` section.

#### H2 — CLI options example shows wrong default thresholds for cyclomatic (class level) and cognitive (method level)

**File:** `website/docs/usage/cli-options.md`, lines 558–566

**Docs claim (in the "Example output" code block):**

```
complexity.cyclomatic    Cyclomatic complexity (McCabe)
  --cyclomatic-class-warning=N   class.max_warning (default: 50)
  --cyclomatic-class-error=N     class.max_error (default: 100)

complexity.cognitive     Cognitive complexity (SonarSource)
  --cognitive-warning=N          method.warning (default: 8)
  --cognitive-error=N            method.error (default: 15)
```

**Actual code defaults** (`src/Rules/Complexity/ClassComplexityOptions.php`, `src/Rules/Complexity/MethodCognitiveComplexityOptions.php`):

- `cyclomatic` class: `maxWarning = 30`, `maxError = 50`
- `cognitive` method: `warning = 15`, `error = 30`

The docs example has both wrong: cyclomatic class thresholds are off by ~2× (50/100 vs actual 30/50), and cognitive method thresholds are off by ~2× (8/15 vs actual 15/30). The `default-thresholds.md` page has the correct values (30/50 for cyclomatic class, 15/30 for cognitive method), so the error is isolated to this example block.

#### H3 — `computed.health` rule is undocumented but active by default

**Source:** `bin/aimd rules` output

```
Maintainability
  computed.health    Checks computed health metrics against thresholds
```

This rule generates violations by default (observed in actual `--format=text` output):

```
[project]: error[health.complexity]: AiMessDetector\Metrics\ComputedMetric: health.complexity = 18.9 (error threshold: below 25.0)
```

The rule does not appear anywhere in `website/docs/rules/` (not in `index.md`, `maintainability.md`, or any other page). The `default-thresholds.md` has no section for it. Users who see `computed.health` violations in their output have no documentation to explain what the rule checks, what the thresholds mean, or how to configure or disable it.

#### H4 — `metrics-json` format documentation shows wrong symbol structure

**File:** `website/docs/usage/output-formats.md`, lines 194–235

**Docs claim:** File-level symbols have embedded per-method metrics as nested keys:

```json
{
    "type": "file",
    "metrics": {
        "loc": 150,
        "lloc": 120,
        "classCount": 1,
        "ccn:App\\Service\\UserService::calculate": 15,
        "cognitive:App\\Service\\UserService::calculate": 22,
        "halstead.volume:App\\Service\\UserService::calculate": 384.5
    }
}
```

**Actual structure:** Method metrics are in separate symbols of `"type": "method"`, not embedded in file symbols. File symbols have only file-level metrics (`classCount`, `loc`, `lloc`, `cloc`, etc.). The `"ccn:Class::method"` key pattern does not exist in actual output.

**Actual file symbol:**

```json
{
    "type": "file",
    "name": "src/Application.php",
    "file": "src/Application.php",
    "line": 1,
    "metrics": {
        "classCount": 1,
        "abstractClassCount": 0,
        "loc": 1364,
        "lloc": 924,
        "cloc": 226
    }
}
```

**Actual method symbol:**

```json
{
    "type": "method",
    "metrics": {
        "ccn": 34,
        "cognitive": 28,
        "npath": 512,
        "halstead.volume": 2300.5
    }
}
```

---

### MEDIUM

#### M1 — `text` and `text-verbose` format footers documented incorrectly

**File:** `website/docs/usage/output-formats.md`

**For `text` format**, docs show:

```
3 error(s), 0 warning(s) in 45 file(s)
```

**Actual output:**

```
14 error(s), 42 warning(s) in 132 file(s)
Technical debt: 3d 4h
```

The tech debt summary line is not documented.

**For `text-verbose` format**, docs show:

```
Files: 45 analyzed, 0 skipped | Errors: 2 | Warnings: 1 | Time: 1.23s
```

**Actual output (before deprecation warning):**

```
Technical debt by rule:
  size.class-count     ~30min    (1 violation)

1 error(s), 1 warning(s) in N file(s)
```

The actual footer uses the same format as `text` format, not the "Files: N | Errors: N | Time: N" format described in the docs.

#### M2 — `summary` format documentation shows wrong header and section labels

**File:** `website/docs/usage/output-formats.md`, lines 27–47

**Docs claim (header):** `"AI Mess Detector — Project Health"`

**Actual output:** `"AI Mess Detector — 415 files analyzed, 2.0s"`

**Docs claim (worst offenders):** `"Worst offenders (namespaces):"` and `"Worst offenders (classes):"`

**Actual output:** `"Worst namespaces"` (at project level, no separate classes section)

**Docs claim:** Both namespace and class worst-offenders sections appear together.

**Actual behavior:**
- At project level: only `"Worst namespaces"` section appears (when multiple namespaces exist)
- With `--namespace`: only `"Worst classes"` section appears
- With `--class`: no worst-offenders section

#### M3 — `--detail` flag documented as "only affects summary format" but also changes `text` format

**File:** `website/docs/usage/cli-options.md`, line 111

**Docs claim:** "Show a grouped violation list after the summary. Only affects `summary` format."

**Actual behavior:** Using `--detail` with `--format=text` changes the output from compact one-line format to verbose multi-line grouped format — the same result as the deprecated `text-verbose` format.

Without `--detail`:
```
file.php: error[rule.code]: Message (Symbol)
```

With `--detail`:
```
file.php (N violations)
  ERROR :42  Symbol::method
    Message  [rule.code]
```

#### M4 — `rules/index.md` Cohesion Rules section lists TCC and LCC with rule IDs that do not work as rules

**File:** `website/docs/rules/index.md`, lines 55–63

**Docs claim:**

```
| Metric | ID    | What it checks                                     | Recommended |
| ------ | ----- | -------------------------------------------------- | ----------- |
| TCC    | `tcc` | Fraction of public method pairs sharing properties | >= 0.5      |
| LCC    | `lcc` | Fraction including transitive connections          | >= 0.5      |
```

**Actual behavior:**

```
$ bin/aimd check src/ --only-rule=tcc --no-progress
Warning: rule "tcc" does not match any registered rule
```

TCC and LCC are metrics collected and visible in `--format=metrics-json`, but they have no rule IDs and cannot be referenced in `--disable-rule` or `--only-rule`. The `cohesion.md` page does correctly say "TCC and LCC are currently reported as metrics only", but `index.md` presents them alongside rules that do work without this caveat.

#### M5 — Default thresholds table for Code Smell rules has broken Markdown column structure

**File:** `website/docs/reference/default-thresholds.md`, lines 93–110

The table header defines 4 columns (`Rule`, `ID`, `Severity`, `Default`), but two rows have 5 cells:

```
| Constructor Over-injection | `code-smell.constructor-overinjection` | 8 params | 12 params | enabled |
| Long Parameter List        | `code-smell.long-parameter-list`       | 4 params | 6 params  | enabled |
```

These rows break the table structure. In rendered HTML the extra cells typically overflow outside the table. The intended meaning appears to be "8 params = warning, 12 params = error" but this is not expressed by the column headers.

#### M6 — Several CLI shortcut flags present in `--help` are absent from `cli-options.md` shortcut tables

**File:** `website/docs/usage/cli-options.md`, "Rule-specific shortcut flags" tabs

The following flags appear in `bin/aimd check --help` but are not listed in any tab of the shortcut flags section:

- `--constructor-overinjection-warning`, `--constructor-overinjection-error`
- `--data-class-woc-threshold`, `--data-class-wmc-threshold`, `--data-class-min-methods`, `--data-class-exclude-readonly`, `--data-class-exclude-promoted-only`
- `--god-class-wmc-threshold`, `--god-class-lcom-threshold`, `--god-class-tcc-threshold`, `--god-class-class-loc-threshold`, `--god-class-min-criteria`, `--god-class-min-methods`, `--god-class-exclude-readonly`
- `--property-exclude-readonly`, `--property-exclude-promoted-only`
- `--lcom-exclude-readonly`
- `--mi-exclude-tests`
- `--wmc-exclude-data-classes`
- `--circular-deps`, `--max-cycle-size`

The Code Smell tab in the shortcut flags section only lists `--long-parameter-list-*` and `--unreachable-code-*`, omitting the constructor overinjection, data class, and god class flags. The Design tab omits `--lcom-exclude-readonly`. The Maintainability tab omits `--mi-min-loc` is documented but `--mi-exclude-tests` is not.

#### M7 — `--format` option help string omits `text-verbose`

**File:** `bin/aimd check --help`

```
-f, --format=FORMAT   Output format (summary, text, json, checkstyle, sarif, gitlab, github, metrics-json, html). Default: summary
```

`text-verbose` is in the docs but absent from the help string. Users who type `--format=text-verbose` get a deprecation warning but no guidance from `--help`. The format should either be removed from the help entirely (with the deprecation being the signal) or listed with a deprecation notice.

---

### LOW

#### L1 — Quick Start page mixes `vendor/bin/aimd` and `bin/aimd` inconsistently

**File:** `website/docs/getting-started/quick-start.md`

Lines 22, 56, 64, 72: use `vendor/bin/aimd` (correct for a Composer-installed tool)
Lines 105, 134, 276: use `bin/aimd` (correct for the project's own dev environment, but wrong for end users who ran `composer require --dev`)

Line 134 ("Setting Up Baseline" under "Pre-commit Hook") is the most confusing — a user following the installation tutorial would have `vendor/bin/aimd`, not `bin/aimd`.

#### L2 — Docker Compose example in Quick Start uses `analyze` command instead of `check`

**File:** `website/docs/getting-started/quick-start.md`, line 211

```yaml
command: analyze src/ --baseline=baseline.json
```

`analyze` is an alias for `check` so this works, but all other examples use `check`. Using `analyze` here without explanation creates inconsistency. The alias is not documented on the page.

#### L3 — `output-formats.md` claims summary shows "Top-3 worst namespaces and classes"

**File:** `website/docs/usage/output-formats.md`, line 22

"Top-3 worst namespaces and classes with health scores"

**Actual:** The project-level summary shows only a "Worst namespaces" section (with 2 entries in the tested runs, not consistently 3). There is no "worst classes" section at the project level — it only appears when using `--namespace` to drill down.

#### L4 — `output-formats.md` claims the summary format has 6 health dimensions including `overall`

**File:** `website/docs/usage/output-formats.md`, line 19

"6 health dimensions with progress bars (complexity, cohesion, coupling, typing, maintainability, overall)"

**Actual:** The summary shows 5 indented dimension bars (Complexity, Cohesion, Coupling, Typing, Maintainability) plus one header-level "Health" bar that represents the overall score. The "Overall" score is the top-level "Health" bar, not a separate 6th dimension. The count is misleading.

#### L5 — `duplication.md` thresholds table describes warning boundary ambiguously

**File:** `website/docs/rules/duplication.md`

```
| < 50 duplicated lines  | Warning | ...
| >= 50 duplicated lines | Error   | ...
```

Actual defaults: `warning = 5`, `error = 50`. The code triggers a Warning for any block with ≥5 duplicated lines and an Error for ≥50 lines. The table describes the error boundary correctly but implies warnings only appear below 50 lines, without clarifying that there is also a minimum of 5 lines before any warning fires. A block of 4 lines generates no violation at all — this is not communicated.

#### L6 — `text-verbose` example output header does not match actual

**File:** `website/docs/usage/output-formats.md`, lines 105–124

The docs show the `text-verbose` example starting with:

```
AI Mess Detector Report
──────────────────────────────────────────────────
```

**Actual output** starts directly with the first file path (no header, no dividers). No "AI Mess Detector Report" title or separator lines appear.

---

## What Works Well

- **`default-thresholds.md`** is accurate. All numeric thresholds checked against source code (`MethodComplexityOptions`, `ClassComplexityOptions`, `MethodCognitiveComplexityOptions`, `ClassCognitiveComplexityOptions`, `MethodNpathComplexityOptions`, `ClassNpathComplexityOptions`, `WmcOptions`, `ClassCboOptions`, `NamespaceCboOptions`, `DistanceOptions`, `ClassRankOptions`, `ClassInstabilityOptions`, `NamespaceInstabilityOptions`, `LcomOptions`, `InheritanceOptions`, `NocOptions`, `TypeCoverageOptions`, `MaintainabilityOptions`, `LongParameterListOptions`, `ConstructorOverinjectionOptions`, `UnreachableCodeOptions`, `GodClassOptions`, `DataClassOptions`) match the documented defaults.

- **`checkstyle` and `sarif` format documentation** accurately reflects actual output structure and attribute names.

- **`rules/index.md`** lists all 40 rules from `bin/aimd rules` (noting TCC/LCC as a separate issue in M4). Every rule name and ID listed is accurate.

- **`rules/code-smell.md`**, **`rules/architecture.md`**, **`rules/security.md`**, **`rules/complexity.md`**, **`rules/coupling.md`**, **`rules/design.md`**, **`rules/maintainability.md`** are thorough and internally consistent.

- **`text` format one-line output** matches the documented format exactly: `file:line: severity[violationCode]: message (symbol)`.

- **Quick Start example output** accurately shows the actual header format (`AI Mess Detector — N files analyzed, Xs`), health bar format, "Worst namespaces" section, and violation summary line.

- **Exit codes** (0/1/2) and `--fail-on` behavior are documented correctly and verified against actual behavior.

- **`baseline.md`**, **`git-integration.md`**, **`configuration.md`** accurately describe their respective features with no material discrepancies found.

- **`graph:export` command** documentation matches actual `--help` output exactly.
