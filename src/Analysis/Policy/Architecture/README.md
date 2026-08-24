# Architecture policy

`Analysis\\Policy\\Architecture` owns declared-layer policy: YAML contribution
parsing, layer membership preparation, diagnostics, and
`architecture.layer-violation`. It is a leaf capability, not the old combined
Architecture vertical slice; circular-dependency evidence is owned separately
by [`Analysis\\Evidence\\CircularDependency`](../../Evidence/CircularDependency/README.md).

## Public contracts

External owners use only the contracts in `Contract/`:

- `ArchitecturePolicyConfiguratorInterface` configures the policy from the
  immutable `ConfigurationDocument` and returns configuration
  warnings after the Console logger is available.
- `LayerPolicyPreparationInterface` is the Run-owned sequential preparation
  boundary. Disabling the rule clears state and does no class-universe or
  template-expansion work. It also carries the literal names of the diagnostic
  channels the producer emits under rule names other than its own — one from
  the rule, five from its configuration validator — and the project-scoped
  subset of them.
- `LayerAssignmentInspectorInterface`, `LayerAssignment`, and
  `LayerAssignmentMatch` form the Console debug projection.
- `ArchitectureConfigurationException` and `ArchitecturePreparationException`
  are the Console-facing failures.

The concrete `ArchitecturePolicy` owns configured and prepared state for one
run. It resets before a new configuration and before disabled preparation; no
policy state enters the worker or cache payload.

## Layout

```text
Architecture/
├── Contract/                  # exact external promises and debug values
├── Configuration/              # contributed `architecture:` document parser
│   └── Allow/                  # allow selectors and binding values
├── Layer/                      # membership and registry primitives
│   └── Expansion/              # observed-template expansion
├── LayerViolation/             # shared evidence walk, two rules, declaration validator
└── ArchitecturePolicy.php      # instance-owned configuration/preparation
```

`Configuration/`, `Layer/`, `Layer/Expansion/`, `LayerViolation/`, and the
policy coordinator are internal zones of one leaf. The manifest-backed
Architecture topology test enforces their exact DAG; sibling internals are not
a public API. The generated qmx projection enforces the leaf owner boundary.

## Configuration and lifecycle

`ConfigurationDocument` preserves ordered source contributions.
`ArchitecturePolicy` alone merges its `architecture` contributions and turns
them into typed policy configuration. The central Configuration merger has no
Architecture-specific branch or deferred-warning transport.

Run prepares the policy after graph construction. Neither verdict traverses the
AST or constructs lifecycle state.

`LayerViolation/` is four subjects, not one. `LayerEvidenceCollector` walks the
analysed classes and the dependency graph **once per run** — memoised weakly by
the run's `AnalysisContext`, so nothing survives into the next run — and returns
one `LayerEvidence`: the edges the allow-list rejects, per-layer tallies of what
each layer was ASSIGNED and what it MATCHED at all, the shadow evidence, the
classes outside every layer, and the coverage state. It short-circuits to `null`
when the producer is disabled or no layers are declared, so "report nothing" has
one answer rather than two. It answers to both consumer gates — the rule's
`enabled` and `UnassignedClassOptions::$mode` — and materialises the
outside-every-layer set when either of them, or the coverage mode, has a use
for it.

Two rules report on the **code**, one finding each, over that one walk.
`LayerViolationRule` emits `architecture.layer-violation` per forbidden edge.
`UnassignedClassRule` emits the magnitude channel
`architecture.unassigned-class`, gated by its own single `mode` option and built
by `UnassignedClassSummary`; both are ordinary debt a baseline may accept. Being
a producer of its own is why `LayerPolicyPreparationInterface::PRODUCER_RULE_NAMES`
names two rules: the run prepares the policy when either is selected, and asking
about one of two left `--only-rule=architecture.unassigned-class` reaching an
unprepared collector.

`LayerDeclarationValidator` is the verdict on the **declaration** and is a
`ConfigurationValidatorInterface`, not a rule — which is the whole statement
that its five channels are configuration errors. `DeclaredLayerReachability`
builds all five: `architecture.coverage`, `architecture.unreachable-layer`,
`architecture.pending-layer-matched`, `architecture.potential-shadow` and
`architecture.empty-template`. The validator declares `architecture.layer-violation`
as its producer, so all five are registered, addressed, excluded, described and
switched off exactly as they were while the rule declared them, and it runs in
the rule's slot so their position in an unsorted report is unchanged.
`DiagnosticSampleList` formats the bounded FQN samples both
`architecture.coverage` and `architecture.unassigned-class` print, and is the
one piece of code shared across the code/declaration split — a narrow
formatting utility with no policy semantics of its own.

A layer declared `pending: true` — reserved for code not
written yet — is exempt from `architecture.unreachable-layer` and is reported
by `architecture.pending-layer-matched` once its criteria match, which the
matched tally sees even when a broader layer wins every assignment.
The Console debug command invokes the inspector contract over the same collected
graph and class universe.

## Definition of Done

- Keep public consumers on the declared contracts; do not import an internal
  Architecture zone from another owner.
- Preserve independent reset semantics across sequential runs and zero work
  when layer policy is disabled.
- Update this README, the manifest inventory, topology tests, and exact
  generated projection whenever the leaf surface or zone DAG changes.


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
