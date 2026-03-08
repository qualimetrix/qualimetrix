# Website Documentation Guide

This document defines the structure and style rules for the AI Mess Detector documentation website (`website/docs/`).

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
    # aimd.yaml
    rules:
      group.rule-name:
        warning: 10
        error: 20
    ```

    ```bash
    bin/aimd analyze src/ --rule-opt="group.rule-name:warning=10"
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
