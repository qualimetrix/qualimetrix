# Configuration

Qualimetrix works out of the box with sensible defaults. A configuration file lets you customize thresholds, disable rules, and exclude paths to fit your project.

---

## Configuration File

Create a file named `qmx.yaml` in your project root. Qualimetrix automatically looks for this file.

You can also specify a file explicitly:

```bash
vendor/bin/qmx check src/ --config=my-config.yaml
```

---

## Configuration Sections

### Paths

Directories to analyze:

```yaml
paths:
  - src/
```

!!! note
    If you pass paths as CLI arguments (e.g., `vendor/bin/qmx check src/ lib/`), they take precedence over the config file.

### Exclude

Directories to skip entirely. Files in these directories are not analyzed at all:

```yaml
exclude:
  - vendor/
  - tests/Fixtures/
```

### Include Generated

By default, files with a `@generated` annotation in the first 2 KB are automatically skipped from analysis. To include them:

```yaml
include_generated: true
```

Equivalent CLI: `--include-generated`

### Exclude Paths

Path patterns for suppressing violations. Unlike `exclude`, these files **are still analyzed** (their metrics are collected), but violations are not reported. Supports both directory prefixes and glob patterns:

```yaml
exclude_paths:
  - src/Entity                # prefix: matches all files under src/Entity/
  - src/Metrics/*Visitor.php  # glob: matches visitor files only
```

Also available as a CLI option: `--exclude-path` (merged with YAML config).

!!! warning "Does not apply to project-scoped architecture findings"
    `exclude_paths` (and `--exclude-path`) never suppress `architecture.layer-violation` or
    `architecture.circular-dependency` violations, for the same reason as `exclude_namespaces`
    below: a layer-policy violation is not a metric, so a path exclusion aimed at quieting noisy
    metrics must not double as an undocumented way to disable architecture enforcement.

    Which findings are exempt is a **declared property of the channel**, not something read off
    the spelling of the rule name — a rule is not exempt because it happens to be called
    `architecture.something`.

    What is left for suppressing such a finding depends on the channel.
    `architecture.layer-violation` is real code debt, so `@qmx-ignore
    architecture.layer-violation` and a baseline entry both still apply. The four layer-policy
    diagnostics beside it — `architecture.coverage`, `architecture.unreachable-layer`,
    `architecture.potential-shadow` and `architecture.empty-template` — report a mistake in the
    *configuration*, so neither applies to them; see
    [Rules > Architecture](../rules/architecture.md). Their remaining answers are the `exclude:`
    block inside the architecture layer configuration itself and, for coverage specifically,
    `coverage: ignore`.

    As with `exclude_namespaces`, this exemption is **global-only** — the per-rule
    `exclude_paths` described below still works for architecture rules.

### Exclude Namespaces

Suppress violations for classes in specific namespaces (prefix matching). Like `exclude_paths`, files are still analyzed and metrics are collected, but violations are not reported. This applies to all rules globally:

```yaml
exclude_namespaces:
  - App\Tests
  - App\Generated
```

This is useful when entire namespace subtrees should never produce violations. For per-rule exclusions, use `exclude_namespaces` inside a rule configuration instead (see below).

Also available as a CLI option: `--exclude-namespace` (merged with YAML config).

!!! warning "Does not apply to project-scoped architecture findings"
    `exclude_namespaces` (and `--exclude-namespace`) never suppress `architecture.layer-violation`
    or `architecture.circular-dependency` violations. A layer-policy violation is not a metric —
    silently dropping it would let a noisy-metric exclusion double as an undocumented way to
    disable architecture enforcement. Which findings are exempt is a declared property of the
    channel, not a consequence of how the rule name is spelled.

    `@qmx-ignore architecture.layer-violation` and a baseline entry still apply to
    `architecture.layer-violation`. They do **not** apply to the four layer-policy diagnostics —
    `architecture.coverage`, `architecture.unreachable-layer`, `architecture.potential-shadow`
    and `architecture.empty-template` — which report a configuration mistake rather than code
    debt; for those, use the `exclude:` block inside the architecture layer configuration
    itself, or `coverage: ignore` for the coverage diagnostic.

    This exemption is **global-only**. The per-rule `exclude_namespaces` / `exclude_paths`
    described below (`rules: {architecture.layer-violation: {exclude_namespaces: [...]}}`) still
    works for architecture rules, same as for any other rule — see
    [Exclude namespaces from a rule](#rules) below and the architecture rule's
    [Suppression section](../rules/architecture.md#suppression) for why that asymmetry is
    intentional: naming the rule explicitly is an unambiguous, auditable choice, while a
    project-wide `exclude_namespaces` entry is not.

### Rules

Control which rules are active and set custom thresholds.

**Disable a rule entirely:**

```yaml
rules:
  code-smell.boolean-argument:
    enabled: false
```

**Override thresholds:**

Each rule defines severity levels. When a metric exceeds a threshold, a violation is reported at that severity. For example, the cyclomatic complexity rule has thresholds for methods:

```yaml
rules:
  complexity.cyclomatic:
    callable:
      warning: 15
      error: 25
```

This means: report a **warning** when a method's cyclomatic complexity reaches 15, and an **error** when it reaches 25.

**Threshold shorthand:**

If you want a single pass/fail threshold (all violations become errors), use the `threshold` key:

```yaml
rules:
  complexity.cyclomatic:
    callable:
      threshold: 15    # warning=15, error=15 → all violations are errors

  size.method-count:
    threshold: 25      # same as warning: 25, error: 25
```

This is useful in CI where you want a simple pass/fail cutoff without graduated warnings. You cannot mix `threshold` with explicit `warning`/`error` keys **from the same configuration layer** (the same preset, the same `qmx.yaml`, or the same CLI invocation) for the same rule level — that raises a configuration error.

**Overriding the mode across layers:** a higher-priority layer (config file over a preset, CLI over the config file) may freely switch mode for a rule level — e.g. a `strict` preset sets `warning`/`error`, and `--rule-opt=size.method-count:threshold=25` on the command line switches that rule to a single cutoff. The CLI's `threshold` overrides the preset's `warning`/`error` instead of conflicting with them:

```yaml
# qmx.yaml (or a preset)
rules:
  size.method-count:
    warning: 10
    error: 20
```

```bash
# CLI switches this rule to simple pass/fail mode — no "cannot mix" error
bin/qmx check src/ --rule-opt=size.method-count:threshold=25
```

The same applies in the other direction (a lower layer's `threshold` overridden by a higher layer's `warning`/`error`), and to hierarchical rules at the level the keys are set (e.g. `complexity.cyclomatic`'s `callable:`/`class:`).

`coupling.cbo` and `coupling.instability` accept the same bare `threshold` shorthand at their own top level too, but with a different effect than `complexity.cyclomatic`'s: since their `class`/`namespace` defaults already match, a top-level `threshold` applies uniformly to BOTH levels at once, instead of only the more granular one:

```yaml
rules:
  coupling.cbo:
    threshold: 15   # class AND namespace: warning=error=15
```

For type coverage, dedicated shorthand keys are available:

```yaml
rules:
  design.type-coverage:
    param_threshold: 90
    return_threshold: 90
    property_threshold: 80
```

**Exclude namespaces from a rule:**

Any rule can exclude specific namespaces using prefix matching. Violations from matching namespaces are suppressed:

```yaml
rules:
  complexity.cyclomatic:
    exclude_namespaces:
      - App\Tests
      - App\Legacy
    callable:
      warning: 15
      error: 25

  coupling.cbo:
    exclude_namespaces:
      - App\Tests
    exclude_paths:
      - src/Infrastructure/DependencyInjection
```

This is useful when certain namespaces (e.g., tests, generated code, legacy modules) should not trigger violations for a specific rule, while still being analyzed for metrics.

**Exclude selected namespace-aggregate channels from a rule:**

Use `exclude_namespace_channels` when one violation channel is structurally inapplicable to
part of the namespace tree, but class findings and the producer's other channels must remain:

```yaml
rules:
  computed.health:
    exclude_namespace_channels:
      health.cohesion:
        - App\Metrics\Coupling
        - App\Generated\*
```

The option is a non-empty map from channel selector to a non-empty list of namespace
prefixes or globs. The key reads the same grammar as everywhere else — an **exact channel
name**, `X.*` for the strict descendants of `X`, or the explicit `ruleName#violationCode`
pair; see [Rule and channel selectors](#rule-and-channel-selectors) below. A bare prefix such
as `health` is an error, not a group: write `health.*`. Exact `health.cohesion` leaves the
sibling `health.coupling` untouched.

The key must address a channel **the rule it is written under actually emits**. A key that
names another rule's channel, or no channel at all, ends the run with exit code 3 and a
message listing that rule's channels — it used to be accepted and exclude nothing:

```yaml
rules:
  computed.health:
    exclude_namespace_channels:
      # the same channel, spelled in full; identical in effect to `health.cohesion`
      'computed.health#health.cohesion':
        - App\Metrics\Coupling
```

Here the pair is redundant: the key already sits under a rule name, so both spellings say the
same thing. It is accepted for consistency of grammar, not because it adds reach.

!!! warning "Only namespace-aggregate findings are removed"
    The option filters findings whose subject is a **namespace**. A rule that reports per
    occurrence (`code-smell.*`, `security.*`, `architecture.layer-violation`) or only per class
    (`design.lcom`) has nothing for it to remove, and a key naming such a channel is accepted
    and then does nothing. The four layer-policy diagnostics — `architecture.coverage`,
    `architecture.unreachable-layer`, `architecture.potential-shadow`,
    `architecture.empty-template` — report against the project as a whole and are likewise
    outside its reach; use the `exclude:` block inside the architecture layer configuration
    instead.
Only aggregate Namespace violations are removed. Class-level `health.cohesion` findings in
the same namespace and sibling channels remain. The existing `exclude_namespaces` option is
unchanged and stays producer-wide across class and namespace findings.

!!! info "Works for every rule, including the architecture rules"
    `exclude_namespaces`, `exclude_namespace_channels`, and `exclude_paths` are extracted and applied at the framework level for
    **any** rule name, regardless of whether that rule's Options class declares such a field —
    this is deliberately not opt-in per rule. That includes `architecture.layer-violation` and
    `architecture.circular-dependency`, which are exempt from the *global* `exclude_namespaces`
    and `exclude_paths` above but not from this per-rule form: naming the rule explicitly makes
    the suppression an unambiguous, auditable choice rather than an incidental side effect of a
    project-wide exclusion. See the architecture rule's
    [Suppression section](../rules/architecture.md#suppression) for the reasoning.

**Exclude paths from a rule:**

Any rule can exclude specific file paths using prefix or glob matching. Violations from matching files are suppressed:

```yaml
rules:
  coupling.cbo:
    exclude_paths:
      - src/Metrics                # prefix: all files in src/Metrics/
      - src/Metrics/*Visitor.php   # glob: only visitor files
```

This works alongside `exclude_namespaces` -- both filters are applied. Unlike the global `exclude_paths`, per-rule `exclude_paths` only affects the specific rule, not all rules.

**Visibility:** unlike `@qmx-ignore`, these suppressions happen silently by default — nothing in
the default output hints that violations were dropped. Run with `-v` to see a per-rule breakdown
of how many violations were suppressed this way (the namespace bucket combines
`exclude_namespaces` and `exclude_namespace_channels`, separately from `exclude_paths`),
and add `--show-suppressed` to also list each suppressed violation, alongside `@qmx-ignore`
suppressions.

<!-- llms:skip-begin -->
**Per-symbol threshold overrides with `@qmx-threshold`:**

In addition to project-wide thresholds in YAML, you can override thresholds for individual classes or methods using `@qmx-threshold` annotations directly in source code:

```php
/**
 * @qmx-threshold complexity.cyclomatic warning=20 error=40
 */
class ComplexStateMachine
{
    // Methods in this class use higher complexity thresholds
}
```

The class-level override also applies to method evaluations inside the class; a narrower callable-level override takes precedence.

See [Baseline > @qmx-threshold](../usage/baseline.md#per-symbol-threshold-overrides-with-qmx-threshold) for full syntax and examples.
<!-- llms:skip-end -->

### Rule and Channel Selectors

Every place that names a rule or a finding channel — `disabled_rules`, `only_rules`, their CLI
equivalents, `exclude_namespace_channels`, and the `@qmx-ignore` family in source code — reads
the name the same way:

| Form                              | Meaning                                                                                                                                                                                                |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `complexity.cyclomatic`           | **exactly** that name, and nothing else                                                                                                                                                                |
| `complexity.*`                    | **strictly the descendants** of `complexity` — `complexity.cyclomatic`, `complexity.wmc` and so on. `complexity` itself is not included; if a name is both a rule and a channel, address it separately |
| `coupling.cbo#coupling.cbo.class` | the rule and the channel spelled out explicitly. Both halves must be exact; no `*` inside this form                                                                                                    |

A bare prefix is **not** a group. `complexity` on its own selects nothing and is rejected:

```
Rule selector "complexity" does not match any registered producer, group, or channel.
```

A `X.*` that has no descendants is rejected for the same reason. Most rules emit a single
channel whose name equals the rule name, so `code-smell.eval.*` is an error — write
`code-smell.eval`.

An unresolvable selector in configuration or on the command line ends the run with exit code 3
before any report is produced. Guessing at intent was the previous behaviour, and it silently
turned typos into "nothing was selected".

**Which side of the pair a name refers to is decided by the directive, not by the name.** The
same string means different things depending on where it is written:

- `@qmx-ignore code-smell.boolean-argument` always names a **channel**, because a suppression
  belongs to the channel;
- `@qmx-threshold code-smell.boolean-argument` always names a **rule**, because a threshold
  belongs to the rule's one options object. `@qmx-threshold` therefore takes an exact rule name
  and accepts **no wildcard at all** — resetting a threshold across a group was a footgun, not
  a feature;
- keys of the `rules:` section and the owner before `:` in `--rule-opt RULE:option=value` name a
  **rule**, exactly. A key like `rules: { complexity: {...} }` used to be accepted and configure
  nothing at all; it is now rejected.

See [Baseline](../usage/baseline.md)
for the full inline-directive syntax, and [Rules > Annotations](../rules/annotation.md) for what
the tool reports when a directive addresses nothing.

### Disabled Rules

Disable specific rules, channels, or whole groups:

```yaml
disabled_rules:
  - code-smell.boolean-argument
  - duplication.code-duplication
```

Equivalent CLI: `--disable-rule=code-smell.boolean-argument --disable-rule=duplication.code-duplication`

To disable a whole group, use the wildcard:

```yaml
disabled_rules:
  - code-smell.*
```

### Only Rules

Run only specified rules (everything else is disabled):

```yaml
only_rules:
  - complexity.cyclomatic
  - complexity.cognitive
```

Equivalent CLI: `--only-rule=complexity.cyclomatic --only-rule=complexity.cognitive`

### Fail On

Control which severity levels cause a non-zero exit code:

```yaml
fail_on: error    # Only fail on errors (default)
# fail_on: warning  # Fail on warnings too
# fail_on: info     # Fail on any violation, including informational ones
# fail_on: none     # Never fail on violations
```

The default is `error`: warnings and Info-level diagnostics are shown in the output but do not cause a non-zero exit code. Use `fail_on: warning` if you want warnings to also fail the build, or `fail_on: info` to additionally enforce Info diagnostics (for example, `annotation.unused-directive`, which reports a suppression that no longer suppresses anything).

!!! warning "`fail_on` does not govern configuration errors"
    Some channels report a mistake in the configuration rather than debt in the code — the four
    layer-policy diagnostics (`architecture.coverage`, `architecture.unreachable-layer`,
    `architecture.potential-shadow`, `architecture.empty-template`) and the three inline-directive
    diagnostics (`annotation.unresolved-directive`, `annotation.unsupported-threshold`,
    `annotation.invalid-threshold`). These never take part in the `fail_on` comparison at all:
    they end the run with a non-zero exit code even under `fail_on: none`, and no baseline entry
    or `@qmx-ignore` can accept them. The tool is not judging your code there — it is saying it
    cannot do what you asked.

### Exclude Health

Exclude specific health dimensions from scoring. The excluded dimensions are not shown in the health summary and do not contribute to the overall score:

```yaml
exclude_health:
  - typing
  - maintainability
```

Equivalent CLI: `--exclude-health=typing --exclude-health=maintainability`

### Memory limit

Set the PHP memory limit for analysis. By default, PHP's `memory_limit` from `php.ini` is used.

```yaml
memory_limit: 1G    # 1 gigabyte
# memory_limit: -1  # Unlimited
```

Equivalent CLI: `--memory-limit=1G`

### Format

Set the default output format:

```yaml
format: summary   # Default
# format: json
# format: html
```

### Cache

Control AST caching for faster repeated runs:

```yaml
cache:
  enabled: true         # Default: true
  dir: .qmx-cache       # Default: .qmx-cache
```

Equivalent CLI: `--no-cache` to disable, `--cache-dir=DIR` to change directory.

### Parallel Processing

Control the number of parallel workers for file analysis:

```yaml
parallel:
  workers: 4     # Fixed number of workers
  # workers: 0   # Disable parallelism (sequential)
  # workers: 1   # Disable parallelism (single-process)
```

By default, Qualimetrix auto-detects the optimal worker count based on CPU cores. Equivalent CLI: `--workers=4`

!!! tip
    Use `workers: 1` for debugging or single-process environments. `workers: 0` disables parallelism (sequential execution); auto-detect is the default when the option is omitted.

### Namespace Detection

Qualimetrix derives project namespaces from the invocation working directory's
`composer.json` and falls back to source parsing where needed. Namespace
detection is not configurable: `namespace.strategy` and
`namespace.composer_json` are rejected as unknown keys.

### Coupling

Configure framework namespace prefixes for the CBO (Coupling Between Objects) metric. Dependencies on framework namespaces are tracked separately as `cbo_app` and `ce_framework`:

```yaml
coupling:
  framework-namespaces:
    - Symfony
    - Doctrine
    - Psr
    - Illuminate
```

When no `framework-namespaces` are configured, `cbo_app` equals `cbo` (no effect).

### Aggregation

Namespace aggregation follows the analyzed declarations. Custom
`aggregation.prefixes` and `aggregation.auto_depth` settings are no longer
supported and are rejected as unknown keys.

### Architecture

Layer rules and the allow-list for the `architecture.layer-violation` rule live under the top-level `architecture:` key. The full schema is documented in [Architecture Rules](../rules/architecture.md); the keys most relevant to general configuration are:

```yaml
architecture:
  layers:
    - name: 'domain-{module}'                         # template layer with capture variable
      patterns: ['App\Module\{module}\Domain\**']
    - name: shared-kernel
      patterns: ['App\Shared\**']

  allow:
    'domain-{m}':
      - shared-kernel
      - target: 'domain-{m}'
        relations: [implements, extends]              # whitelist of dependency kinds

  coverage: ignore                                    # ignore | warn | error
  max_expanded_layers: 500                            # cumulative cap on template expansion
```

**Capture variables** in layer names and patterns use the syntax `{name}` (single namespace segment) or `{name:**}` (cross-segment). The same variable name within one layer entry binds to one value (co-binding); variables in different entries are independent. See the [layer-templates section](../rules/architecture.md#layer-templates) for the full grammar.

**`max_expanded_layers`** caps the total number of concrete layers produced by template expansion across all templates (default `500`). The cap protects against pathological broad templates whose binding tuples would blow up the layer count. Raise the ceiling explicitly when a monorepo legitimately has more bounded contexts than the default allows; overflow rejects at expansion with an actionable error.

---

## Presets

<!-- llms:skip-begin -->
Presets are named configuration bundles that apply predefined settings — thresholds, disabled rules, fail behavior — in a single flag. Instead of manually tuning dozens of options, pick a preset that matches your project's maturity.
<!-- llms:skip-end -->

| Preset   | Description                                                       |
| -------- | ----------------------------------------------------------------- |
| `strict` | Tight thresholds for greenfield projects. Sets `fail_on: warning` |
| `legacy` | Relaxed thresholds for legacy codebases. Disables noisy rules     |
| `ci`     | Explicit CI mode. Sets `fail_on: error`                           |

```bash
# Use a single preset
vendor/bin/qmx check src/ --preset=strict

# Combine multiple presets
vendor/bin/qmx check src/ --preset=strict,ci

# Use a custom preset file
vendor/bin/qmx check src/ --preset=./my-preset.yaml
```

<!-- llms:skip-begin -->
**Priority order:** Presets are applied after `composer.json` discovery but before `qmx.yaml`. Your config file always overrides preset values.

**Multiple presets:** When combining presets, they are merged left-to-right — later presets override earlier ones, except list keys like `disabled_rules` which accumulate. For example, `--preset=legacy,ci` gives you legacy thresholds with CI fail behavior.

!!! warning
    `only_rules` is **not** accumulated across presets — the last preset's `only_rules` completely replaces any earlier one. This is intentional: `only_rules` is a restrictive filter, and union would widen the scope.

**Custom presets:** Any YAML file with the same structure as `qmx.yaml` can be used as a preset. Pass the file path instead of a built-in name.
<!-- llms:skip-end -->

---

<!-- llms:skip-begin -->
## Full Example

```yaml
# Or start with a preset and customize:
# vendor/bin/qmx check src/ --preset=strict

paths:
  - src/

exclude:
  - vendor/
  - tests/Fixtures/

exclude_paths:
  - src/Entity
  - src/DTO

exclude_namespaces:
  - App\Tests

include_generated: false

format: summary
fail_on: error        # default — warnings shown but don't fail the build

cache:
  enabled: true
  dir: .qmx-cache

parallel:
  workers: 4

coupling:
  framework-namespaces:
    - Symfony
    - Doctrine

exclude_health:
  - typing

disabled_rules:
  - code-smell.boolean-argument
  - duplication.code-duplication

rules:
  complexity.cyclomatic:
    exclude_namespaces:
      - App\Tests
    exclude_paths:
      - src/Generated
    callable:
      warning: 15
      error: 25

  size.method-count:
    warning: 25
    error: 40
```

---

## CLI Options Override Config

Command-line options always take precedence over values in the configuration file. For example:

```bash
# Config says paths: [src/], but CLI overrides it
vendor/bin/qmx check lib/

# Add extra exclude paths on top of config
vendor/bin/qmx check src/ --exclude-path='src/Generated/*'
```

This makes it easy to experiment without editing the config file.

---
<!-- llms:skip-end -->

## Configuration Validation

Qualimetrix validates your configuration file and reports clear errors for common mistakes.

### Unknown keys

Any unrecognized key — at the root level or inside a section — produces an error with a suggestion:

```
Invalid configuration in qmx.yaml:
  Unknown key "workes" in "parallel" section. Did you mean "workers"?
```

### Type errors

If a value has the wrong type, you'll get a clear message instead of silent fallback to defaults:

```
Invalid value for "cache.enabled": expected boolean, got string
```

### Unknown rule names

Misspelled rule names in the `rules:` section are rejected:

```
Unknown rule "complexty.cyclomatic" in qmx.yaml. Did you mean "complexity.cyclomatic"?
```

!!! tip
    Set a value to `~` (YAML null) or leave it empty to explicitly use the default — this is always valid.

---

## Configuration Processing

The configuration file format and CLI behavior are stable. Internally,
Qualimetrix resolves defaults, presets, files, Composer discovery, and CLI
options through the Analysis Configuration boundary. This implementation detail
does not change any documented key or precedence rule.

---

## What's Next?

See the [CLI Options](../usage/cli-options.md) reference for the complete list of command-line options.
