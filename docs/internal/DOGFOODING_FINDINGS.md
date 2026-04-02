# Dogfooding Findings — Issues Discovered During Implementation

Date: 2026-04-02
Context: Implementation of dogfooding features (F1–F4) and self-analysis configuration.

---

## 1. [BUG] `exclude_paths` does not work for code smell rules via RuleExecutor

**Severity:** High — the feature works in unit tests but not in real analysis runs.

**Reproduction:**
```yaml
# qmx.yaml
rules:
  code-smell.empty-catch:
    exclude_paths:
      - src/Infrastructure/Ast/*
```
The violation on `src/Infrastructure/Ast/CachedFileParser.php` is still reported.

**Verified:**
- `PathMatcher` matches the pattern correctly (fnmatch returns true)
- `RulePathExclusionProvider::isExcluded()` returns true when called directly
- `RuleOptionsRegistry` and container-level `RulePathExclusionProvider` share the same instance (confirmed via `spl_object_id`)

**Root cause (confirmed via triple review):** Both `RuleNamespaceExclusionProvider` and
`RulePathExclusionProvider` were registered as separate synthetic services, then injected
into `RuleExecutor` (a non-public service) via `new Reference(...)`. Synthetic services
set via `$container->set()` on `ContainerBuilder` may be lost during compilation — the
compiled container does not preserve these runtime-set instances for private service
injection. `RuleNamespaceExclusionProvider` appeared to work because it was accessed
through `RuleOptionsRegistry` (also synthetic) in the configuration pipeline path,
not through `RuleExecutor`.

**Fix applied:** Removed separate synthetic registrations of both exclusion providers.
`RuleExecutor` now receives providers through `RuleOptionsRegistry` (which already
owns them). This reduced synthetic service count from 9 to 7 and eliminated the
instance mismatch.

**Lesson:** Avoid registering objects as separate synthetic services when they are
already owned by another synthetic service. Prefer accessing them through the owner.

---

## 2. [UX] JSON format is capped at 50 violations

**Severity:** Low — summary totals are accurate, only the violations array is truncated.

`--format=json` always returns exactly 50 violations (top N by impact score), even when `summary.violationCount` reports 586+. This is a hardcoded limit.

**Impact:** CI/CD integrations (SARIF parsers, GitLab Code Quality) that consume the JSON violations array get an incomplete picture.

**Suggestion:** Add `--format-opt=limit=0` to allow unlimited violations, or at minimum document the 50-violation cap.

---

## 3. [UX] Empty catch detection ignores comments — no way to suppress inline

**Severity:** Low-Medium — affects developer experience.

`CodeSmellVisitor::checkEmptyCatch()` filters out `Nop` nodes (comment-only statements):
```php
$realStmts = array_filter($node->stmts, fn($s) => !$s instanceof Nop);
```

This is intentional (a comment is not error handling), but combined with issue #4 below, it creates an unsuppressable violation pattern:

```php
} catch (SomeException) {
    // Intentionally ignored — this comment does NOT suppress the violation
}
```

The only options are `@qmx-ignore-file` in class docblock or `exclude_paths` in config (which doesn't work per issue #1).

**Suggestion:** Either:
- (a) Treat catch blocks with a comment containing "intentional"/"ignore"/"expected" as non-empty
- (b) Support `@qmx-ignore` in regular `//` comments (see #4)

---

## 4. [UX] `@qmx-ignore` only works in docblocks, not regular comments

**Severity:** Low-Medium — unintuitive for developers.

`SuppressionExtractor` reads only `$node->getDocComment()` (PHP docblocks `/** ... */`). Regular comments (`// @qmx-ignore`, `/* @qmx-ignore */`) are ignored.

This means:
- You can't suppress a specific line with a `//` comment
- `@qmx-ignore-next-line` only works if placed inside a `/** */` docblock attached to a node
- For file-level suppression, you must add to the class docblock (not a standalone comment)

**Suggestion:** Extend suppression extraction to scan all comment types (not just docblocks). This would require using `$node->getComments()` in addition to `$node->getDocComment()`, or scanning the token stream for `@qmx-ignore` patterns.

---

## Notes

- Issues #2–#4 are pre-existing and were not introduced by the dogfooding changes.
- Issue #1 was introduced by the F1 implementation and has been fixed (see root cause above).

---

## Follow-up: Improvements to implement

These are pre-existing issues surfaced during dogfooding. Not blockers, but worth addressing.

### F1. JSON format: configurable violation limit

**Related to:** Issue #2 above.

Add `--format-opt=limit=N` (or `limit: N` in config) to control the number of violations
in JSON output. Default 50 is fine for text, but CI integrations (SARIF, GitLab Code Quality)
need the full list.

**Scope:** `src/Reporting/Formatter/JsonFormatter.php` — the `MAX_VIOLATIONS` constant.

### F2. `@qmx-ignore` in regular comments

**Related to:** Issues #3 and #4 above.

Currently `@qmx-ignore` only works in PHP docblocks (`/** ... */`). Regular comments
(`// @qmx-ignore`, `/* @qmx-ignore */`) are ignored. This creates UX friction:
- Can't suppress a specific line with `//`
- Empty catch blocks with intentional comments still trigger violations

**Scope:** `src/Baseline/Suppression/SuppressionExtractor.php` — needs to scan
`$node->getComments()` in addition to `$node->getDocComment()`, or parse the token stream.

### F3. Empty catch with intentional comments

**Related to:** Issue #3 above. Depends on F2.

Once `@qmx-ignore` works in regular comments, users can write:
```php
} catch (CacheException) {
    // @qmx-ignore code-smell.empty-catch — best-effort caching
}
```

Alternatively, treat catch blocks with comments containing "intentional"/"ignore"/"expected"
as non-empty (heuristic approach, independent of F2).
