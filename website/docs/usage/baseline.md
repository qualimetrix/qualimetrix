# Baseline

A baseline is a snapshot of all current violations in your project. Once you have a baseline, AIMD only reports **new** violations -- anything that was already there is silently ignored.

## Why use a baseline?

When you add AIMD to an existing project, the first run may report hundreds of violations. You cannot fix them all at once. A baseline lets you:

- **Start today** -- adopt AIMD without fixing every legacy issue
- **Prevent new debt** -- every new violation is reported immediately
- **Track progress** -- see how many old violations you have fixed over time

---

## Creating a baseline

Run analysis with the `--generate-baseline` flag:

```bash
bin/aimd analyze src/ --generate-baseline=baseline.json
```

This runs the full analysis and saves all violations to `baseline.json`. The file is a JSON document with stable hashes for each violation, so it works even when line numbers shift.

!!! tip
    Commit `baseline.json` to your repository. This way, the whole team shares the same baseline.

---

## Using a baseline

Pass the `--baseline` flag on subsequent runs:

```bash
bin/aimd analyze src/ --baseline=baseline.json
```

AIMD loads the baseline, runs the analysis, and only reports violations that are **not** in the baseline. If you introduced a new violation, you will see it. If you fixed an old one, it silently disappears from the baseline matches.

---

## How it works

Each violation in the baseline is identified by:

- **File path** -- relative path to the source file
- **Violation code** -- rule identifier (e.g., `complexity.cyclomatic.method`)
- **Symbol path** -- the class, method, or namespace where the violation occurs
- **Content hash** -- a stable hash of the file contents

When AIMD runs with a baseline, it matches current violations against baseline entries. If a match is found, the violation is filtered out.

---

## Tracking progress with --show-resolved

Want to know how many old violations you have fixed? Use `--show-resolved`:

```bash
bin/aimd analyze src/ --baseline=baseline.json --show-resolved
```

This adds a summary line showing the count of resolved violations -- entries that exist in the baseline but no longer appear in the current analysis.

---

## Stale entries

A baseline entry becomes "stale" when the file it references no longer exists (e.g., a file was deleted or renamed).

By default, AIMD reports an error when stale entries are found. You have two options:

### Option 1: Ignore stale entries

```bash
bin/aimd analyze src/ --baseline=baseline.json --baseline-ignore-stale
```

### Option 2: Clean up the baseline

Remove all stale entries permanently:

```bash
bin/aimd baseline:cleanup baseline.json
```

!!! tip
    Run `baseline:cleanup` periodically (e.g., after major refactoring) to keep your baseline file clean.

---

## Inline suppression with @aimd-ignore

For cases where a violation is intentional and you do not want it in the baseline, you can suppress it directly in the code using docblock tags.

### Suppress a specific rule

```php
class LegacyProcessor
{
    /**
     * @aimd-ignore complexity.cyclomatic This method handles all legacy formats
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
 * @aimd-ignore * Generated code, do not analyze
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
 * @aimd-ignore-file
 */

class GeneratedCode
{
    // All violations in this file are suppressed
}
```

### Suppression tag syntax

| Tag | Scope | Example |
|-----|-------|---------|
| `@aimd-ignore <rule> [reason]` | The symbol this docblock belongs to | `@aimd-ignore complexity.cyclomatic Legacy code` |
| `@aimd-ignore * [reason]` | All rules for this symbol | `@aimd-ignore * Generated code` |
| `@aimd-ignore-file` | Entire file | `@aimd-ignore-file` |

The rule name supports prefix matching: `@aimd-ignore complexity` suppresses all `complexity.*` rules.

### Viewing suppressed violations

To see what was suppressed:

```bash
bin/aimd analyze src/ --show-suppressed
```

### Ignoring suppression tags

To run analysis as if no `@aimd-ignore` tags existed:

```bash
bin/aimd analyze src/ --no-suppression
```

---

## Best practices

### 1. Commit the baseline

```bash
git add baseline.json
git commit -m "chore: add AIMD baseline"
```

This ensures every team member and CI pipeline uses the same baseline.

### 2. Update the baseline periodically

After fixing a batch of violations, regenerate the baseline:

```bash
bin/aimd analyze src/ --generate-baseline=baseline.json
git add baseline.json
git commit -m "chore: update AIMD baseline (15 violations resolved)"
```

### 3. Use --show-resolved in CI

Add `--show-resolved` to your CI pipeline to track progress:

```bash
bin/aimd analyze src/ --baseline=baseline.json --show-resolved --no-progress
```

### 4. Prefer baseline over inline suppression

Use `@aimd-ignore` for intentional exceptions (e.g., generated code, known trade-offs). Use the baseline for legacy violations you plan to fix eventually.

### 5. Clean up stale entries after refactoring

```bash
bin/aimd baseline:cleanup baseline.json
git add baseline.json
git commit -m "chore: clean up stale baseline entries"
```
