# Baseline — Baseline and Suppression Subsystem

## Overview

The Baseline subsystem provides two mechanisms for ignoring known violations:

1. **Baseline files** — a JSON snapshot of all current violations, used to adopt Qualimetrix in legacy projects
2. **Inline suppression** — `@qmx-ignore` tags in docblocks and comments for intentional exceptions

## Structure

```
Baseline/
├── Baseline.php              # VO: stored baseline data (version, entries, diff/stale detection)
├── BaselineEntry.php         # VO: single violation entry (rule + hash)
├── BaselineGenerator.php     # Generates Baseline from list of Violations
├── BaselineLoader.php        # Loads Baseline from JSON file (with version validation)
├── BaselineWriter.php        # Writes Baseline to JSON file (atomic write)
├── ViolationHasher.php       # Produces stable 16-char hashes for violations
│
├── Filter/
│   └── BaselineFilter.php    # ViolationFilterInterface: filters violations present in baseline
│
└── Suppression/
    ├── SuppressionType.php       # Enum: Symbol, NextLine, File
    ├── Suppression.php           # VO: parsed suppression tag (rule, reason, line, type)
    ├── SuppressionExtractor.php  # Extracts suppression tags from AST node docblocks
    └── SuppressionFilter.php     # ViolationFilterInterface: filters violations by suppression tags
```

## Baseline Workflow

```
Violations -> BaselineGenerator -> Baseline -> BaselineWriter -> JSON file
                                                                     |
JSON file -> BaselineLoader -> Baseline -> BaselineFilter -> filtered Violations
```

**Version history:**
- **Version 2**: Introduced canonical symbol path keys
- **Version 3**: Rule naming scheme update (`group.rule-name` format)
- **Version 4**: 16-char violation hashes (was 8-char in v3)
- **Version 5**: Relative file paths in canonical keys (no path resolution needed)

Only version 5 is supported. Older versions (2, 3, 4) are rejected with an error message asking to regenerate.

## ViolationHasher

Produces stable hashes based on:
- Rule name
- Namespace, type (class), and member (method) from SymbolPath
- Violation code

**Deliberately excluded** (for stability across refactoring):
- Line number (shifts when code is added above)
- Method parameters (renaming should not invalidate baseline)
- Message text (rewording should not invalidate baseline)
- Severity (may change when thresholds are reconfigured)

## Suppression Subsystem

### Supported Tags

| Tag                     | Scope             | SuppressionType |
| ----------------------- | ----------------- | --------------- |
| `@qmx-ignore <rule>`    | Symbol (docblock) | Symbol          |
| `@qmx-ignore-next-line` | Next line only    | NextLine        |
| `@qmx-ignore-file`      | Entire file       | File            |

Rule names support prefix matching: `@qmx-ignore complexity` suppresses all `complexity.*` rules.

### How Suppression Is Wired

1. **FileProcessor** (in `Analysis/Collection/`) uses `SuppressionExtractor` to extract suppression tags during AST traversal
2. Extracted suppressions are carried in `CollectionResult` alongside metrics
3. During violation filtering, `ViolationFilterPipeline` (in `Infrastructure/Console/`) applies `SuppressionFilter` to remove suppressed violations

### SuppressionFilter Logic

- **File-level**: suppresses all matching violations in the file
- **Symbol-level**: suppresses matching violations at or after the suppression line
- **Next-line**: suppresses matching violations on the exact next line only

## Related Documents

- [src/Core/README.md](../Core/README.md) — contracts (`ViolationFilterInterface`)
- [src/Analysis/README.md](../Analysis/README.md) — pipeline orchestration, `FileProcessor`
- [src/Infrastructure/README.md](../Infrastructure/README.md) — `ViolationFilterPipeline`
- [website/docs/usage/baseline.md](../../website/docs/usage/baseline.md) — user-facing documentation
