# 06 — Config Wizard: Configuration UX Audit

**Persona:** Experienced PHP developer setting up AIMD for a team
**Projects:** Symfony DI (`benchmarks/vendor/symfony/dependency-injection`), Monolog (`benchmarks/vendor/monolog/monolog/src`)
**Focus:** Can I configure the tool correctly from docs alone?

---

## Summary

AIMD's configuration system has a critical structural defect: `YamlConfigLoader` maintains a hard-coded allow-list of root YAML keys that is missing three keys that are fully implemented and documented elsewhere (`failOn`, `computedMetrics`, `computed_metrics`). These keys are silently rejected with a config error, making the feature completely inaccessible via config file. Additionally, unknown rule names in the `rules:` section are silently ignored without any warning, and the docs present the wrong key casing for `fail_on` (snake_case) while the validator only accepts `failOn` (camelCase) — except `failOn` is also not in the allow-list. Rule toggling and threshold overrides work well once you know the correct format.

---

## Findings

### HIGH

**H1: `failOn` / `fail_on` blocked by YAML validator despite being documented**

The configuration docs (`website/docs/getting-started/configuration.md`) explicitly show:
```yaml
fail_on: error
```
The CLI options docs (`website/docs/usage/cli-options.md`) confirm this key is supported in `aimd.yaml`. However, `YamlConfigLoader::ALLOWED_ROOT_KEYS` does not include `fail_on` or `failOn`. Both variants are rejected:

```
Configuration error: Invalid configuration in /tmp/test-failon.yaml: Unknown configuration keys: fail_on
```

`ConfigFileStage` has full support for `failOn` (line 157), but it never gets called — the loader aborts first. The feature is entirely dead code at the YAML level.

**Affected file:** `src/Configuration/Loader/YamlConfigLoader.php` — add `'failOn'` and `'fail_on'` to `ALLOWED_ROOT_KEYS`.

---

**H2: `computedMetrics` / `computed_metrics` blocked by YAML validator**

`ConfigFileStage` (line 161) handles `computed_metrics` and `computedMetrics`. `ComputedMetricsConfigResolver` exists and works. But neither key is in `ALLOWED_ROOT_KEYS`:

```
Configuration error: Invalid configuration in ...: Unknown configuration keys: computedMetrics
```

There is no user-facing documentation for computed metrics configuration at all (no page in `website/docs/`), compounding the dead-code problem — users cannot discover nor use this feature.

**Affected file:** `src/Configuration/Loader/YamlConfigLoader.php` — add `'computedMetrics'` and `'computed_metrics'` to `ALLOWED_ROOT_KEYS`.

---

**H3: Unknown rule name in `rules:` config section is silently ignored**

A config with a typo or non-existent rule name produces no warning and runs as if the config entry were not there:

```yaml
rules:
  nonexistent.rule:
    threshold: 10
```

Result: full analysis with 505 violations, exit 2. No indication that the config was malformed. This is a silent misconfiguration that a team member setting up thresholds would never catch.

**Root cause:** `YamlConfigLoader` only validates that rule values are arrays/bools/null, not that rule names are registered. `RuleOptionsCompilerPass` links known rules at container build time, so unknown names are simply lost.

**Suggested fix:** At config load or pipeline time, validate rule names against the registered rule list and emit a warning (not a hard error, to allow forward compatibility).

---

### MEDIUM

**M1: Docs show snake_case keys, runtime expects camelCase for some keys**

The configuration docs show `disabled_rules:` (snake_case), which works because `ALLOWED_ROOT_KEYS` lists both `disabled_rules` and `disabledRules`. However, `failOn` (only camelCase would logically be expected given the pattern) is not listed at all. The inconsistency creates confusion:

- `disabled_rules` (snake) ✓ — works
- `disabledRules` (camelCase) ✓ — works
- `fail_on` (snake) ✗ — rejected (and what docs show)
- `failOn` (camelCase) ✗ — also rejected
- `only_rules` (snake) ✓ — works
- `onlyRules` (camelCase) ✓ — works

The pattern is inconsistent. Both variants work for list keys but neither works for `failOn`. Docs should reflect actual behavior.

---

**M2: Rule option key format undiscoverable from docs**

The configuration docs show the hierarchical threshold format:
```yaml
rules:
  complexity.cyclomatic:
    method:
      warning: 15
      error: 25
```

But the `rules` command output and threshold reference table (`website/docs/reference/default-thresholds.md`) uses different notation (`--cyclomatic-error`, `--rule-opt=complexity.cyclomatic:method.error=...`). The flat legacy format (`errorThreshold`, `warningThreshold`) is not documented at all, though it works. There is no single source of truth for what keys each rule accepts.

**Observed behavior:** Both `error_threshold: 30` (snake) and `errorThreshold: 30` (camel) work for legacy format; `method.error: 25` works for hierarchical format.

---

**M3: `--only-rule` + `enabled: false` in config produces confusing behavior**

When config disables a rule (`size.class-count: enabled: false`) and `--only-rule=size.class-count` is passed, the tool emits:

```
Warning: both --disable-rule and --only-rule are active. This may result in no rules being enabled.
```

Then reports "No violations found" (exit 0). The warning message incorrectly says `--disable-rule` is active even though the disable came from config's `enabled: false`, not from CLI. The semantic is also surprising: users expect `--only-rule` (a narrowing filter) to supersede config-level disables.

---

**M4: String value for numeric threshold silently coerces to 0**

```yaml
rules:
  complexity.cyclomatic:
    error_threshold: "not_a_number"
```

PHP's `(int)` cast converts `"not_a_number"` to `0`, causing every method with CCN > 0 to trigger an error. This produces 1156 violations instead of the expected ~505. No warning is emitted. A user who accidentally writes `error_threshold: "20"` (quoted) would also get threshold 0 via the legacy format path — though this case casts fine since `(int)"20" === 20`.

---

**M5: `--disable-rule` prefix discrepancy vs individual rule disabling**

In Phase 2 testing:
- `--disable-rule=complexity` (prefix) removes 51 violations (505 → 454)
- `--disable-rule=complexity.cyclomatic --disable-rule=complexity.cognitive` removes only 36 violations (505 → 469)

This is correct behavior — the prefix disables all 4 complexity rules (including `complexity.npath` and `complexity.wmc`), while the second command only disables 2. The discrepancy is not a bug but it is a usability issue: there is no easy way from the summary output to know which rules belong to the `complexity` group. The `rules` command lists them but this requires a separate lookup.

---

### LOW

**L1: Config error message does not suggest the correct key**

When `fail_on` is rejected:
```
Configuration error: Invalid configuration in ...: Unknown configuration keys: fail_on
```

The error does not suggest the correct syntax or point to the documentation. Since `fail_on` is the documented form, the error message is actively misleading.

**L2: Health scores are metric-based, not violation-based — not obvious in config context**

When all complexity rules are disabled, the health summary still shows `Complexity: 55.7% Acceptable`. Users configuring the tool to disable all complexity rules will expect the health score to disappear or change. This is working as designed (health scores are computed from raw metrics, not from violations), but the config docs do not explain this.

**L3: `--only-rule=nonexistent.rule` exits 0 with "No violations found"**

Passing a completely unknown rule name to `--only-rule` emits a warning (`Warning: rule "nonexistent.rule" does not match any registered rule`) and exits 0. This is correct for CI (no violations found = pass), but a user who misspells `--only-rule=complexity.cyclomatic` as `--only-rule=complexity.cyclomtic` will get a silent false-negative.

**L4: No computed metrics documentation**

The feature exists in code and its config key is supported in `ConfigFileStage`, but:
1. The YAML validator blocks it
2. No documentation page exists
3. No example configs reference it

This feature is effectively hidden.

---

## Configuration Matrix

| Feature             | Config Key                               | CLI Equivalent   | Works in YAML?            | Interaction                    |
| ------------------- | ---------------------------------------- | ---------------- | ------------------------- | ------------------------------ |
| Paths               | `paths:`                                 | positional args  | Yes                       | CLI paths override config      |
| Exclude dirs        | `exclude:`                               | `--exclude`      | Yes                       | CLI merges with config         |
| Exclude paths       | `exclude_paths:` / `excludePaths:`       | `--exclude-path` | Yes                       | CLI merges with config         |
| Disable rules       | `disabled_rules:` / `disabledRules:`     | `--disable-rule` | Yes                       | CLI merges with config list    |
| Only rules          | `only_rules:` / `onlyRules:`             | `--only-rule`    | Yes                       | CLI overrides config           |
| Fail on             | `fail_on:` / `failOn:`                   | `--fail-on`      | **No** (validator blocks) | N/A                            |
| Format              | `format:`                                | `--format`       | Yes                       | CLI overrides config           |
| Rule thresholds     | `rules.{name}.method.{warning\|error}:`  | `--rule-opt=...` | Yes                       | CLI overrides config           |
| Rule enable/disable | `rules.{name}.enabled: false`            | N/A              | Yes                       | `--only-rule` does NOT restore |
| Computed metrics    | `computedMetrics:` / `computed_metrics:` | N/A              | **No** (validator blocks) | N/A                            |
| Cache               | `cache:`                                 | N/A              | Yes                       | —                              |
| Aggregation         | `aggregation:`                           | N/A              | Yes                       | —                              |

---

## What Works Well

1. **Auto-discovery**: `aimd.yaml` in the project root is picked up automatically. Verbose mode (`-v`) reports config sources clearly: `Configuration loaded from: defaults, composer.json, test-aimd.yaml`.

2. **Unknown rule warning for CLI**: `--only-rule=nonexistent.rule` emits a clear warning. The parity with config-side validation is missing (unknown rule in config = silent), but CLI behavior is correct.

3. **Threshold formats are flexible**: Both snake_case (`error_threshold`) and camelCase (`errorThreshold`) work for rule option keys, as do both flat legacy format and hierarchical `method: { error: N }` format.

4. **Prefix matching for rule groups**: `--disable-rule=complexity` disables all `complexity.*` rules as expected. Works both in CLI and config's `disabled_rules:` list.

5. **Config error messages are actionable** for real invalid keys: "Unknown configuration keys: X" is clear and exits 1. The problem is that documented valid keys are falsely classified as unknown.

6. **CLI overrides config** as documented. `--fail-on=none` overrides even config-level fail_on (when it eventually works). `--format=json` overrides config `format:`. The precedence chain (defaults → composer.json → config file → CLI) works correctly.

---

## Root Cause Summary

All three major bugs (H1, H2, H3) share a root cause: `YamlConfigLoader::ALLOWED_ROOT_KEYS` was not updated when `ConfigFileStage` gained support for `failOn` and `computedMetrics`. This is a maintenance gap between the loader (validation layer) and the stage (processing layer). The fix for H1 and H2 is a one-line addition to `ALLOWED_ROOT_KEYS` per key. H3 requires cross-cutting validation against the rule registry.
