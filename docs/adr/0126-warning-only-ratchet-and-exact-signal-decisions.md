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

There is no authorised error entry in the ratchet. Reporting stopped producing
its former finding after its subject split. Pipeline's dependency-graph,
directive-audit and coverage contracts now have their own subject namespaces,
so the main Pipeline contract returns below the error boundary and its former
30 entry is removed. The resolved-document namespace contains only the shared
read vocabulary and provenance values consumed by declared configuration
owners: 64 classes across 28 incoming namespaces, against two outgoing
contract namespaces. Its former error entry is therefore replaced by the exact
namespace-CBO public-readership exception used for intentional public readership.

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

Architecture's shared Allow language remains concrete by design: it carries
selector parsing, relation-token expansion, immutable policy values and the
self-glob semantic judgement below the Configuration and Layer operations.
Moving the judgement into that language and keeping authored shape extraction
in Configuration changes its distance from 0.83 (error) to 0.67 (warning), with
four incoming classes and two outgoing semantic contracts. Architecture owns
the finite category-3 warning ratchet. Its closure condition is a complete
`src/` analysis without a baseline measuring this namespace below the 0.50
warning boundary.

Moving executable selection resolution and terminal coverage contracts into
their subject-owned namespaces recalibrates three warning ceilings by one exact
far-side namespace; none reaches the rule's error boundary:

| Subject                                          | Before | After | Added subject boundary                                         |
| ------------------------------------------------ | -----: | ----: | -------------------------------------------------------------- |
| `Qualimetrix\Analysis\Finding\RuleConfiguration` | 19     | 20    | executable selection resolution beside rule configuration      |
| `Qualimetrix\Infrastructure\Console\Baseline`    | 22     | 23    | terminal coverage separated from the remaining pipeline result |
| `Qualimetrix\Infrastructure\Console\CheckReport` | 20     | 21    | terminal coverage separated from the remaining pipeline result |

Two private immutable phase-fact carriers make the existing
`Qualimetrix\Analysis\Run\Preparation` namespace measurable by increasing its
declaration population from two to four, across the configured three-class
floor. They preserve the existing discovery, retry, coverage and audit
responsibilities through explicit phase facts. Run therefore owns
finite category-3 warning ceilings at namespace CBO 22 and instability
0.9487179487179487. Each entry closes when its value in a complete `src/`
analysis without the baseline falls below its configured warning boundary:
CBO 16 and instability 0.90, respectively.

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

Executable selection services live outside the vocabulary-only contract tree:

| Old name                                                                 | New name                                                                          |
| ------------------------------------------------------------------------ | --------------------------------------------------------------------------------- |
| `Qualimetrix\Analysis\Finding\Contract\Selection\RuleEnablementResolver` | `Qualimetrix\Analysis\Finding\RuleConfiguration\Selection\RuleEnablementResolver` |
| `Qualimetrix\Analysis\Finding\Contract\Selection\RuleNameJudge`          | `Qualimetrix\Analysis\Finding\RuleConfiguration\Selection\RuleNameJudge`          |

Both operations interpret the same authored rule configuration lifecycle:
name judgement validates the schema's keys and selectors, while enablement
resolution combines those statements with resolved option activity. Keeping
them in RuleConfiguration avoids executable policy in Contract and avoids
separate one-class operation namespaces.

Run's public operations and terminal coverage use separate subject contracts;
method signatures and returned values are unchanged:

| Old name                                                                      | New name                                                                             |
| ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| `Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage`                 | `Qualimetrix\Analysis\Run\Contract\Coverage\AnalysisCoverage`                        |
| `Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure`                  | `Qualimetrix\Analysis\Run\Contract\Coverage\AnalysisFailure`                         |
| `Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind`              | `Qualimetrix\Analysis\Run\Contract\Coverage\AnalysisFailureKind`                     |
| `Qualimetrix\Analysis\Run\Contract\Pipeline\IncompleteAnalysisException`      | `Qualimetrix\Analysis\Run\Contract\Coverage\IncompleteAnalysisException`             |
| `Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalysisResult`    | `Qualimetrix\Analysis\Run\Contract\DependencyGraph\DependencyGraphAnalysisResult`    |
| `Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalyzerInterface` | `Qualimetrix\Analysis\Run\Contract\DependencyGraph\DependencyGraphAnalyzerInterface` |
| `Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditInterface`          | `Qualimetrix\Analysis\Run\Contract\DirectiveAudit\DirectiveAuditInterface`           |
| `Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditReport`             | `Qualimetrix\Analysis\Run\Contract\DirectiveAudit\DirectiveAuditReport`              |

Consumers replace these imports directly. No old-namespace aliases or
compatibility declarations remain.

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
