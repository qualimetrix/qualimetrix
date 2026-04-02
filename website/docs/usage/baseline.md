# Baseline

A baseline is a snapshot of all current violations in your project. Once you have a baseline, Qualimetrix only reports **new** violations -- anything that was already there is silently ignored.

## Why use a baseline?

When you add Qualimetrix to an existing project, the first run may report hundreds of violations. You cannot fix them all at once. A baseline lets you:

- **Start today** -- adopt Qualimetrix without fixing every legacy issue
- **Prevent new debt** -- every new violation is reported immediately
- **Track progress** -- see how many old violations you have fixed over time

---

## Creating a baseline

Run analysis with the `--generate-baseline` flag:

```bash
bin/qmx check src/ --generate-baseline=baseline.json
```

This runs the full analysis and saves all violations to `baseline.json`. The file is a JSON document with stable hashes for each violation, so it works even when line numbers shift.

!!! tip
    Commit `baseline.json` to your repository. This way, the whole team shares the same baseline.

---

## Using a baseline

Pass the `--baseline` flag on subsequent runs:

```bash
bin/qmx check src/ --baseline=baseline.json
```

Qualimetrix loads the baseline, runs the analysis, and only reports violations that are **not** in the baseline. If you introduced a new violation, you will see it. If you fixed an old one, it silently disappears from the baseline matches.

---

## How it works

Each violation in the baseline is identified by:

- **File path** -- relative path to the source file
- **Violation code** -- rule identifier (e.g., `complexity.cyclomatic.method`)
- **Symbol path** -- the class, method, or namespace where the violation occurs
- **Content hash** -- a stable hash of the file contents

When Qualimetrix runs with a baseline, it matches current violations against baseline entries. If a match is found, the violation is filtered out.

---

## Tracking progress with --show-resolved

Want to know how many old violations you have fixed? Use `--show-resolved`:

```bash
bin/qmx check src/ --baseline=baseline.json --show-resolved
```

This adds a summary line showing the count of resolved violations -- entries that exist in the baseline but no longer appear in the current analysis.

---

## Stale entries

A baseline entry becomes "stale" when the file it references no longer exists (e.g., a file was deleted or renamed).

By default, Qualimetrix reports an error when stale entries are found. You have two options:

### Option 1: Ignore stale entries

```bash
bin/qmx check src/ --baseline=baseline.json --baseline-ignore-stale
```

### Option 2: Clean up the baseline

Remove all stale entries permanently:

```bash
bin/qmx baseline:cleanup baseline.json
```

!!! tip
    Run `baseline:cleanup` periodically (e.g., after major refactoring) to keep your baseline file clean.

---

## Inline suppression with @qmx-ignore

For cases where a violation is intentional and you do not want it in the baseline, you can suppress it directly in the code using comments.

Suppression tags work in all comment styles:

- PHPDoc docblocks: `/** @qmx-ignore rule */`
- Line comments: `// @qmx-ignore rule`
- Block comments: `/* @qmx-ignore rule */`

!!! note "Limitation"
    Inline same-line comments are not supported: `$x = foo(); // @qmx-ignore rule` will **not** work.
    Place the comment on a separate line before the target.

### Suppress a specific rule

```php
class LegacyProcessor
{
    /**
     * @qmx-ignore complexity.cyclomatic This method handles all legacy formats
     */
    public function process(array $data): array
    {
        // complex but intentional logic
    }
}
```

### Suppress all rules for a symbol

```php
/**
 * @qmx-ignore * Generated code, do not analyze
 */
class GeneratedMapper
{
    // ...
}
```

### Suppress all rules for an entire file

Add a file-level docblock at the top of the file:

```php
<?php
/**
 * @qmx-ignore-file
 */

class GeneratedCode
{
    // All violations in this file are suppressed
}
```

### Suppress on the next line only

Use `@qmx-ignore-next-line` in a comment to suppress a violation on the very next line:

```php
class CliApplication
{
    public function run(): void
    {
        // process commands...

        // @qmx-ignore-next-line code-smell.exit CLI entry point
        exit(0);
    }
}
```

This is useful for one-off suppressions where a docblock-level tag would be too broad.

This also works for suppressing empty catch violations:

```php
try {
    $cache->delete($key);
} catch (CacheException) {
    // @qmx-ignore code-smell.empty-catch Best-effort caching
}
```

### Suppression tag syntax

| Tag                                     | Scope                              | Example                                                 |
| --------------------------------------- | ---------------------------------- | ------------------------------------------------------- |
| `@qmx-ignore <rule> [reason]`           | The symbol this comment belongs to | `@qmx-ignore complexity.cyclomatic Legacy code`         |
| `@qmx-ignore * [reason]`                | All rules for this symbol          | `@qmx-ignore * Generated code`                          |
| `@qmx-ignore-next-line <rule> [reason]` | The next line only                 | `@qmx-ignore-next-line code-smell.exit CLI entry point` |
| `@qmx-ignore-file`                      | Entire file                        | `@qmx-ignore-file`                                      |

The rule name supports prefix matching: `@qmx-ignore complexity` suppresses all `complexity.*` rules.

### Viewing suppressed violations

To see what was suppressed:

```bash
bin/qmx check src/ --show-suppressed
```

### Ignoring suppression tags

To run analysis as if no `@qmx-ignore` tags existed:

```bash
bin/qmx check src/ --no-suppression
```

---

## Per-symbol threshold overrides with @qmx-threshold

Sometimes a class or method legitimately needs different thresholds than the project default. Instead of suppressing the violation entirely with `@qmx-ignore`, you can override specific thresholds using `@qmx-threshold` annotations.

### Override a threshold for a class

```php
/**
 * @qmx-threshold complexity.cyclomatic method.warning=20 method.error=40
 */
class ComplexStateMachine
{
    // Methods in this class use higher complexity thresholds
}
```

### Override a threshold for a method

```php
class OrderProcessor
{
    /**
     * @qmx-threshold complexity.cyclomatic warning=25 error=50
     * @qmx-threshold complexity.npath warning=500 error=2000
     */
    public function processLegacyOrder(array $data): Order
    {
        // This method handles many legacy edge cases
    }
}
```

### Syntax

```
@qmx-threshold <rule> <option>=<value> [<option>=<value> ...]
```

- The rule name supports the same dotted format as configuration (`complexity.cyclomatic`, `coupling.cbo`, etc.)
- Options use the same keys as YAML configuration and `--rule-opt` CLI flag
- Multiple `@qmx-threshold` tags can be used on the same symbol for different rules
- Threshold overrides are scoped to the annotated symbol only -- they do not propagate to child symbols

!!! tip
    Use `@qmx-threshold` when a violation is expected but you still want Qualimetrix to enforce _some_ limit. Use `@qmx-ignore` when you want to suppress the violation entirely.

---

## Best practices

### 1. Commit the baseline

```bash
git add baseline.json
git commit -m "chore: add Qualimetrix baseline"
```

This ensures every team member and CI pipeline uses the same baseline.

### 2. Update the baseline periodically

After fixing a batch of violations, regenerate the baseline:

```bash
bin/qmx check src/ --generate-baseline=baseline.json
git add baseline.json
git commit -m "chore: update Qualimetrix baseline (15 violations resolved)"
```

### 3. Use --show-resolved in CI

Add `--show-resolved` to your CI pipeline to track progress:

```bash
bin/qmx check src/ --baseline=baseline.json --show-resolved --no-progress
```

### 4. Prefer baseline over inline suppression

Use `@qmx-ignore` for intentional exceptions (e.g., generated code, known trade-offs). Use the baseline for legacy violations you plan to fix eventually.

### 5. Clean up stale entries after refactoring

```bash
bin/qmx baseline:cleanup baseline.json
git add baseline.json
git commit -m "chore: clean up stale baseline entries"
```

### 6. Escape tags in documentation

When referencing `@qmx-ignore` or `@qmx-threshold` in docblocks as documentation (format descriptions, examples), wrap them in backticks to prevent the parser from treating them as real tags:

```php
/**
 * Use `@qmx-ignore complexity` to suppress this rule.       // escaped — not parsed
 * Use `@qmx-threshold complexity.cyclomatic 15` to override. // escaped — not parsed
 *
 * @qmx-ignore coupling Real suppression tag                   // real — will be parsed
 */
```

An unpaired backtick is safe — without a closing pair, the tag is parsed normally.
