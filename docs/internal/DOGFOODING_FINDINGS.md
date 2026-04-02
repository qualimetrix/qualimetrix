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

**Hypothesis:** `RuleExecutor` is a private (non-public) service. During Symfony DI compilation, the container may inline the definition and instantiate a **new** `RulePathExclusionProvider()` from the constructor default instead of resolving the `Reference` to the synthetic service. The existing `RuleNamespaceExclusionProvider` works — the difference may be in how the synthetic service is resolved for inlined definitions.

**Investigation plan:**
1. Make `RuleExecutor` public temporarily and verify the `pathExclusionProvider` instance via reflection
2. Compare DI wiring of `RuleNamespaceExclusionProvider` vs `RulePathExclusionProvider` — are they resolved identically?
3. Check if Symfony's compiler pass inlines the `RuleExecutor` arguments before synthetic services are set

**Workaround:** Use `@qmx-ignore-file` in class docblock instead of `exclude_paths` for code smell rules.

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
- Issue #1 was introduced by the F1 implementation (per-rule `exclude_paths`) and needs a fix.
- The `exclude_paths` feature works correctly for non-code-smell rules (verified via unit tests and `RulePathExclusionProviderTest`). The bug is specific to the DI wiring path.
