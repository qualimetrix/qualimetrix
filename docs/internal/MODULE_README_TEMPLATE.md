# Module README Template

Use this template for a leaf capability. Delete prompts that do not apply; do
not create an empty `Contract/` or internal folder merely to match the template.

## Subject, promise and owners

- **Subject:** What is this module about, as a noun phrase?
- **Promise:** What behaviour or information does it own?
- **Semantic owner:** Which manifest owner approves semantic changes?
- **Owned paths:** Production, tests, fixtures, support and documentation.
- **Non-goals:** Adjacent concerns explicitly owned elsewhere.

## External consumers and contracts

List every external owner-consumer and the exact contract types it imports. A
permanent consumer is owner-wide for that exact target contract declaration. Do
not generalise a declared consumer to a fourth declaration of the same owner. If there are no
named consumers, state “Private leaf; no external contract” and do not add a
`Contract/` directory.

| Consumer owner | Source FQCN (`null` if permanent) | Contract type | `closes_in` | Promise used |
| -------------- | --------------------------------- | ------------- | ----------- | ------------ |
|                |                                   |               |             |              |

Internal entities, holders, raw configuration and framework types must not be
listed as contracts.

## State and lifecycle

Describe every mutable value, its owner and scope (`per-file`, `per-run`, or an
explicitly reviewed process-wide infrastructure proxy). State who creates,
resets and reads it, and how two sequential runs prove isolation.

| State | Scope | Owner | Created/reset by | Typed readers |
| ----- | ----- | ----- | ---------------- | ------------- |
|       |       |       |                  |               |

## Dependencies and ports

List public dependencies only. Consumer-owned ports belong to the consumer;
identify the implementation here without relocating the port. Record typed
phase inputs, outputs and any proven earlier-phase dependency. Do not encode
ordering through priorities or shared mutable state.

The proposed `Analysis\Run` phase ports are non-binding hypotheses until the P3
phase-port contract gate accepts their signatures and contract tests. Before
that gate, document observed dependencies without presenting a proposed port as
current API.

| Dependency/port | Owner | Direction | Typed input/output | Why required |
| --------------- | ----- | --------- | ------------------ | ------------ |
|                 |       |           |                    |              |

## Test ownership

List module-owned unit, integration, functional, fixture, support and process
paths. Name the promise each suite protects and the command that proves it is
still discovered. Cross-module tests belong to the module whose promise they
verify; use `System` only for a named whole-product scenario.

## Extension registration

If the module participates in automatic registration, state the extension
family, implementation path, DI configurator/scanner, tag, composite/registry
consumer, deterministic id/order contract and duplicate-id behaviour. Write
“None” when it has no extension point.

## Composition bindings and locality check

Document a private declaration referenced directly by the composition root as a
permanent exact `composition_binding`: name its DI source, target, container
operation, and the coarse qmx pair it retains. A binding is not a public
contract, a general owner permission, or an exemption from the manifest check.
For every change, confirm that code, tests, fixtures, support, and documentation
move with their subject; external consumers use declared contracts; and mutable
state has one owner, reset point, and typed readers. No wildcard sibling access
or taxonomy allow target is valid.
