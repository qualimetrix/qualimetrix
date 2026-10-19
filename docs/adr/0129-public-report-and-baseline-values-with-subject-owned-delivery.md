# ADR 0129: Public report and baseline values with subject-owned delivery

## Status

Accepted

## Context

Namespace quality signals exposed two different concentrations. Console and
container composition grouped independently changing operations by technical
role. Reporting and Baseline mixed public immutable values with the services
that assemble, apply or deliver them. Incoming readership made those concrete
value namespaces stable, while their physical placement did not express the
public promise that already existed.

The correction must preserve the definitions of CBO and distance, and must not
move services into `Contract` merely to exempt them from analysis.

## Decision

- Application command loading owns lazy product-container lookup and its build
  failure. The application shell retains process diagnostics and invocation
  lifetime.
- Check report assembly, finding projection and format binding are separate
  subjects. Binding judges `--format-opt` before analysis; projection selects
  findings and publishes selection diagnostics; assembly constructs the report.
  Delivery retains the response and publication sequence.
- Run setup separates input preflight, finding configuration, execution
  settings and capability state. Its root coordinates runtime effects and the
  analysed project source. Each prepared value stays with its operation.
- Concrete container registration belongs to the subject it composes. The
  factory names every configurator explicitly. The common configurator contract
  and process defaults do not enrol future siblings implicitly.
- `Reporting/Contract/Formatting` promises formatter ports, rendering context
  and grouping to Console format binding. `Reporting/Contract/Report` promises
  the report, health enrichment and project scope snapshots to Console assembly
  and the supported formatters. These are existing public values and ports;
  builders, registry, projections, enrichment and protocol implementations stay
  outside `Contract`.
- Rendering bytes and encoding policy belong to `Publication`; the common
  structured finding representation belongs to `FindingPublication`. Each
  output protocol owns its formatter. Coverage owns collection-failure
  projection.
- `Baseline/Contract/Entry` promises immutable baseline snapshots, accepted
  identities, selectors, modes and rejected-entry records to Console baseline
  commands and Reporting finding projection. Constructors enforce value
  invariants; parsing, writing, capture, comparison, update and cleanup remain
  internal operations. `Ceiling/GroupAcceptance` is an internal decision.
- Prioritization publishes only its immutable debt result through
  `Contract/Debt`; calculation and remediation lookup remain private. Token
  normalization remains private Duplication algorithm state alongside the
  duplication operation, rather than an independent stable value bucket.
- Console composition follows input preparation, runtime effects, check
  publication, hooks, graph export, directives and rule listing. Each operation
  owns its registrations. Baseline persistence and baseline commands stay with
  Baseline composition; source discovery and Inline policy have their own
  registration lifecycles. The product factory retains an explicit finite
  configurator roster rather than discovering configurators dynamically.
- Graph-export input defines the CLI projection vocabulary and validates bound
  namespace selectors. The command retains analysis and publication order.
- Computed-metric registration separates health metadata construction from
  summary and drill-down construction. This shortens the operations while
  preserving their explicit shared service bindings.

Authored tree values, name binding and reading context remain with document
reading. Layer membership criteria and their evaluation share Layer; allow
selectors and their reading and validation share Architecture Configuration.
Those values do not promise independent module lifecycles. Combining each
language with its operation removes artificial stability dependencies without
inventing an interface or moving an implementation into a public contract.
The resulting namespace class-count warnings retain explicit follow-up
ownership; no error is accepted in the ratchet.

This physical rejoin reintroduces the Configuration/Layer namespace cycle from
before the earlier namespace split: Configuration reads Layer definitions,
while LayerPolicy reads the existing AllowTarget and CaptureBinding values. The
final Architecture graph has four cyclic namespace edges, including the
retained Layer/ClassContext pair; the intermediate two-edge graph is
superseded. An independent source-AST comparison against both the intermediate
split and the original tree found the same 31 forward and two reverse
declaration references after translating exact renames. No new
configuration-reading or construction operation is called from Layer. The
logical allow-language zone remains separately governed: Layer may consume its
values, but may not call the adjacent configuration validators or factory.

Inline's suppression extractor, threshold extractor and rule-validator map
factory are implementation operations. They move from Contract to their
Extraction and Threshold subjects; the existing source-control extraction port
remains the consumer promise. Layer's known-type query belongs beside the named
criteria and unmatched-type judgements it decides, while class-context
preparation retains ancestry and per-class facts.

The placement follows ownership and lifecycle: changing a formatter protocol
changes that protocol; changing the public report snapshot changes its contract
and readers; changing baseline parsing changes the private parser rather than
its result vocabulary. A consumer of a snapshot cannot construct a parser,
container registration or delivery operation through the contract surface.

## Consumer migration

Names below are relative to `Qualimetrix\`. Replace imports and class strings
for the existing public values; no compatibility aliases are provided.

| Old name                                           | New name                                                    |
| -------------------------------------------------- | ----------------------------------------------------------- |
| `Reporting\Report`                                 | `Reporting\Contract\Report\Report`                          |
| `Reporting\ReportProjectScope`                     | `Reporting\Contract\Report\ReportProjectScope`              |
| `Reporting\FormatterContext`                       | `Reporting\Contract\Formatting\FormatterContext`            |
| `Reporting\GroupBy`                                | `Reporting\Contract\Formatting\GroupBy`                     |
| `Analysis\Policy\Baseline\Entry\Baseline`          | `Analysis\Policy\Baseline\Contract\Entry\Baseline`          |
| `Analysis\Policy\Baseline\Entry\BaselineEntry`     | `Analysis\Policy\Baseline\Contract\Entry\BaselineEntry`     |
| `Analysis\Policy\Baseline\Entry\BaselineEntryMode` | `Analysis\Policy\Baseline\Contract\Entry\BaselineEntryMode` |
| `Analysis\Policy\Baseline\Entry\EntrySelector`     | `Analysis\Policy\Baseline\Contract\Entry\EntrySelector`     |

Report construction also changes. Remove the independent `errorCount`,
`warningCount` and `infoCount` constructor arguments: these read-only properties
now derive from `findings`. Move `healthScores`, `worstNamespaces`,
`worstClasses`, `techDebtMinutes`, `debtPer1kLoc`, `topIssues` and `namespaceTree`
constructor arguments into one `Reporting\Contract\Report\ReportHealth`, then
pass it as `health:` or replace it through `Report::withHealth()`. Existing
reads of those report properties remain available as read-only projections;
copy the captured report rather than rebuilding its unrelated publication facts.
`ReportHealth` is a new value, not an alias for a former class.

The report and formatting values serve Console report assembly, format binding
and presentation; the baseline values serve Console generation, explanation,
loaded-run and cleanup operations. These are exact manifest-declared consumer
promises. A type's physical presence under `Contract` does not make every sibling
public or admit a new consumer. Baseline parsing, comparison and persistence,
report builders and protocol implementations remain private operations.

Portable baseline-scope normalization has its own unchanged algorithm: replace
`Analysis\Policy\Baseline\Entry\Baseline::normalizeScope()` with
`Analysis\Policy\Baseline\Contract\RunScope::normalize()`, as recorded in
[ADR 0128](0128-subject-boundaries-with-one-way-operations.md).

## Consequences

PHP imports and report construction change as listed above; the CLI, report
schemas and finding channels do not.
Exact consumers, tests and manifest declarations follow the renamed types.
There are no compatibility aliases and no new runtime abstraction.

Public values can still have large incoming readership. Such popularity is
judged only at the exact declared public subject. Outgoing implementation
concentration remains a quality signal; relocating a file never authorizes a
broad scanner permission or a new indefinite quality suppression.
