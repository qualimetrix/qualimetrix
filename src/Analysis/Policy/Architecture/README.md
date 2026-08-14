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
  template-expansion work.
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
├── LayerViolation/             # rule, options and finding construction
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

Run prepares the policy after graph construction. `LayerViolationRule` reads
the prepared policy; it does not traverse the AST or construct lifecycle state.
The Console debug command invokes the inspector contract over the same collected
graph and class universe.

## Definition of Done

- Keep public consumers on the declared contracts; do not import an internal
  Architecture zone from another owner.
- Preserve independent reset semantics across sequential runs and zero work
  when layer policy is disabled.
- Update this README, the manifest inventory, topology tests, and exact
  generated projection whenever the leaf surface or zone DAG changes.
