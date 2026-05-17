# 0014. Retire deptrac, Own Architecture Enforcement via qmx.yaml

**Date:** 2026-05-17
**Status:** Accepted
**Related:** [0005 — Architecture Rules](0005-architecture-rules.md),
[0006 — Architecture Rules Declaration Order](0006-architecture-rules-declaration-order.md),
[0007 — Architecture Rules Phase 2 Design](0007-architecture-rules-phase-2-design.md),
[0010 — Architecture as Vertical Slice](0010-architecture-vertical-slice.md)

## Context

Qualimetrix ships an `architecture.layer-violation` rule (Phase 1 in
v0.17, Phase 2 in v0.18) that markets itself as a deptrac replacement
for users. Until now we were dogfooding it only partially: the project
declared an `architecture:` block in `qmx.yaml` with ten coarse layers
(`core`, `configuration`, `rules`, `reporting`, `baseline`, a flat
`analysis`, a flat `infrastructure`, plus a `metrics-{Category}`
template) and ran `deptrac` separately in `composer check` with a
27-layer topology that split `Analysis.*` and `Infrastructure.*` into
ten sub-layers each plus a dedicated `Architecture` layer for the
vertical slice landed in v0.18.

The discrepancy meant deptrac caught cross-sub-layer edges we did not
(e.g. `Analysis.Discovery → Analysis.Pipeline`) while we caught
edge-kind restrictions deptrac could not (e.g. `infra-di` may *use* a
collector but must not *extend* it, via the `relations:` filter). Two
overlapping tools enforcing partially overlapping rules is a
maintenance smell; one of them should win.

Marketing-wise the gap is worse than the technical one. We tell
prospective users "drop deptrac, qmx covers it" while keeping deptrac
in our own `composer.lock`. The honest move is to make the claim true
for us first.

## Decision

Drop the `deptrac/deptrac` dev-dependency, delete `deptrac.yaml`,
remove the `composer check` step that invoked it, and re-declare the
full 27-layer topology directly in `qmx.yaml`'s `architecture:` block.
`composer check` becomes `cs-check + test + phpstan + selfcheck` —
`selfcheck` (`bin/qmx check src/`) is the sole architecture gate.

The dogfooded `qmx.yaml` topology:

- one `core` layer
- one `configuration` cross-cutting layer (allows `architecture` since
  `ConfigurationPipeline` consumes `ArchitectureConfigurationFactory`)
- one `architecture` vertical-slice layer (allows `core`, `rules`,
  `configuration`, `analysis-lifecycle` only)
- a `metrics-{Category}` template (per-category isolation via Phase 2
  capture expansion)
- four leaf domain layers (`rules`, `reporting`, `baseline`, plus
  `metrics-*`), each allowing only `core`
- ten `analysis-*` sub-layers (`exception`, `discovery`, `namespace`,
  `repository`, `duplication`, `aggregator`, `ruleexecution`,
  `collection`, `lifecycle`, `pipeline`) with the same allow-list
  structure deptrac enforced
- ten `infra-*` sub-layers with the same allow-list deptrac enforced,
  plus a `relations:` filter on `infra-di → metrics-*` that restricts
  the DI configurator to `type_reference`, `new`, `static_access`,
  `class_const_fetch`, `attribute`, and `runtime_check` — extends /
  implements / trait_use remain forbidden

## Pre-removal verification

We pinned the new topology against the old before deleting deptrac:

1. **Shadow run.** Both tools were executed against `src/` with the
   declarative configs side-by-side. Both reported `0` violations on
   clean code (parity in the positive direction).
2. **Manual injection (five scenarios).** A small typed-property
   injection was committed to each pilot file in turn, both tools were
   run, then the change reverted. Results:

| #   | Edge                                                | qmx | deptrac | Outcome                                                                           |
| --- | --------------------------------------------------- | --- | ------- | --------------------------------------------------------------------------------- |
| 1   | `core → analysis-pipeline`                          | ✓   | ✓       | parity                                                                            |
| 2   | `analysis-discovery → analysis-pipeline`            | ✓   | ✓       | **gained by qmx** (was masked by the flat `analysis` layer)                       |
| 3   | `metrics-Coupling → metrics-Complexity`             | ✓   | ✗       | qmx **stricter** (template-layer expansion; deptrac sees flat Metrics)            |
| 4   | `infra-di extends a collector` (relations filter)   | ✓   | ✗       | qmx **stricter** (`relations:` blocks `extends`; deptrac has no edge-kind filter) |
| 5   | `architecture → analysis-pipeline` (slice boundary) | ✓   | ✓       | parity                                                                            |

   Two scenarios are net gains; three are at parity; none regressed.
3. **Permanent fixture test.** Added
   `tests/Integration/Architecture/DogfoodingTopologyTest.php`, which
   loads the real `qmx.yaml` through the production
   `ConfigurationPipeline` and asserts:
   - all 27 expected layer names are declared (sub-layer enforcement
     stays explicit; future edits that collapse the topology back to a
     flat `analysis`/`infrastructure` parent layer will fail this
     assertion);
   - no flat `analysis` / `infrastructure` / `metrics` layer is
     reintroduced (negative regression guard);
   - `analysis-discovery → analysis-pipeline` is forbidden;
   - `infra-di → metrics-Complexity` is allowed for `TypeHint` but
     forbidden for `Extends` (the `relations:` filter contract).

## Consequences

- `composer check` is shorter (no deptrac step), no deprecation
  warnings from `deptrac/deptrac`'s vendored Symfony 6 internals.
- The marketing claim "replaces deptrac" is now honest for the project
  itself.
- Architectural enforcement gains two new capabilities our previous
  setup did not have: per-category metric-layer isolation and edge-kind
  filtering (`relations:`) on `infra-di → metrics-*`.
- `MutualAllowDetector` emits a deferred warning for the
  `configuration ↔ architecture` pair (Configuration consumes
  `ArchitectureConfigurationFactory`; Architecture consumes
  `ConfigLoadException` / `DeferredWarning`). The warning is honest —
  it surfaces the design choice rather than masking it — and is
  acceptable noise. Three functional tests
  (`CheckCommandTest::itSupportsJsonFormat` and siblings) had to be
  hardened to parse JSON output past a leading warning line; that is
  a one-time fixture-level fix in
  `firstParseableJsonFragment()`.
- The retired `deptrac.yaml` documentation value (the explicit
  sub-layer allow-list) is preserved verbatim in the new
  `architecture:` block, with comments explaining the structure.

### What this is NOT

- Not a claim that `architecture.layer-violation` is a 1:1 superset of
  deptrac in every dimension. It does not implement deptrac's emitter
  plugins, dependency type whitelisting per *source*, or the layered
  formatter ecosystem. It implements what this project needed.
- Not a forced migration recommendation for users. Users with a
  working deptrac setup are not asked to switch. The dogfooding switch
  is about being honest with our own claims.

## References

- Pilot retirement commit batch on `main` (2026-05-17)
- `qmx.yaml` `architecture:` block at the time of writing — the
  authoritative source of the dogfooded topology
- `tests/Integration/Architecture/DogfoodingTopologyTest.php` — the
  invariant guard against future regressions
- [Phase 2 of the Architecture vertical slice rollout](0010-architecture-vertical-slice.md)
  is the prerequisite that made this retirement possible (template
  layers, `relations:` filter, vertical-slice boundary enforcement)
