# Baseline — Baseline and Suppression Subsystem

## Overview

The Baseline subsystem provides two mechanisms for ignoring known violations:

1. **Baseline files** — a JSON snapshot of all current violations, used to adopt Qualimetrix in legacy projects
2. **Inline suppression** — `@qmx-ignore` tags in docblocks and comments for intentional exceptions

## Structure

```
Baseline/
├── Baseline.php                 # VO: a loaded/captured file (generated, scope, entries, inert entries)
├── BaselineIdentity.php         # VO: what an entry is about — symbol + channel + dependency edge
├── BaselineEdge.php             # VO: the dependency edge half of an identity
├── BaselineEntry.php            # VO: one accepted group (identity, magnitudes, count, mode)
├── BaselineEntryMode.php        # Enum: the optional `mode` (only `suppress`)
├── EntrySelector.php            # VO: the short handle addressing one entry
├── InertBaselineEntry.php       # VO: an entry that cannot be applied, and why
├── InertEntryReason.php         # Enum: why an entry is inert
├── BaselineEntryParser.php      # Parses one raw entry into a valid or inert entry
├── BaselineEntryRejection.php   # Internal control-flow signal used by the parser
├── BaselineConflictException.php # The file changed between read and write
├── BaselineGenerator.php        # Captures a run's findings as entries (injected clock)
├── BaselineCapture.php          # VO: what one capture produced — the baseline plus its refusals
├── UncapturedGroup.php          # VO: a group that produced no entry, and why
├── UncapturedReason.php         # Enum: undeclared channel / no finite magnitude
├── BaselineLoader.php           # Loads a version 10 file
├── BaselineWriter.php           # Writes atomically under a compare-and-swap guard
│
├── Filter/
│   └── BaselineFilter.php    # ViolationFilterInterface: filters violations present in baseline
│
└── Suppression/
    ├── SuppressionType.php              # Enum: Symbol, NextLine, File
    ├── Suppression.php                  # VO: parsed suppression tag (rule, reason, line, type)
    ├── SuppressionExtractor.php         # Extracts suppression tags from AST node docblocks
    ├── SuppressionFilter.php            # ViolationFilterInterface: filters violations by suppression tags
    └── ThresholdOverrideExtractor.php   # Extracts @qmx-threshold annotations from AST node docblocks
```

## Baseline Workflow

```
Violations -> BaselineGenerator -> BaselineCapture -> BaselineWriter -> JSON file
                                    |          `-> uncaptured groups -> reported
JSON file -> BaselineLoader -> Baseline -> BaselineFilter -> filtered Violations
```

Two kinds of group never become an entry: one on a channel no rule declares, and a
`magnitude` group where some member reports no finite number. Both are the fail-safe
direction — an entry that could not be applied would be reported as inert forever while
suppressing nothing — but the refusal is **returned** in `BaselineCapture::$uncaptured`
and named in the output. A dropped group is written nowhere, so nothing downstream could
report it otherwise, and "Baseline with 0 entries written" would read as success.

**Version history:**
- **Version 2**: Introduced canonical symbol path keys
- **Version 3**: Rule naming scheme update (`group.rule-name` format)
- **Version 4**: 16-char violation hashes (was 8-char in v3)
- **Version 5**: Relative file paths in canonical keys (no path resolution needed)
- **Version 10**: Entries record the magnitude a finding was accepted at, keyed by
  identity instead of an opaque hash

Only version 10 is loadable. A version 5 file is rejected with a message naming
`bin/qmx baseline:migrate`; every other version is rejected as unsupported.

## Entry Identity

An entry is about an **identity**: the symbol, the channel (`ruleName#violationCode`),
and — when the finding carries one — the dependency edge (target plus reference kind).
The set of violations in a run sharing one identity is that entry's **group**.

**Deliberately excluded** (for stability across refactoring):
- Line number (shifts when code is added above)
- Method parameters (renaming should not invalidate baseline)
- Message text (rewording should not invalidate baseline)
- Severity (may change when thresholds are reconfigured)

**Known collisions, accepted rather than discriminated away** (see the
`BaselineIdentity` docblock for the full argument): two declarations of one FQN share
an entry, a trait method is keyed once for all consumers, and a namespace literally
called `__PROJECT__` collides with the project sentinel. Adding the declaring file as a
discriminator would strand every entry on the most common refactor there is, and does
not exist at all for namespace-, project- and file-keyed findings.

### Entry selector

Every entry is addressable by a **selector** — 12 lowercase hexadecimal characters,
the truncated SHA-256 of the complete identity. It is printed next to an entry so a
user copies rather than composes it. `<symbol>#<channel>` cannot serve: `#` already
separates the two halves of a channel key, and two forbidden edges out of one class on
one channel agree on everything else.

## File Contract (version 10)

```json
{
  "version": 10,
  "generated": "2026-08-05T12:00:00+03:00",
  "scope": ["src"],
  "entries": {
    "method:App\\OrderService::calculate": [
      { "channel": "complexity.cyclomatic#complexity.cyclomatic.method",
        "magnitudes": [25], "count": 1 }
    ],
    "file:src/Legacy/dup.php": [
      { "channel": "duplication.code-duplication#duplication.code-duplication",
        "magnitudes": [40, 100], "count": 2 }
    ],
    "class:App\\Web\\Controller": [
      { "channel": "architecture.layer-violation#architecture.layer-violation",
        "edge": { "target": "class:App\\Db\\Connection", "type": "new" },
        "count": 1 }
    ]
  }
}
```

| Field       | Contract                                                      |
| ----------- | ------------------------------------------------------------- |
| `version`   | Exactly `10`                                                  |
| `generated` | ISO 8601, from an injected clock (`Core\Time\ClockInterface`) |
| `scope`     | The analysed path set that produced this file, normalized     |
| `entries`   | Canonical symbol keys → deterministic entry lists             |

Entry invariants:

- `count` is a positive integer and is always present.
- `magnitudes` holds exactly `count` finite numbers and is present exactly for
  channels declared `magnitude`; it is absent for `occurrence` channels. Each value is
  `round($v, 6)` and `-0.0` normalizes to `0`. The list is stored ascending — a
  determinism convention only, since the comparison counts members per severity level
  and never reads it positionally.
- `edge` is present exactly when the finding carries a dependency target.
- `mode` is optional; `suppress` is the only recognized value.

Entries under one symbol key sort by channel and then by edge, **whatever their state** —
an entry that happens to be inert in the writing process sorts exactly where it would if
it were applicable. Only an entry whose channel could not be read at all has nothing to
sort on; those follow, ordered by selector. Order therefore does not depend on which
command wrote the file: `baseline:cleanup` runs without an analysis configuration and
loads every `computed.*` entry inert, which under a valid-block-then-inert-block layout
moved those lines on every cleanup.

Everything except `generated` is deterministic for the same analysis. The writer pins
the float representation at the encode site (`serialize_precision=-1` for the duration
of the encode), so the same analysis produces byte-identical files whatever the
reader's ini says — six-decimal normalization alone would not do it, since `0.1` has no
exact binary form and prints as `0.10000000000000001` at `serialize_precision=17`. A
normalized `40.0` is written as `40` and reloads as an `int`, which is harmless for a
numeric comparison and stable from the first write.

### Entries that cannot be applied

A malformed entry, an undeclared channel, a shape mismatch in either direction, an
unrecognized `mode`, a component carrying the identity key separator, or a duplicated
identity makes an entry **inert**: it does not
suppress, and it does not fail the load — refusing to load would punish a whole run
for one bad line. An inert entry keeps its symbol, channel, selector and reason for
reporting, and its raw payload so a rewrite preserves the line verbatim.

### Writes

`BaselineWriter` writes to a temporary file and renames. A sibling `<baseline>.lock`
file (worth adding to `.gitignore`) holds an exclusive lock across both the
content-hash check and the rename, so a read-modify-write cannot silently discard a
concurrent writer: a `Baseline` loaded from a file carries that file's content hash,
and writing it back to a file that no longer matches raises
`BaselineConflictException`. The hash is a property of the guard, never a field of the
file. `write()` returns the token for the bytes it wrote, and
`Baseline::withSourceContentHash()` carries it back — without which a caller writing one
instance twice would be refused by its own first write.

The wait for the lock is bounded (10 seconds by default): a crashed writer releases
through the OS, but a hung one would otherwise stop the next `qmx` invocation with no
output at all, which in CI reads as a job timeout rather than a baseline problem.

**Every entry read is an entry written.** The writer never groups entries under a key two
of them can share, because resolving such a clash by overwriting would delete a line
nobody decided to delete. Two identities that are distinct in memory but collapse onto
one symbol key once `file:` paths are made project-relative are refused outright rather
than merged.

## Suppression Subsystem

### Supported Tags

| Tag                     | Scope                        | SuppressionType |
| ----------------------- | ---------------------------- | --------------- |
| `@qmx-ignore <rule>`    | Symbol (docblock or comment) | Symbol          |
| `@qmx-ignore-next-line` | Next line only               | NextLine        |
| `@qmx-ignore-file`      | Entire file                  | File            |

All three tags work in PHPDoc docblocks (`/** */`), line comments (`//`), and block comments (`/* */`).

> **Note:** Inline same-line comments (e.g., `$x = foo(); // @qmx-ignore rule`) are not supported.
> Only comments on a separate line before the target are recognized.

Rule names support prefix matching: `@qmx-ignore complexity` suppresses all `complexity.*` rules.

### How Suppression Is Wired

1. **FileProcessor** (in `Analysis/Collection/`) uses `SuppressionExtractor` to extract suppression tags from docblocks and regular comments during AST traversal
2. Extracted suppressions are carried in `CollectionResult` alongside metrics
3. During violation filtering, `ViolationFilterPipeline` (in `Infrastructure/Console/`) applies `SuppressionFilter` to remove suppressed violations

### SuppressionFilter Logic

- **File-level**: suppresses all matching violations in the file
- **Symbol-level**: suppresses matching violations at or after the suppression line
- **Next-line**: suppresses matching violations on the exact next line only

## Threshold Overrides

### @qmx-threshold Annotation

Allows per-class or per-method threshold overrides without fully suppressing the rule.

| Annotation       | Effect             | Metrics computed? | Violation possible? |
| ---------------- | ------------------ | ----------------- | ------------------- |
| (none)           | Default thresholds | Yes               | Yes                 |
| `@qmx-threshold` | Custom thresholds  | Yes               | Yes (if exceeded)   |
| `@qmx-ignore`    | Suppressed         | Yes               | No (filtered out)   |

### Syntax

```php
/**
 * @qmx-threshold coupling.cbo 30
 * @qmx-threshold complexity.cyclomatic warning=15 error=25
 * @qmx-threshold complexity.cognitive error=30 warning=15
 * @qmx-threshold complexity.cyclomatic warning=15
 * @qmx-threshold coupling.instability 0.8
 * @qmx-threshold complexity.cyclomatic 20 -- Legacy state machine
 */
class ContainerFactory { ... }
```

The value portion has two accepted forms:

- a single non-negative integer or decimal, which sets both thresholds;
- `warning=N`, `error=N`, or both keys in either order.

These are generic override keys, not arbitrary YAML or `--rule-opt` option names. An optional
non-empty reason may follow only after `--` or an em dash (`—`). Prefix patterns such as
`complexity` and the `*` wildcard are supported, but they cannot be validated against one
specific rule's threshold semantics during extraction. Prefer exact rule names whenever possible.

### How Threshold Overrides Are Wired

1. **FileProcessor** uses `ThresholdOverrideExtractor` to extract `@qmx-threshold` tags during AST traversal
2. Extracted overrides are carried in `CollectionResult` alongside suppressions
3. `AnalysisPipeline` passes overrides to `AnalysisContext`
4. During rule execution, rules call `getEffectiveSeverity()` or `getEffectiveOptions()` (from `AbstractRule`), which applies the override before checking thresholds
5. Unlike `@qmx-ignore` (post-rule filter), overrides are applied **during** rule execution

### Scope

- `@qmx-threshold` on a **class** docblock: applies to rule evaluations inside that class, including its methods
- `@qmx-threshold` on a **method** docblock: applies to that specific method only
- When scopes overlap, the smallest source span wins, so a method override takes precedence over its class override
- If matching scopes have the same span, the first extracted override wins

## Escaping in Documentation

When referencing `@qmx-ignore` or `@qmx-threshold` tags in docblocks as documentation
(e.g., format descriptions, usage examples), wrap them in backticks to prevent the parser
from interpreting them as real tags:

```php
/**
 * Use `@qmx-ignore complexity` to suppress this rule.       // ← escaped, not parsed
 * Use `@qmx-threshold complexity.cyclomatic 15` to override. // ← escaped, not parsed
 *
 * @qmx-ignore coupling Real suppression tag                   // ← real, will be parsed
 */
```

The extractors strip backtick-delimited regions before pattern matching.
An unpaired backtick is safe — without a closing pair, the regex does not match,
and the tag is parsed normally.

## Related Documents

- [src/Core/README.md](../Core/README.md) — contracts (`ViolationFilterInterface`)
- [src/Analysis/README.md](../Analysis/README.md) — pipeline orchestration, `FileProcessor`
- [src/Infrastructure/README.md](../Infrastructure/README.md) — `ViolationFilterPipeline`
- [website/docs/usage/baseline.md](../../website/docs/usage/baseline.md) — user-facing documentation
