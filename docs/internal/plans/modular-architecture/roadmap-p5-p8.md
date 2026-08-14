# P5–P8 roadmap, execution dependencies, and regression record

> **Status:** P5 and P6 are complete after corrective publication and independent
> review GO. P6 closes at 754 declarations / 752 files, 37 owners / zero seams /
> 51 exact grants / 8 owner pairs, 223 qmx allows, and 509 PHPUnit classes /
> 7,251 semantic IDs. `native-codex-01` implemented the minimal
> `RuleDefinitionInterface` contract and passed independent address-check;
> `native-codex-02` closed all confirmed current-document findings; and
> `native-codex-03` verified the exact anchored lock-file behavior. The final
> host `composer check` exited 0 (7,251 tests / 23,654 assertions / one skip,
> 17 Python tests, PHPStan over 1,280 files, active/stale dogfood 0/0). P7 is
> complete after its implementation, final aggregate validation, and
> independent review returned GO. It lands at 762 declarations / 760 files, 37
> owners / zero seams / 50 exact grants / 7 owner pairs, 224 qmx allows, and
> 7,254 generated PHPUnit cases. P8 remains pending as the next phase.
> **Prerequisites:** [Plan overview](../modular-architecture.md), [decisions and target](decisions-and-target.md), and the completed phase records linked there.
### P5 — Extract Analysis\Evidence\ComputedMetrics and remove static state

Detailed execution is specified in [P5 — ComputedMetrics and Health](p5-computed-metrics.md). Its mandatory P5-0 design gate resolves the Run invocation boundary before production moves begin.

Files: `Core/ComputedMetric/**`, computed/health config resolvers, metric/rule paths, health reporting paths, runtime configurator, its exact DI wiring, docs/tests/qmx.

- Co-locate definition parsing, validation, dependency ordering, evaluation, options and rules.
- Apply the P5 manifest rows and regenerate qmx/inventories. The `ComputedMetricsConfigResolver` seam was already removed in P3 after deleting dead namespace detection; remove the remaining `HealthReasonBuilder` and `MetricHintProvider` seams only when returning each declaration to its true owner leaves the projected graph acyclic.
- Replace `ComputedMetricDefinitionHolder` with an instance-owned run definition catalog.
- Move `Configuration\HealthFormulaExcluder` to `Analysis\Evidence\ComputedMetrics\Health\HealthFormulaExcluder`; relocate `Configuration\ComputedMetrics\Contract\HealthFormulaExclusionInterface` to `Analysis\Evidence\ComputedMetrics\Contract\HealthFormulaExclusionInterface`. Health consumes the transitional `excludeHealth` field through a distinct Health-owned `HealthConfiguration` data contract; that parsed configuration is not an alias for the formula-exclusion operation port.
- Dissolve P3's `TransitionalMetricEnricher` and the remaining `TransitionalEnrichmentResult` shell after P4 has removed cycles: Measurement owns aggregation/global reaggregation, ComputedMetrics owns computed evaluation, and Run keeps invocation order only.
- Do not inherit a P3 MetricDerivation participant. P5 first proves its graph/definition ordering and then may introduce a narrow consumer-owned seam; the module parses its own configuration node.
- Keep Health as a named subdomain for this package; split only if inventory shows an independent lifecycle/public consumer set.
- Move health computation identified by P0 out of Reporting; pure health rendering remains in Reporting and consumes a narrow ComputedMetrics/Health read contract.

DoD: `Analysis\Configuration` contains no computed-feature knowledge; `Analysis\Run` contains only the exact ComputedMetrics evaluation phase contract and no formula configuration/state; `RuntimeConfigurator` invokes only the exact configuration contract. Two different configurations in one process cannot leak definitions; formula cycles/errors, exclude-health and all health formats retain direct regressions.

### P6 — Separate Finding and Policy capabilities; place orchestration and projections honestly

Detailed execution is specified in [P6 — Finding, Inline Policy, Baseline
Policy, Prioritization, and finding projection](p6-finding-policy.md). Its P6-0
gate must reconcile the authoritative current closure of 156 production
declarations and 118 test artifacts (86 discovered PHPUnit classes / 939 IDs).
The older 60-class / 628-ID execution slice is not a complete P6 inventory and
must not be used as package or final-count authority.

P3 leaves four exact contract-surface leak rows for this package rather than publishing false Configuration/RuleExecution contracts: `RuleOptionsRegistry` constructor/getter exposure of `RuleNamespaceExclusionProvider` and `RulePathExclusionProvider`, plus `RuleExecutorInterface` exposure of `RuleExclusionStats` and `list<RuleInterface>`. P6 replaces provider getters with Finding-owned namespace/channel/path query-mutation operations, moves providers internal, promotes `Finding\Contract\RuleExclusionStats` for Console, and returns Finding-owned rule metadata instead of concrete rule instances.

Files: `Baseline/**`, `Core/Suppression/**`, `Core/Violation/Filter/**`, suppression collection code, `Infrastructure/Console/ViolationFilter*`, git scope adapter, reporting seams, docs/tests/qmx.

- Use the P0 context map for source annotations, rule/config exclusion, baseline ceiling, Git projection and presentation; name producer, semantic owner, consumer and order for every stage.
- Apply the P6 manifest rows, regenerate qmx/inventories, and remove the `DeclarationBindings`, `SourceControls`, `RuleExecutor`, `RuleValidatorMapFactory`, `SuppressionFilter` and `RuleMatcher` singleton seams only after returning each declaration to its true owner leaves the projected graph acyclic. `Location` may still move or change with Finding, but P2 already removed its enforcement seam after inverting the DependencyModel location dependency.
- Move neutral violation/rule/channel contracts to `Analysis\Finding`, source annotation ownership to `Analysis\Policy\Inline`, and retain `Analysis\Policy\Baseline` as its own capability.
- Move the eight P3-deferred rule option/exclusion declarations from their exact current FQCNs and replace `TransitionalResolvedConfiguration::$ruleOptions`, the provider rule-options methods and the holder slot with Finding-owned contracts. Close the renamed `DeclarationBindings` seam here.
- Put only cross-capability invocation order in `Analysis\Run`; do not create a `FindingEvaluation` module, shared policy-state holder, or use P3's file-set composite for policy work.
- Move Git-scoped and other output-only projections to Reporting. Keep the Git client as an Infrastructure adapter behind a Reporting-owned port.
- Move framework-free orchestration out of Console; Console only parses options and renders diagnostics.
- Keep Baseline as a peer policy capability unless the P0 ownership map disproves its independent lifecycle; do not force all policy into one implementation module.

DoD: invocation/projection order has one authoritative contract test; each result/state type has one owner; Run orchestration holds no feature state; Reporting projection has no Git concrete import; Baseline remains fail-safe and scope semantics/golden output are unchanged.

### P7 — Classify and migrate thin evidence capabilities in vertical batches

Files per batch are disjoint by proposed check owner: implementation + rule + config/defaults + tests + docs. Shared DI/qmx files are a final serial integration package, not concurrently edited.

1. Refresh P0's exhaustive ownership table and add a 6–12 month focused co-change matrix excluding mass commits.
2. Keep the reviewed dispositions unless new evidence triggers a plan/ADR amendment: Halstead moves with Maintainability, WMC with Complexity, and legacy Structure declarations split among six owners exactly as enumerated in the generated inventory: Cohesion 10, Design 9, CodeSmell 5, Coupling 3, Size 3, and Complexity 2.
3. For each accepted capability, update its P7 manifest rows and named consumers, regenerate qmx/inventories, then move metric collectors and rules together under `Analysis\Evidence\{Capability}`. Measurement foundation and repository work already closed in P3 are inputs, not P7 leftovers. Give a capability `Contract` only if another module actually consumes it.
4. Move the transitional `frameworkNamespaces` field to the Coupling-owned configuration contract; P7 may not extend the mixed P3 DTO.
5. Delete empty role buckets and shrink Metrics/Rules foundation; do not recreate identical internal role folders by template.

DoD per batch: one owner for every moved class; no cross-check internal import; external imports only through actual contracts; all rule names/options/output stay stable unless a documented breaking change is intentional; README gives an agent a bounded reading set.

### P8 — Remove grants and ratchet context locality

Files: `qmx.yaml`, architecture fixtures, module READMEs, scripts/CI, ADR completion note.

- Remove all exact temporary grants from the manifest and their derived coarse qmx edges.
- Remove every temporary exact contract-consumer entry. Every new surviving contract consumer is permanent only with an observed exact source/target import, an exact `source_fqcn` and `closes_in: null`; legacy owner-wide permanent entries are not widened or copied.
- Apply the P8 manifest rows, remove every now-unused grant/seam, and regenerate qmx/inventories until `--check` is clean.
- Consolidate the duplicated `LoggerFactory` ownership/test locations in P8 according to the generated rows; do not leave parallel legacy and target test owners.
- Treat the 28 generated orphan candidates as candidates only: prove absence of direct, dynamic, fixture-path and process consumers before deleting each row. Otherwise move it to its assigned owner and record the retained disposition.
- Add a report/test listing public imports and fan-in per module; treat growth as review evidence, not an automatic design score.
- Add a context-locality checklist to module READMEs: owned code/tests/docs, public dependencies, adapters, change recipes.
- Add a test-topology check: every module-owned test/support/fixture path maps to its subject, every permanent `System` scenario and `TestSupport` child has a recorded subject owner/justification, no file lives directly in the `TestSupport` taxonomy, and production code never imports test namespaces.
- Run complete validation and self-analysis; intentionally review any baseline change rather than regenerating it mechanically.

DoD: no temporary contract consumer, temporary internal grant or singleton seam remains; no mutual allow, taxonomy allow target, wildcard sibling access, uncovered production namespace, feature static holder, feature payload in universal contexts, unowned legacy test path or silently undiscovered moved test; LoggerFactory has one coherent owner/test location; every one of the 28 orphan candidates has recorded consumer proof and a move-or-delete disposition; `composer check`, selfcheck, docs build and direct module regressions pass.

## Package dependencies and execution

```text
[complete] P0-A -> P0-B -> P0-C -> P0-D -> P0 completion gate
[complete] P1-A -> P1-B -> {P1-C || P1-D} -> P1-E -> P1 completion gate -> [complete] P2-A -> P2-B -> P2-D1 -> P2-C -> P2-D2 -> P2-E -> P2 completion gate -> [complete; independent design review GO] P3-0 -> [complete] P3-A -> [complete] P3-B -> [complete] P3-R1 -> [complete] P3-R2 -> independent remediation review -> [complete] P3-R3 -> independent P3-R3 review -> [checkpoint complete] P3-C -> final-review findings -> [complete] {P3-R4-A || P3-R4-B} -> [complete] P3-R4-C -> P3-R4-D -> P3-R4-E -> aggregate validation -> [complete; GO] final independent re-review -> [complete; two review rounds and aggregate validation green] P4 -> [complete; three findings fixed, aggregate green, independent review GO] P5 -> [complete; native-codex-01/02/03 closed, address-check GO, final host aggregate green] P6
-> [complete; final aggregate green, independent review GO] P7 -> [pending] P8
```

P0 completed discovery, decision and enforcement; it does not authorise the unchanged remainder automatically. P0-A atomically established authority, generated qmx enforcement, reproducible evidence and freshness checks, including ownership of the qmx topology header immediately adjacent to its generated region. P0-B closed decision/general documentation, P0-C reconciled the baseline, and P0-D completed final review and shared-document dispositions. P1–P7 are complete. P4 published Architecture Policy and CircularDependency, P5 published ComputedMetrics and Health, P6 published Finding, Inline/Baseline policy, Prioritization, and FindingProjection, and P7 published the eight evidence capabilities. The native-codex closure is complete: `native-codex-01` passed independent address-check after implementation and fixes, `native-codex-02` closed confirmed documentation findings, and `native-codex-03` verified the ignored anchored lock behavior. The final host aggregate is green.

P7 is complete after its final aggregate and independent review gates returned
GO; P8 alone remains pending. Generated rows bearing an exact P1–P8 closure remain migration inputs; the completed phase records reclassify them by subject and name every integration writer. Documentation rows marked `P0-D`, `permanent`, or `shared` do not grant later package ownership and still change when the landed current state would otherwise make them false. The only P3 Run-owned new port is FileSetInspection; DependencyTraversal is a DependencyModel-owned promise to its named consumers. No graph-preparation, metric-derivation or generic lifecycle port is approved.

Packages P1–P6 then land sequentially because they share Run, DI, configuration and topology seams. P1's temporary `MetricEnricher -> DuplicationInspectionInterface` relation created the explicit lifecycle dependency on P3; P3-A replaced it with FileSetInspection and deleted the temporary contract. P3-A migrated the original production/import/test/governance set and P3-B completed its documentation set. P3-R1 atomically owns the exact remediation source/tests plus finite manifest/generator/governance-source delta; P3-R2 owns only four named READMEs. P3-C owns only generated publication, qmx, baseline, PHPUnit discovery and the final status marker. P4 must prove any Architecture/CircularDependency boundary from its own inputs and closes the two physically renamed Configuration seams plus the transitional lifecycle/cycle result. P5 dissolves transitional enrichment and Health/computed fields; P6 closes the eight Finding deferrals and rule-option transport; P7 closes Coupling's framework namespace field. P7 batches may be delegated in parallel only after an execution plan enumerates exact non-overlapping production/tests/docs files; shared DI, `qmx.yaml`, `phpunit.xml.dist`, Composer dev-autoload/classmaps, root docs and generated inventories form a named serial integration package. No package described with `**` is eligible for parallel execution as-is.

Each package is behavioural-preservation first: move + contract + architecture tests in one package, no compatibility shim. Validate focused tests after every package, then `composer check`; run standard/extended review according to changed contracts and inspect seams explicitly.

## Edge cases and regression matrix

- two analyses with different config in one process;
- a disabled expensive capability does zero preparation/allocation;
- empty project and incomplete analysis result;
- parallel collection serialization contains only neutral contracts, never module services/state;
- config warnings emitted after logger setup without a global feature holder;
- deterministic extension order and duplicate-id failure;
- CLI debug/export adapters consume only public contracts;
- baseline + source suppression + CLI exclusion + git projection preserve exact order;
- Git projection changes only the reported view and never the accepted-state/policy result;
- taxonomy directories contain no production types and cannot be dependency targets;
- a listed contract consumer without an observed exact target import fails; a temporary consumer cannot omit/mismatch `source_fqcn`, cannot disagree with its semantic `owner`, and cannot omit or outlive `closes_in`;
- a fourth declaration of an otherwise authorised temporary consumer owner cannot reuse its coarse qmx allow;
- an unlisted exact internal import fails even when one of the 16 coarse semantic-owner edges would otherwise permit it;
- every moved test remains present in the PHPUnit discovery manifest, with no duplicate old/new class;
- module-specific fixtures and support code move with their owner while neutral TestSupport consumers remain green;
- layer policy and circular-dependency evidence can be enabled, disabled and reset independently;
- dynamic rule discovery and constructor dependencies still work after moves;
- cache payload versioning is bumped or proven compatible for moved class names;
- public contract break produces CHANGELOG migration entry;
- taxonomy container cannot become qmx allow target.

## Explicit non-goals

- no generic marketplace/plugin API for third-party checks;
- no `Api/`/`Contract/` folder for private leaf code;
- no `FindingEvaluation` role bucket or policy/projection state shared through the Run kernel;
- no placement of circular-dependency detection under Architecture merely because its rule currently lives there;
- no numeric context-window hard gate;
- no mass migration based only on names or current directory categories;
- no preservation shim for obsolete internal namespaces.
