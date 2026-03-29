# T07: GroupBy Class/Namespace Extensions

**Proposal:** #5 | **Priority:** Batch 3 (output enrichment) | **Effort:** ~3h | **Dependencies:** none

## Motivation

JSON output has a flat `violations[]` array. Building a "worst files" or "worst classes" view
requires post-processing. The existing `GroupBy` enum has `File`, `Rule`, `Severity` but not
`Class` or `Namespace`. Extending the enum and applying grouping in formatters covers the need.

## Design

### Extend GroupBy enum

Add two new cases:
```php
case ClassName = 'class';
case NamespaceName = 'namespace';
```

(Using `ClassName`/`NamespaceName` since `class` and `namespace` are reserved words in PHP.)

### JSON formatter behavior

When `--group-by=class` (or namespace/file), output **both** `violations[]` (flat, for backward
compat) AND `violationGroups` (grouped view):

```json
{
  "violations": [...],
  "violationGroups": {
    "App\\Service\\UserService": {
      "count": 5,
      "violations": [...]
    },
    "App\\Repository\\OrderRepository": {
      "count": 3,
      "violations": [...]
    }
  },
  "violationsMeta": { ... }
}
```

When `--group-by=none` (default) — `violationGroups` key is absent. No schema change.

**Violation limit** applies to the total flat list first, then groups are built from the
(possibly truncated) list. `violationsMeta` counts reflect the flat list.

### Grouping key extraction

- `File`: violation's `filePath`
- `Class`: violation's symbol path class component (or file path if no class)
- `Namespace`: violation's symbol path namespace component (or directory if no namespace)
- `Rule`: violation's rule name
- `Severity`: violation's severity level

## Files to modify

| File                                                    | Change                                   |
| ------------------------------------------------------- | ---------------------------------------- |
| `src/Reporting/GroupBy.php`                             | Add `Class_` and `Namespace_` cases      |
| `src/Reporting/Formatter/Json/JsonFormatter.php`        | Apply grouping when group-by is set      |
| `src/Infrastructure/Console/CheckCommandDefinition.php` | Update `--group-by` choices if hardcoded |
| Tests for JsonFormatter                                 | Add grouping test cases                  |
| Website: JSON output guide (EN + RU)                    | Document grouping options                |

## Acceptance criteria

- [ ] `--format=json --group-by=class` produces grouped output
- [ ] `--format=json --group-by=namespace` produces grouped output
- [ ] `--format=json --group-by=file` uses existing `File` case (verify it works)
- [ ] Default `--group-by=none` produces flat array (backward compatible)
- [ ] Groups sorted by count descending (worst first)
- [ ] `violationsMeta` still present with correct totals
- [ ] PHPStan passes, tests pass

## Edge cases

- Violation without class context (file-level violation) → grouped under file path
- Violation without namespace (global namespace) → grouped under `<global>`
- Empty violations → empty `violationGroups` object
- `--group-by=class` with `--format=text` → text formatter should support grouping (violations grouped under class headers)
- Adding `ClassName`/`NamespaceName` to enum requires updating all exhaustive `match` statements on `GroupBy` in sorters/renderers
