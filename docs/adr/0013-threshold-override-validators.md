# 0013. Per-Options Threshold Override Validators

**Date:** 2026-05-16
**Status:** Accepted

## Context

`@qmx-threshold` annotations have been supported since the early
releases of the analyser. Originally each rule's
`ThresholdAwareOptionsInterface::withOverride()` was responsible for
applying the override at rule-analyze time, while
`ThresholdOverrideExtractor` validated the (warning, error) pair at
parse-time using one universal invariant: `warning <= error`, both
non-negative. That invariant matched the dominant pattern of
"exceeding-threshold" rules (CCN, method count, CBO, etc.) and worked
for them.

It silently broke for every other shape of rule:

- **Inverted-threshold rules** (Maintainability Index since v0.x,
  type-coverage starting v0.18) treat *higher* metric values as better.
  The natural override is `warning=40 error=20` — exactly what the
  parser rejected. Maintainability shipped with this bug **latent for
  multiple releases** because the integration test exercised
  `Options::withOverride()` directly and never went through the parser.
- **Independent-axis rules** (Data Class) map warning and error to two
  unrelated metrics (WOC threshold high, WMC threshold low). The `W ≤ E`
  invariant has no semantic basis here, and the user's natural override
  values were rejected.
- **Warning-only rules** (God Class) accept only the warning
  threshold; the error half is silently discarded by `withOverride()`.
  Users supplying `error=N` had no feedback that the value was ignored.

A retroactive standard review on commit `2351dbf` (shipped in v0.18.0)
surfaced all four cases together with one structural cause: a global
parser-level invariant cannot encode rule-specific semantics.

## Decision

Each `ThresholdAwareOptionsInterface` declares its own validation
strategy via a new **static** accessor:

```php
interface ThresholdAwareOptionsInterface
{
    public function withOverride(int|float|null $warning, int|float|null $error): static;

    public static function getOverrideValidator(): OverrideValidatorInterface;
}

interface OverrideValidatorInterface
{
    public function validate(
        int|float|null $warning,
        int|float|null $error,
        bool $errorWasExplicit,
    ): ?OverrideValidationFailure;
}
```

Four reusable validators ship in `src/Core/Rule/Override/`:

| Validator                   | Used by                       | Constraint                                                    |
| --------------------------- | ----------------------------- | ------------------------------------------------------------- |
| `StandardOverrideValidator` | ~23 default rules (via trait) | W ≤ E + non-negative                                          |
| `InvertedOverrideValidator` | Maintainability, TypeCoverage | W ≥ E + non-negative                                          |
| `IndependentAxisValidator`  | DataClass                     | both non-negative; no W ↔ E relation                          |
| `WarningOnlyValidator`      | GodClass                      | warning non-negative; error must be null OR shorthand-implied |

`StandardOverrideValidatorTrait` provides the default `getOverrideValidator()`
for the majority of rules — single-line `use` in each Options class —
so the migration cost is bounded.

`ThresholdOverrideExtractor` delegates to the per-rule validator instead
of applying global checks. The map (rule name → validator) is built
**once per process**: main-side by `ThresholdValidatorMapCompilerPass`
after `RuleRegistryCompilerPass` runs, worker-side by `WorkerBootstrap`
calling `RuleValidatorMapFactory::build($ruleClasses)` from the
forwarded class list. The static accessor avoids any
`fromArray([])` boot dance — the validator is class-level metadata, not
instance state.

`OverrideValidationFailure` carries a stable `code` (e.g.
`warning_exceeds_error`, `error_not_supported`) alongside the human
message. The code propagates into `ThresholdDiagnostic` so future
SARIF/JSON output can cross-reference rejections.

### Why on Options rather than on Rule

Options own the threshold semantics — they decide which constructor
properties the warning/error pair maps to. The Rule consumes the
resulting Options. Putting validation on the Rule would force the
Rule to inspect its own Options, inverting the dependency. The static
accessor on Options is the natural location.

### Why a strategy hierarchy rather than an enum

An enum (`ThresholdOrientation::Standard | Inverted | …`) would be a
slightly smaller surface — four cases match four patterns. The strategy
pattern was chosen because Qualimetrix supports extension rules, and
extension authors must be able to ship a fifth validator without
modifying core. A strategy class on Options is the open-extension
form; an enum would force a `switch` in the extractor that core would
have to maintain.

### Why a separate `validateOverride()` method on Options was rejected

Pushing `validateOverride()` straight onto the Options interface
(skipping `OverrideValidatorInterface` entirely) avoids one indirection
but loses the four shared validators — 23 standard rules would each
re-implement the same `W ≤ E + non-negative` check, or pull it in via
trait that essentially reinvents the strategy pattern with less
encapsulation.

### Wildcard handling

Wildcard rule patterns (`@qmx-threshold * warning=…`) skip
validator-level checks. The matched-rule set is not known at parse
time, and applying every matched rule's validator would produce
confusing many-against-one diagnostics. The existing post-analysis
`annotation.unsupported-threshold` diagnostic continues to catch
patterns that match no real rule. A future iteration may expand
wildcards to the matched set and validate each — out of scope for
v0.19.

### LongParameterListOptions

`LongParameterListOptions` has two threshold pairs:
`(warning, error)` and `(voWarning, voError)`. `withOverride()` touches
only the primary pair, so `StandardOverrideValidator` is the right
validator for the annotated path; the secondary VO pair remains
config-only. Users wanting to tune VO thresholds use YAML, not the
annotation. This is a documented limitation of the annotation surface,
not a validator-strategy concern.

## Consequences

**Breaking change for extension authors:** any custom Options class
implementing `ThresholdAwareOptionsInterface` must now implement
`getOverrideValidator()` (or `use StandardOverrideValidatorTrait`).
The breakage is mechanical and the trait keeps the migration to a
single line for the common case.

**Anti-regression structure:**

- `ThresholdValidatorAssignmentTest` (unit, reflection): asserts every
  ThresholdAware Options returns a valid validator and that any class
  whose `fromArray([])` defaults pair W > E uses a non-Standard
  validator. Catches the next latent-inverted-rule bug at CI time.
- `ThresholdValidatorWiringTest` (integration): asserts the production
  factory and compiler pass produce the same map the extractor sees.
- `ThresholdAnnotationParserPathTest` (integration, end-to-end):
  drives each validator strategy through the real parser path
  (docblock text → extractor → diagnostic / override). The previous
  `Options::withOverride()`-only coverage shipped the v0.18 bug; this
  suite is structured so the same omission cannot recur.

**Validator instance discipline:** validators MUST be stateless and
safe to share across `amphp/parallel` worker processes — each worker
hydrates its own static state, and the singleton-per-class pattern
relies on stateless behaviour. Adding state to a validator is an
explicit thread-safety hazard documented in the interface.

**Wildcard validation is intentionally permissive** for v0.19. If a
future use case justifies stricter handling (annotation that matches
disparate validator strategies surfacing as ambiguous), this ADR
should be revisited.
