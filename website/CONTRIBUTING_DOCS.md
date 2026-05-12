# Website Documentation Guide

This document defines the structure and style rules for the Qualimetrix documentation website (`website/docs/`).

---

## Bilingual Policy

All documentation pages exist in two versions:

- English: `page.md`
- Russian: `page.ru.md`

Both versions must be updated simultaneously. Content parity is required.

---

## Rule Pages (`website/docs/rules/`)

### Page Structure

Each rule group page (e.g., `complexity.md`, `design.md`) follows this structure:

```
# {Group Name} Rules

{1-3 sentence introduction explaining what this group of rules checks and why it matters.}

---

## {Rule Name}

**Rule ID:** `group.rule-name`

### What it measures

{Clear explanation of the metric/check. Use analogies and plain language.
For metrics with a formula, show the formula here.}

### Thresholds

{Table with Value/Severity/Meaning columns.
Include all configurable options if there are 3 or fewer (besides enabled).
For rules with multiple levels (method/class/namespace), use separate sub-tables.}

### Example

{10-30 lines of PHP code demonstrating the problem.
Show the BAD code, not the solution. Add inline comments showing the metric count.}

### How to fix

{Concrete refactoring steps with short code examples of the GOOD approach.}

### Implementation notes                     <!-- optional -->

{Include this section when:
- The algorithm differs from the industry standard
- Users comparing values with other tools would see discrepancies
- The formula or scope needs clarification (e.g., method-level vs class-level)

Content: which variant/algorithm is used, how it differs, why the choice was made.}

### Configuration

{Always show YAML first, then CLI:}

    ```yaml
    # qmx.yaml
    rules:
      group.rule-name:
        warning: 10
        error: 20
    ```

    ```bash
    bin/qmx check src/ --rule-opt="group.rule-name:warning=10"
    ```
```

### Code Smell Rules Exception

Code smell rules use a simplified structure (no thresholds, no formula):

```
## {Rule Name}

**Rule ID:** `code-smell.rule-name`
**Severity:** {Warning|Error}

### What it measures
### Example
### How to fix
```

Shared configuration section goes at the bottom of the page.

---

## LLM Documentation (`llms.txt` / `llms-full.txt`)

The site publishes machine-readable documentation for AI coding agents:

- **`llms.txt`** — hand-written index with links to pages (static file in `docs/`)
- **`llms-full.txt`** — auto-generated from English pages via `hooks/generate_llms_txt.py` during `mkdocs build`

### Single-source-of-truth principle

For LLM consumption, every fact should live in **one canonical place**. Other pages reference rather than duplicate:

| Topic                                                | Canonical page                     |
| ---------------------------------------------------- | ---------------------------------- |
| Rule warning/error threshold values                  | `reference/default-thresholds.md`  |
| YAML configuration schema (all keys, types, effects) | `getting-started/configuration.md` |
| CLI flag reference                                   | `usage/cli-options.md`             |
| `@qmx-ignore` / `@qmx-threshold` suppression syntax  | `usage/baseline.md`                |
| GitHub Actions reference                             | `ci-cd/github-actions.md`          |
| Health score formulas and variables                  | `reference/health-scores.md`       |

When a fact appears in a non-canonical location (e.g., a threshold value inside a rule page), wrap it with skip markers so it only renders on the website and is stripped from `llms-full.txt`.

### Pages excluded from `llms-full.txt`

The generator skips entire pages whose content is purely onboarding/tutorial (handled by `SKIP_PAGES` in `hooks/generate_llms_txt.py`):

- `getting-started/installation.md`
- `getting-started/quick-start.md`
- `usage/usage-scenarios.md`
- `changelog.md`

When adding a new page, decide its audience: if it's pure tutorial, add to `SKIP_PAGES`; if it carries reference value, leave it included and use skip markers for human-only sections.

### Skip markers

Two markers control per-section visibility in `llms-full.txt`. Both are HTML comments — invisible on the rendered website.

**`<!-- llms:skip-begin --> ... <!-- llms:skip-end -->`** — content rendered on the website but stripped from `llms-full.txt`. Use for: tutorial prose, refactoring advice, code examples, duplicate threshold tables, large JSON example blobs, CI-yaml variations, "Read more →" cross-links.

```markdown
<!-- llms:skip-begin -->
### What it measures

Cyclomatic Complexity counts the number of decision points...

### Example

```php
// ... code example
```

### How to fix

- Extract methods...
<!-- llms:skip-end -->
```

**`<!-- llms-only ... -->`** — single multi-line HTML comment whose body is hidden from the rendered website (MkDocs renders nothing for an HTML comment) but extracted into `llms-full.txt`. Use when you need a compact, agent-friendly version of a section that the human page renders verbosely.

```markdown
<!-- llms:skip-begin -->
[Verbose table for humans, 30 lines]
<!-- llms:skip-end -->

<!-- llms-only
Compact list for agents. See [Default Thresholds](../reference/default-thresholds.md) for values.
- `complexity.cyclomatic`, `complexity.cognitive`, ...
-->
```

> **Important — single comment:** the opener `<!-- llms-only` and the closer `-->` must enclose the body inside a **single** HTML comment. Do **not** split it into two markers (`<!-- llms:only-begin --> ... <!-- llms:only-end -->`) — MkDocs treats those as two separate comments and the markdown between them renders on the website.

> **Important — no `-->` inside the body:** the body must not contain `-->`. Browsers and the hook regex both terminate the comment at the first `-->`. If you need to mention the closing sequence, escape it (e.g. `--&gt;`) or wrap it in backticks. The build hook validates marker balance and prints a warning to the build log when something looks off.

> **Important — no nesting:** skip and only blocks cannot be nested. A stray inner end marker terminates the outer block early. Place them sequentially instead.

### What to skip in rule pages

Per the single-source-of-truth principle:

- `### What it measures` — pedagogical, agent already knows
- `### Example` — illustration for humans
- `### How to fix` — refactoring advice
- `### Implementation notes` — algorithm nuances (skip unless the page is the canonical home for the nuance)
- `### Thresholds` (default value tables) — duplicates `reference/default-thresholds.md`
- Educational page intros and analogies ("Think of complexity like…")
- "Read more →" cross-links

### What to keep in rule pages

- Rule ID (e.g., `**Rule ID:** complexity.cyclomatic`)
- `### Configuration` — YAML/CLI option syntax and non-default options (`exclude_data_classes`, `min_afferent`, `max_warning`, `threshold` shorthand, etc.) — these are canonical here, not in `default-thresholds.md`

When adding a new rule page, mirror the skip-marker placement from existing pages. Both EN and RU versions must have identical markers.

---

## Admonitions

Use MkDocs Material admonitions sparingly and consistently:

| Type          | When to use                                                            |
| ------------- | ---------------------------------------------------------------------- |
| `!!! note`    | Methodology nuances, scope clarifications, comparison with other tools |
| `!!! warning` | Inverted logic, counterintuitive behavior                              |
| `!!! tip`     | Practical advice, common false positive workarounds                    |

Place admonitions immediately after the section they relate to, not grouped at the end.

---

## Writing Style

- **Tone:** Conversational but precise. Explain concepts with analogies ("Think of it like...").
- **Formulas:** Show them when the metric uses one. Use plain text notation: `MI = 171 - 5.2 x ln(V) - 0.23 x CCN - 16.2 x ln(LOC)`.
- **Examples:** Show real-world scenarios, not toy code. Use domain names like `OrderService`, `UserRepository`.
- **Thresholds:** Always explain what the numbers mean in practice, not just the values.
- **Cross-references:** Link to related rules when they share metrics (e.g., WMC references CCN).

---

## When to Update Documentation

### Website documentation

| Change type                 | Pages to update                                             |
| --------------------------- | ----------------------------------------------------------- |
| Rule added/changed/removed  | `rules/{group}.md` + `reference/default-thresholds.md`      |
| Metric algorithm changed    | `rules/{group}.md` (Implementation notes section)           |
| CLI option added/changed    | `usage/cli-options.md`                                      |
| Output format added/changed | `usage/output-formats.md`                                   |
| Baseline behavior changed   | `usage/baseline.md`                                         |
| Git integration changed     | `usage/git-integration.md`                                  |
| Configuration option added  | `getting-started/configuration.md` + `usage/cli-options.md` |
| CI/CD integration changed   | `ci-cd/`                                                    |
| Default thresholds changed  | `reference/default-thresholds.md` + `rules/{group}.md`      |

### Internal documentation

| Change type                            | Files to update                                                      |
| -------------------------------------- | -------------------------------------------------------------------- |
| Rule added/changed/removed             | `src/Rules/README.md` + `CLAUDE.md` (feature list)                   |
| Metric collector added/changed         | `src/Metrics/README.md`                                              |
| CLI alias added for a rule             | `src/Configuration/README.md` (CLI aliases table)                    |
| Pipeline phase changed                 | `src/Analysis/README.md` + `docs/ARCHITECTURE.md` + `CLAUDE.md`      |
| DI registration mechanism changed      | `CLAUDE.md` (§ Symfony DI) + `docs/ARCHITECTURE.md` (link to CLAUDE) |
| Formatter added/changed                | `src/Reporting/README.md`                                            |
| Configuration pipeline stage added     | `src/Configuration/README.md`                                        |
| Baseline/suppression mechanism changed | `src/Baseline/README.md`                                             |

**Note:** `DocumentationConsistencyTest` automatically validates rule names in `default-thresholds.md`,
CLI aliases in `Configuration/README.md`, and YAML examples in `README.md` against source code.
If you add a rule or CLI alias, the test will catch missing documentation.
