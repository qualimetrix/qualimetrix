# T01: Full JSON Violation Output

**Proposal:** #10 | **Priority:** Batch 0 (quick win) | **Effort:** ~1h | **Dependencies:** none

## Motivation

`--format-opt=violations=all` already exists but is undiscoverable. Users (especially AI agents) default
to truncated output (50 violations) and don't realize the full list is available. A top-level `--all` flag
would make this obvious.

## Design

1. Add `--all` CLI option to `CheckCommandDefinition` — boolean flag
2. When `--all` is set, override violation limit to unlimited for all formatters
3. Document in `--help` and website

## Files to modify

| File                                                    | Change                                                   |
| ------------------------------------------------------- | -------------------------------------------------------- |
| `src/Infrastructure/Console/CheckCommandDefinition.php` | Add `--all` option (lines 275-297 area)                  |
| `src/Infrastructure/Console/Command/CheckCommand.php`   | Pass `--all` flag to `FormatterContext`                  |
| `src/Reporting/Formatter/Json/JsonFormatter.php`        | Respect `--all` in violation limit logic (lines 218-255) |
| `src/Reporting/Formatter/Summary/SummaryFormatter.php`  | Respect `--all` for detailed violation list              |
| Website: `website/docs/reference/cli.md` (EN + RU)      | Document `--all` option                                  |
| Website: `website/docs/guides/json-output.md` (EN + RU) | Document full output usage                               |

## Acceptance criteria

- [ ] `bin/qmx check src/ --format=json --all` outputs all violations (no truncation)
- [ ] `violationsMeta.truncated` is `false` when `--all` is used
- [ ] `--all` works with all formatters that support violation limits
- [ ] `--all` and `--format-opt=violations=N` conflict produces a clear error
- [ ] `bin/qmx check --help` shows `--all` with description
- [ ] Existing `--format-opt=violations=all` still works (backward compat)
- [ ] PHPStan passes, tests pass

## Edge cases

- `--all` combined with `--detail` — both should work together (all violations, all detail)
- `--all` with `--format-opt=violations=N` (numeric) — error: conflicting options
- `--all` with `--format-opt=violations=all` — not a conflict, these are synonyms (no error)
- `--all` with formatters that don't have violation limits (checkstyle, SARIF) — no-op, no error
- `--format-opt=limit=0` already means "no limit" in JSON — `--all` is a top-level alias for this
- Large projects: `--all` with `--format=json` may produce very large output — acceptable, user's choice
