# 0009. YAML Loader Normalization Model

**Date:** 2026-05-15
**Status:** Accepted

## Context

`YamlConfigLoader::normalizeKeys()` defaults to camelCasing every key it encounters. Subtree preservation — needed wherever a section's keys are user-defined identifiers rather than schema-known options — is opt-in via two registry methods on `ConfigSchema`: `identifierKeySections()` for whole-section opt-out and `nestedIdentifierKeyPaths()` for path-scoped opt-out within otherwise-normalized sections.

The opt-out model has a structural limit: it protects only **sub-array descendants**. Scalar leaves under a MIXED root receive the normalized key directly (verified at `YamlConfigLoader.php:111-141`, scalar branch at line 137). Every snake_case scalar key under an opt-out path is silently mangled — the loader does not know the key was meant to be preserved verbatim, because preservation lives in the loader's traversal logic rather than in the schema.

The same defect surfaced three times during Phase 2 implementation:

1. `architecture.allow.*` subtree — caught by characterization tests at Step E
2. `allow_cross_instance` as a deep descendant inside the allow subtree — caught by Codex during Step E review
3. `max_expanded_layers` as a scalar leaf at the architecture root — caught during Step F review

The third recurrence — a scalar leaf with no array depth to descend into — proved that the opt-out model could not be patched. The information needed to decide "preserve" vs "normalize" must live with the schema declaration, not with traversal heuristics.

## Decision

### 1. `SectionNormalizationPolicy` enum

Three explicit policies cover every observed case:

- **`NORMALIZE_TO_CAMEL_CASE`** — section sub-keys are camelCased at every level. The current default behavior, made explicit. Appropriate for typed configuration sections (`analysis`, `reporting`, `rules`, etc.) where keys are schema-known options.
- **`PRESERVE_IMMEDIATE_CHILDREN`** — section's level-1 keys are preserved verbatim because they are user-defined identifiers (rule names, formatter names, preset keys); level-2 and deeper resumes `NORMALIZE_TO_CAMEL_CASE`. This is the exact semantic of today's `identifierKeySections()` and is made explicit so the boundary between "user identifier" and "schema options" is not implicit in code.
- **`PRESERVE_SUBTREE`** — section's entire descendant tree is preserved verbatim, **including scalar leaves at every depth**. Closes the leaf-normalization gap that opt-out could not address. Appropriate for sections where user-defined identifiers nest arbitrarily (e.g., `architecture` post-Phase 2 with template layers, captured selectors, and snake_case option keys mixed with user-defined layer names).

`PRESERVE_IMMEDIATE_CHILDREN` is a distinct case rather than a degenerate `PRESERVE_SUBTREE` because it preserves user intent at the identifier boundary while still normalizing the typed option-set inside each identifier. Collapsing it into `PRESERVE_SUBTREE` would silently change behavior for every existing user of `identifierKeySections()`.

### 2. `ConfigSchema::sectionPolicies()` is the single source of truth

```php
public static function sectionPolicies(): array; // array<string, SectionNormalizationPolicy>
```

Exhaustively populated: every root key returned by `allowedRootKeys()` has an explicit entry. The loader reads this map; preservation is no longer a behavior of the loader's traversal, it is a declared property of the section.

### 3. Missing-policy default: `LogicException` fail-fast

Any lookup for an unregistered section throws at boot, before any user-facing analysis runs. New root keys cannot ship without declaring a policy. This is the same fail-fast philosophy as the processor state-machine ([ADR 0008](0008-architecture-processor-service.md)): a missing policy is a wiring bug, not a runtime input problem.

A `PRESERVE_SUBTREE` default for unregistered MIXED roots was rejected — it inverts the historic loader behavior silently for any future typed section that a contributor forgets to register, with the symptom appearing far from the cause.

### 4. Coverage invariant guard test

A unit test asserts `array_keys(allowedRootKeys()) ⊆ array_keys(sectionPolicies())`. A new root key declared without a corresponding policy entry fails at the test level before reaching production. A static guard test (asserting "no MIXED section is accidentally normalized") complements this for the regression layer.

### 5. Migration

The migration is staged to avoid behavior drift during refactoring:

1. **Characterization tests first.** Every existing section's current loader behavior is pinned with explicit assertions BEFORE any production code change. The tests capture today's actual behavior, bugs and all.
2. **Add `sectionPolicies()` populated.** `identifierKeySections()` and `nestedIdentifierKeyPaths()` become thin wrappers reading from the policy map during the transition; the loader still reads them. Behavior unchanged.
3. **Switch loader to consult `sectionPolicies()` directly.** Wrappers retained for one cycle to ease bisection if a regression appears.
4. **Migrate `architecture` to `PRESERVE_SUBTREE`.** The characterization test for `architecture` is updated to reflect new (correct) behavior. A **separate consumer-expectation test** — independent assertion at the `ArchitectureConfigurationFactory` boundary — backs the migration so that the change is verified at two layers, not just at the loader.
5. **Remove `identifierKeySections()` and `nestedIdentifierKeyPaths()`** once all sections route through `sectionPolicies()`.

The two-layer test discipline (loader characterization + consumer expectation) is the explicit response to "this bug recurred three times" — a single-layer test catches one symptom but does not catch the next variant.

### 6. Alternatives considered

- **Static guard test only**, leave the opt-out model. Rejected — catches a regression class but does not eliminate the underlying class of bugs. Retained as a complementary safety net.
- **`PRESERVE_SUBTREE` as default for unregistered MIXED roots.** Rejected — silent-inversion hazard for typed sections.
- **Extend existing opt-out methods to also cover scalar leaves.** Rejected — the bug class is "opt-out invariant violated at depth", not "scalar-leaf path is broken". Extending the opt-out lists keeps the recurring-class shape: every new MIXED root key is still a future bug waiting for the contributor who forgets to add it. Replacing opt-out with explicit per-section policy closes the class.
- **Schema-driven loader** (full JSON-Schema-style traversal). Rejected as overkill — the policy enum captures every observed case with two orders of magnitude less surface area.

## Consequences

- The leaf-normalization bug class is eliminated by construction — there is no path through the loader that can normalize a key the schema marked `PRESERVE_SUBTREE`
- Every root key has explicit normalization policy; reviewers see "what should happen" beside "what the loader does"
- Contributors adding a new root key pay a one-time hygiene cost: declare the policy. Fail-fast catches forgetting; the coverage invariant test catches it earlier
- `architecture` migrates from opt-out (`identifierKeySections + nestedIdentifierKeyPaths`) to `PRESERVE_SUBTREE` — strictly broader preservation than before; bugs caused by opt-out gaps disappear without changing the user-facing YAML schema
- `identifierKeySections()` and `nestedIdentifierKeyPaths()` are deleted in the final migration step — the loader's traversal logic gets simpler, not more complex

## References

- Loader: `src/Configuration/Loader/YamlConfigLoader.php`
- Schema registry: `src/Configuration/ConfigSchema.php`
- Policy enum: `src/Configuration/Loader/SectionNormalizationPolicy.php`
- Characterization tests: `tests/Integration/Configuration/Loader/YamlNormalizationCharacterizationTest.php`
- Coverage invariant: `tests/Integration/Configuration/ConfigSchemaCoverageTest.php`
- Architecture migration: `src/Architecture/Configuration/ArchitectureConfigurationFactory.php` and matching consumer-expectation tests
- Related: [ADR 0008](0008-architecture-processor-service.md) (same fail-fast philosophy applied to a different invariant)
