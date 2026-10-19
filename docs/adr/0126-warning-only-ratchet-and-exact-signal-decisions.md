# ADR 0126: Warning-only ratchet and exact signal decisions

## Status

Accepted.

## Context

An accepted ceiling does not make a severe design problem residual debt. The
repository ratchet had mixed errors, class-specific model limitations and public
contract readership. That mixture obscured both the owner and the intended
response to a new finding.

The namespace CBO implementation counts the complete namespaces at the far end
of dependencies, including descendant namespaces (`CoupledNamespaces`). A
subject split can therefore raise a reader's namespace CBO while leaving its
class dependencies and responsibilities unchanged.

## Decision

The ratchet holds residual, currently accepted **warnings only**. A quality
warning needs an exact subject/channel, a named owner and a measurable closure
condition. Quality errors require a subject-owned refactor. If that refactor
moves a model-limited signal to a neighbouring class, the class carries its own
finite `@qmx-threshold` and reason. A class or method model limitation is never
parked in the ratchet.

The only authorised exception to the error invariant is namespace CBO for these
exact pre-existing subjects:

| Subject                                                | Before subject split | After subject split | Reason                                                                           |
| ------------------------------------------------------ | -------------------: | ------------------: | -------------------------------------------------------------------------------- |
| `Qualimetrix\Analysis\Run\Contract\Pipeline`           | 18                   | 25                  | More complete far-side namespace names for the same run protocol                 |
| `Qualimetrix\Reporting`                                | 23                   | 27                  | More complete far-side namespace names for the same publication boundary         |
| `Qualimetrix\Analysis\Configuration\Contract\Document` | 24                   | 30                  | More complete far-side namespace names for the same resolved-document vocabulary |

These values record the historical transition, not permission for an unreviewed
ceiling increase. The explicit category-4 recalibration of Pipeline to 30 is
justified by the exact dependency inventory below; it authorises no later
growth. The exception does not follow a renamed or newly created namespace by
similarity. An entry whose subject no longer produces that finding is removed.
The revised source census retains the Document and Pipeline exceptions; the
Reporting root no longer produces a finding and its row is removed. The
Pipeline dependency inventory below records its revised measurement; the
Document exception retains its recorded boundary.

Changing namespace CBO's definition is separate work: compare direct namespaces
with descendants on the existing calibration corpus, document the semantic
choice, and remove every surviving exceptional error entry. No other channel,
subject or later subject split inherits this exception.

Intentional public readership uses an exact signal exception. For class CBO,
the incoming population must itself reach the configured warning boundary; an
"Afferent" message alone is insufficient. Such an exception deliberately hides
future outgoing growth in that one channel; implementations and sibling
subjects remain measured. Public vocabulary is not a licence to keep executable
policy in a `Contract` directory.

Immutable fact carriers retain finite arity/property ceilings at the first
unaccepted magnitude. Report severity counts are derived from the published
findings. Health, debt and impact enrichment are one report-owned value, so an
enricher does not duplicate the complete report constructor.

`Finding/HealthThresholdOverrideValidator` is a concrete executable public
ComputedMetrics service outside its vocabulary-only `Contract` subtree. Its
closed validator map is consumed only by WorkerBootstrap and
ThresholdValidatorMapCompilerPass, which assemble the same source-threshold
policy for workers and the main process. Exact public imports express those two
consumers; they do not broaden DI-only composition authority. The metadata
family does not construct an internal implementation.

Builtin health producers accept one exact class boundary through the existing
inline-threshold lifecycle. Only a scalar or equal axes are accepted; direction
comes from the resolved metric definition. The open computed producer remains
unretunable, because it owns multiple runtime-defined names and directions.

Executable threshold parsing and severity comparison belong to the shared
Finding-owned threshold pair. Metric-owned Options retain their exact defaults
and option-key declarations. Repetition consisting solely of structural
interface or attribute declarations can be documented as structurally
inapplicable to clone detection; executable parsing is not such an exception.

## Consequences

A new reader of an intentional public contract does not demand a ratchet update.
A quality change remains visible through its symbol-local threshold or the
warning ratchet. Dead exclusions, dead directives and obsolete baseline entries
are removed together with the change that makes them dead.

The ratchet is not regenerated to absorb a failing check. Every changed row is
compared with the current measurement; an improvement tightens its ceiling.
The existing directive audit measures symbol-local thresholds. No extra
permanent checker is introduced for this policy.

## Rejected alternatives

- Keeping severity counters as constructor arguments allowed findings and counts
  to disagree. Computing them from published findings removes that independent
  state. A report-owned health value avoids a second complete constructor copy.
- Dynamic property names in shared Options traits violated the repository's
  typed-property policy. Literal field access in the integer and maximum-band
  traits retains metric-owned field names without reflection or suppression.
- Copying parsing into each Options class kept executable clones. Shared
  threshold-pair operations leave only exact structural declaration ranges;
  broader Options regex exclusions would also enroll future executable clones.
- A generic pipeline-phase port would publish implementation ordering as a new
  extension contract. Concrete preparation and measurement operations keep one
  measured context shared by reporting and directive audit.
- A second error-stream owner would break shared-reference section redraw.
  ErrorSections therefore holds the private section lifecycle of the existing
  ErrorStream, and the existing ownership control judges both constructors.
- Promoting the entire computed producer to a retunable rule would conflate
  unrelated runtime names and directions. Only the closed six health producers
  accept the single exact class boundary.

## Pipeline namespace-CBO recalibration

The native graph analysed all production sources at `0f735530f` (before the
subject split), `59191fbec` (the previous runtime), and the revised runtime. A
one-off exporter intercepted only serialization, retaining the actual
dependency analyser and each source snapshot. Its inventory follows
`getAllClasses()` / `getClassDependencies()`, exactly as `CoupledNamespaces`
does; `getAllDependencies()` also retains builtin inheritance edges that
coupling excludes and is not the metric's population. Both the native metric
and the independently enumerated far-side set agree at **18 / 25 / 30**.

The category-4 adjustment is **25 to 30**, with this exact explanation:

- The former DI Configurator namespace's existing run-analysis and directive /
  graph-export bindings now belong to Run, Console Directives and Console
  GraphExport: one far-side namespace becomes three (**+2**).
- Existing AnalysisResult / AnalysisCoverage / AnalysisFailure publication readers
  now belong to CheckReport Assembly and Projection alongside CheckReport:
  one namespace becomes three (**+2**).
- RunPreparation and PreparedRun now hold the discovery/collection preparation
  operation previously inside Pipeline, and read the same AnalysisCoverage /
  AnalysisFailure / AnalysisFailureKind run protocol (**+1**).
- Console Command Baseline and its Measurement subject become Console Baseline
  and Console Baseline Measurement: two far-side names replace two (**0**).

These changes are subject ownership and intended reads of an existing run
protocol, not five added responsibilities inside that protocol. The native
graph and independent far-side enumeration agree, as recorded above and in the
namespace inventory below. Class-level outgoing quality remains measured and
the preparation split has its own finite thresholds. The owner of this
exceptional error remains **Coupling metric definition follow-up**: compare
direct and descendant namespace semantics on the calibration corpus, adopt and
document the chosen definition, and remove this exact error entry. A new
namespace reader requires another explicit decision.

| Far-side namespace                                                   | Before subject split: 0f735530f | Previous runtime: 59191fbec | Revised runtime |
| -------------------------------------------------------------------- | ------------------------------- | --------------------------- | --------------- |
| `Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation`  | yes                             | yes                         | yes             |
| `Qualimetrix\Analysis\Evidence\DependencyModel\Contract`             | yes                             | yes                         | yes             |
| `Qualimetrix\Analysis\Evidence\Measurement\Contract`                 | yes                             | yes                         | yes             |
| `Qualimetrix\Analysis\Finding\Contract`                              | yes                             | yes                         | yes             |
| `Qualimetrix\Analysis\Finding\Contract\Channel`                      | —                               | yes                         | yes             |
| `Qualimetrix\Analysis\Finding\Contract\Population`                   | yes                             | yes                         | yes             |
| `Qualimetrix\Analysis\Finding\Contract\ProjectScope`                 | yes                             | yes                         | yes             |
| `Qualimetrix\Analysis\Policy\Baseline\Contract`                      | yes                             | yes                         | yes             |
| `Qualimetrix\Analysis\Policy\Inline\Contract`                        | yes                             | yes                         | yes             |
| `Qualimetrix\Analysis\Policy\Inline\Contract\Directive`              | yes                             | yes                         | yes             |
| `Qualimetrix\Analysis\Run\Contract\Configuration`                    | yes                             | yes                         | yes             |
| `Qualimetrix\Analysis\Run\Contract\Discovery`                        | yes                             | yes                         | yes             |
| `Qualimetrix\Analysis\Run\Discovery`                                 | yes                             | yes                         | yes             |
| `Qualimetrix\Analysis\Run\FileSetInspection`                         | yes                             | yes                         | yes             |
| `Qualimetrix\Analysis\Run\Pipeline`                                  | yes                             | yes                         | yes             |
| `Qualimetrix\Analysis\Run\Preparation`                               | —                               | —                           | yes             |
| `Qualimetrix\Core\Path`                                              | yes                             | yes                         | yes             |
| `Qualimetrix\Infrastructure\Console`                                 | yes                             | —                           | —               |
| `Qualimetrix\Infrastructure\Console\Baseline`                        | —                               | —                           | yes             |
| `Qualimetrix\Infrastructure\Console\BaselineCommand`                 | —                               | yes                         | yes             |
| `Qualimetrix\Infrastructure\Console\Baseline\Measurement`            | —                               | —                           | yes             |
| `Qualimetrix\Infrastructure\Console\Check`                           | —                               | yes                         | yes             |
| `Qualimetrix\Infrastructure\Console\CheckReport`                     | —                               | yes                         | yes             |
| `Qualimetrix\Infrastructure\Console\CheckReport\Assembly`            | —                               | —                           | yes             |
| `Qualimetrix\Infrastructure\Console\CheckReport\Projection`          | —                               | —                           | yes             |
| `Qualimetrix\Infrastructure\Console\CheckScope`                      | —                               | yes                         | yes             |
| `Qualimetrix\Infrastructure\Console\Command`                         | yes                             | —                           | —               |
| `Qualimetrix\Infrastructure\Console\Command\Baseline`                | —                               | yes                         | —               |
| `Qualimetrix\Infrastructure\Console\Command\Baseline\Measurement`    | —                               | yes                         | —               |
| `Qualimetrix\Infrastructure\Console\Directives`                      | —                               | yes                         | yes             |
| `Qualimetrix\Infrastructure\Console\GraphExport`                     | —                               | yes                         | yes             |
| `Qualimetrix\Infrastructure\DependencyInjection\Configurator`        | yes                             | yes                         | —               |
| `Qualimetrix\Infrastructure\DependencyInjection\Console\Directives`  | —                               | —                           | yes             |
| `Qualimetrix\Infrastructure\DependencyInjection\Console\GraphExport` | —                               | —                           | yes             |
| `Qualimetrix\Infrastructure\DependencyInjection\Run`                 | —                               | —                           | yes             |
