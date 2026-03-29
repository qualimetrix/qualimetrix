# T04: VO Constructor Exemption for Long Parameter List

**Proposal:** #1 | **Priority:** Batch 2 (false positive reduction) | **Effort:** ~2h | **Dependencies:** none

## Motivation

Readonly DTOs/VOs with promoted properties are structurally different from service constructors.
A `readonly class` with 8 promoted properties and no body logic is valid design — it's a typed data
container, not a sign of poor decomposition. ~30+ false positives across Core, Reporting, Analysis.

## Design

**Decision:** Raise threshold for VO constructors, not skip entirely (VO with 15 params still needs splitting).

**VO detection heuristic:**
1. Class has `readonly` modifier (PHP 8.2+ — makes all properties readonly automatically)
2. All constructor parameters are promoted properties (have visibility modifier)
   - No need to check `readonly` on individual params — the class-level `readonly` covers it
3. Constructor body is empty or absent (no statements other than property promotion)

When all three conditions are met, use separate VO thresholds.

**Note:** This applies only to `__construct`, not to other methods.

### New options in `LongParameterListOptions`

```
code-smell.long-parameter-list:
  warning: 4          # regular methods/functions
  error: 6
  vo-warning: 8       # readonly VO constructors
  vo-error: 12
```

### Metric enrichment

The VO detection must happen at collection time, not rule time (rules don't traverse AST).
Add a boolean metric `CODE_SMELL_IS_VO_CONSTRUCTOR` in the parameter count collector.

## Files to modify

| File                                                             | Change                                      |
| ---------------------------------------------------------------- | ------------------------------------------- |
| `src/Metrics/CodeSmell/ParameterCountCollector.php` (or similar) | Detect VO constructor, emit boolean metric  |
| `src/Rules/CodeSmell/LongParameterListRule.php`                  | Use VO thresholds when VO metric is true    |
| `src/Rules/CodeSmell/LongParameterListOptions.php`               | Add `voWarning`, `voError` fields           |
| `src/Core/Metric/MetricName.php`                                 | Add `CODE_SMELL_IS_VO_CONSTRUCTOR` constant |
| Tests for LongParameterListRule                                  | Add VO test cases                           |
| `src/Rules/README.md`                                            | Document VO exemption                       |
| Website: rules documentation (EN + RU)                           | Document VO behavior                        |

## Acceptance criteria

- [ ] `readonly class Foo { public function __construct(public string $a, ...) {} }` with 8 params → no warning (vo-warning=8, value < threshold)
- [ ] Same class with 13 params → error (default vo-error=12)
- [ ] Non-readonly class with 8 params → still warning at threshold 4
- [ ] Class with `readonly` but constructor has body logic → uses regular thresholds
- [ ] Class with `readonly` but not all params promoted → uses regular thresholds
- [ ] VO thresholds configurable via YAML config
- [ ] PHPStan passes, tests pass

## Edge cases

- Readonly class with no constructor → no violation (no params)
- Readonly class with empty promoted constructor + trait use → still VO (trait is irrelevant)
- Constructor with `parent::__construct()` call in body → NOT VO (has body logic)
- Constructor with assertion in body (e.g., `Assert::notEmpty()`) → NOT VO (has body logic)
- Mixed promoted and non-promoted params → NOT VO (not all promoted)
- `final readonly class` → still eligible for VO detection
- Abstract readonly class → still eligible for VO detection
- Non-readonly class with all individually `readonly` promoted params → NOT VO (class-level `readonly` is the trigger)
- Constructor with default parameter values → still VO (defaults don't imply logic)
