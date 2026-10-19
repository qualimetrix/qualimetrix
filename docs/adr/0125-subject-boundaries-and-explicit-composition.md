# 0125. Subject Boundaries and Explicit Composition

**Date:** 2026-10-15
**Status:** Partially superseded by [0128](0128-subject-boundaries-with-one-way-operations.md) for placement and directive-suggestion ordering

## Context

Several established subjects still shared a parent namespace: written-name
binding, individual metric algorithms, channel and option vocabulary, layer
matching, file publication, private-group ownership, baseline lifecycle and
console delivery. A namespace's own class count exposed these separate reasons
to change; moving arbitrary classes to a generic helper directory would only
hide the population while retaining the same dependencies.

Console result presentation and runtime setup additionally combined policy
preparation, effects, formatting and publication. Moving these wide classes
unchanged would transfer their outgoing coupling to a new address.

## Decision

Keep semantic module owners and their existing contracts. Give the named
subjects direct child namespaces. Core file publication and private-group
ownership retain separate lifecycles; Baseline follows entry, document,
capture, coverage, maintenance, rename and explanation operations. Metric
algorithms and their collector, options and rule move together. Finding's
channel, selection, option-language and channel-level vocabulary follow the
promises their consumers read. Layer allow and matching operations stay with
Architecture.

Infrastructure adapters remain in Infrastructure. Cache root selection,
provenance and trust form `Cache\Root`; Git's NUL-separated change listing
forms `Git\ChangeListing`. DI's `RuleCatalog` composes rule, channel, Options
and validator metadata. Each evidence configurator lives under its own direct
DI subject; collection and dependency-model composition remain in `Configurator`.
Reject a common evidence-production namespace: its implementations have separate
capability owners and reasons to change. `CompilerPass` retains unrelated
composition mechanisms rather than manufacturing many new one-class modules.

All service discovery roots and compiler passes remain finite. Rule registration
across moved roots retains the previous basename order, including inside a phar;
collector and derived ordering is preserved separately. Baseline exclusions use
Symfony's native array of paths: concatenating the longer moved addresses into
one brace pattern exceeds the native glob length limit. Existing registration,
population, channel-order and ownership witnesses cover the moved roots.

Console separates candidate finding preparation, projection options, late
projection diagnostics, report assembly, formatting and terminal publication.
`CheckReportDelivery` prepares an invocation-local `CheckResponse`; that response
owns the one profile-completion decision and the settlement, rendering, profile
and publication sequence. Values containing invocation input/output/session are
not services. Runtime preparation commits owner state before fallible runtime
effects; cache clear/final reporting are a Console lifecycle adapter over the
Cache-owned factory. Every diagnostic consumer retains the one composed
`ErrorStream`. No new generic context, phase port or compatibility surface is
introduced.

Commands follow the action they deliver: check and graph export have their own
subjects; hook, directives and rule-listing commands join their existing
subjects. Baseline generation, maintenance and whole-project measurement
have separate lifecycles. Invocation distinguishes command declarations,
authored rule options and input configuration. Terminal report publication
keeps its output helper beside the operation; shared diagnostic routing and
glyph policy remain at the terminal boundary.

CheckReport and RunSetup keep one response and setup lifecycle respectively.
Their operations have separate collaborators; splitting their directories
again solely to reduce namespace CBO would scatter those lifecycles. Namespace
CBO counts distinct far-side namespaces, so finer ownership on the other side
can increase it without increasing a class's outgoing responsibility. Retain
precise finite namespace ceilings for this effect; class quality remains
measured independently. A parser, resolver or builder does not become an
unbounded popularity exception merely by living under `Contract`.

The response constructor keeps its invocation input, output, target session,
namespace selector and completed delivery operations explicit. Rendering takes
independently resolved analysis, scope, projection, configuration and output
facts. A parameter bag would remove neither dependencies nor lifetime
requirements, while returning these responsibilities to the command would
restore the former broad orchestration. Keep precise finite signature
ceilings for these boundaries. The single-class ComputedMetrics composition
namespace also retains a finite maintainability floor: its namespace formula
combines declarative registration volume while the unchanged class has shallow
methods and a healthy class maintainability index. This is a limitation of the
aggregate model, not an algorithmic improvement.

Reporting's existing `Report` value is a declared contract for the named Console
assembler and formatter. It was already the input of public formatter methods
and the output of public builder/enrichment operations. Separating those two
operations makes that existing value boundary explicit; erasing the type or
adding a transport facade would hide the same dependency. SuppressionComposition
remains an unnamed internal value between its public builder and report builder.

## Migration

All namespaces below are relative to `Qualimetrix\`. For each listed declaration,
replace its old namespace with its new namespace in imports, fully qualified
names and class strings. Names and algorithms are unchanged. These are the
moved manifest-declared contracts; private implementation placement is described
in the owning component README. No compatibility aliases are provided.

| Declarations                                                                                                                                                                                                                                                                                                                                | Old namespace                    | New namespace                                   |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------- | ----------------------------------------------- |
| `AcceptedLevel`, `ChannelDeclaration`, `ChannelDeclarationRegistryInterface`, `ChannelIdentityInterface`, `ChannelPresentation`, `ChannelPresentationInterface`, `ChannelPublication`, `ChannelSelectionRole`, `ChannelShape`, `ChannelUniverseInterface`, `FindingChannel`, `JudgedMetrics`, `LevelActivity`, `ProducerDeclaration`        | `Analysis\Finding\Contract`      | `Analysis\Finding\Contract\Channel`             |
| `SelectionFilter`, `SelectionRecord`, `SelectionTrace`                                                                                                                                                                                                                                                                                      | `Analysis\Finding\Contract`      | `Analysis\Finding\Contract\Selection`           |
| `ChannelLevelAddressing`, `ChannelLevelSelector`                                                                                                                                                                                                                                                                                            | `Analysis\Finding\Contract\Rule` | `Analysis\Finding\Contract\Rule\ChannelLevel`   |
| `BandDirection`, `FrameworkOptionKeys`, `NoConfiguredBoundary`, `ResolvedRuleOptionValues`, `RuleOptionAddress`, `RuleOptionBand`, `RuleOptionDocumentFormsInterface`, `RuleOptionKey`, `RuleOptionKeySet`, `RuleOptionRefusal`, `RuleOptionRefusalWording`, `RuleOptionShape`, `RuleOptionSurface`, `RuleOptionWordSet`, `ThresholdParser` | `Analysis\Finding\Contract\Rule` | `Analysis\Finding\Contract\Rule\OptionLanguage` |
| `BaselineCapture`, `BaselineGenerator`                                                                                                                                                                                                                                                                                                      | `Analysis\Policy\Baseline`       | `Analysis\Policy\Baseline\Capture`              |
| `BaselineChannelRenamer`, `ChannelRenameMap`, `ChannelRenameRefusal`, `ChannelRenameReport`                                                                                                                                                                                                                                                 | `Analysis\Policy\Baseline`       | `Analysis\Policy\Baseline\ChannelRename`        |
| `BaselineDocumentReader`                                                                                                                                                                                                                                                                                                                    | `Analysis\Policy\Baseline`       | `Analysis\Policy\Baseline\DocumentAccess`       |
| `BaselineLoader`                                                                                                                                                                                                                                                                                                                            | `Analysis\Policy\Baseline`       | `Analysis\Policy\Baseline\DocumentReading`      |
| `BaselineWriter`                                                                                                                                                                                                                                                                                                                            | `Analysis\Policy\Baseline`       | `Analysis\Policy\Baseline\DocumentWriting`      |
| `Baseline`, `BaselineEntry`, `BaselineEntryMode`, `EntrySelector`                                                                                                                                                                                                                                                                           | `Analysis\Policy\Baseline`       | `Analysis\Policy\Baseline\Entry`                |
| `BaselineCleaner`, `BaselineCleanupCandidate`, `BaselineCleanupReason`, `BaselineUpdateDisposition`, `BaselineUpdateResult`, `BaselineUpdater`                                                                                                                                                                                              | `Analysis\Policy\Baseline`       | `Analysis\Policy\Baseline\EntryMaintenance`     |
| `BoundaryExplanation`, `BoundaryExplanationService`, `BoundaryExplanationStatus`, `BoundaryRunFacts`, `BoundaryThresholdSources`, `EffectiveBoundary`, `EffectiveBoundaryBaselineSource`                                                                                                                                                    | `Analysis\Policy\Baseline`       | `Analysis\Policy\Baseline\Explanation`          |
| `RunRuleCoverage`, `RunScope`                                                                                                                                                                                                                                                                                                               | `Analysis\Policy\Baseline`       | `Analysis\Policy\Baseline\RunCoverage`          |
| `ProcessOwner`                                                                                                                                                                                                                                                                                                                              | `Core\FileTarget`                | `Core\FileTarget\PrivateGroup`                  |
| `FileReplacement`, `HeldLock`, `HeldTarget`, `NewName`, `PreparedTarget`                                                                                                                                                                                                                                                                    | `Core\FileTarget`                | `Core\FileTarget\Publication`                   |
| `ConsoleDiagnostics`                                                                                                                                                                                                                                                                                                                        | `Infrastructure\Console`         | `Infrastructure\Console\Application`            |

### Console composition callers

Existing callers of the internal Console services must also select the completed
operation instead of replacing a facade name alone. All names in this table are
relative to `Infrastructure\Console\`.

| Old call                                                             | New operation                                                                                                                                                                   |
| -------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `RuleInputValidator::resolve(document, input)`                       | `RunSetup\FindingConfigurationPreparation::resolve(document)`                                                                                                                   |
| `RuleInputValidator::validate(input, configuration, definitions)`    | Read the mandatory channel universe from the prepared Finding configuration; worker spelling is judged by `Invocation\Configuration\WorkerCountParser` during input adaptation. |
| `RuleInputValidator::configureCheckCommand(command)`                 | `Invocation\CommandDefinition\CheckCommandDefinition::configure(command)` on the composed instance.                                                                             |
| `FindingFilterOrchestrator::projectionOptions(...)`                  | `CheckScope\FindingProjectionOptionsResolver::resolve(...)`                                                                                                                     |
| `FindingFilterOrchestrator::filterAndReport(...)`                    | `CheckReport\CheckFindingProjection::project(...)`, which combines late projection and its diagnostics.                                                                         |
| `FindingFilterOrchestrator::projectScope(...)`                       | `CheckScope\ProjectScopePresenter::project(...)`                                                                                                                                |
| `ResultPresenter::renderResults(...)`                                | `CheckReport\ReportAssembler::assemble(...)` builds the report only; the check response uses `CheckReportRenderer` for formatting and exit status.                              |
| `ResultPresenter::bindOutputOptions(...)`, `bindOutputFormat(...)`   | `CheckReport\FormatterContextFactory::bindBeforeAnalysis(...)`, `bindFormatBeforeAnalysis(...)`                                                                                 |
| `ResultPresenter::publishReport(...)`, `assertOutputIsWritable(...)` | `Terminal\ReportPublication\ReportPublication` operations of the same names.                                                                                                    |
| `ResultPresenter::presentProfile(...)`                               | `Profile\ProfilePresenter::outcome(...)` for one terminal outcome.                                                                                                              |
| `ResultPresenter::writeDiagnostic(...)`                              | The composed `Terminal\ErrorStream::write(...)` operation.                                                                                                                      |
| `RuntimeConfigurator::clearCacheIfRequested(...)`, `finishRun()`     | `CacheLifecycle\CacheRunLifecycle` operations of the same names.                                                                                                                |

`Command\CheckCommand` becomes `Check\CheckCommand`; graph export, hook,
directives and rule-listing command classes likewise join their named subjects.
The CLI spellings are unchanged. Resolve the commands from the container rather
than reconstructing their former constructor arguments. A complete check
response is prepared by `CheckReportDelivery` and finished by its invocation-local
`CheckResponse`; neither assembling a report nor formatting its body publishes it.

### Graph worker input

`graph:export --workers` changes from permissive integer coercion to an input
refusal. Previously `abc`, `1.5` and an out-of-range integer could be coerced or
ignored, allowing analysis and a graph with exit 0 despite the invalid request.
`Infrastructure\Console\Invocation\Configuration\WorkerCountParser` now judges
the supplied spelling during input adaptation and refuses these values with
exit 3 before analysis. Worker validation belongs at this shared input boundary:
graph export must not depend on Finding configuration validation to reject an
invalid parallelism request.

For migration, omit `--workers` to retain `parallel.workers` from configuration
(automatic detection by default), or pass a non-negative decimal integer in
`0..PHP_INT_MAX` for the running PHP platform. Explicit `--workers=0` preserves
sequential execution, as does `--workers=1`; zero is not an auto-detection request.
The option name and valid-value behavior are unchanged.

## Consequences

Own namespace class counts now describe the named subjects. Child namespaces
retain their actual populations and dependencies; an empty former parent is not
evidence that outgoing coupling disappeared. New and remaining quality signals
are measured on the complete source tree. Contract popularity and structurally
inapplicable metrics follow their existing exact policy; quality residuals keep
finite thresholds or exact baseline ceilings with a stated reason.

Mechanical address migration does not establish behavioral equivalence for the
new Console operations. Registration and argument comparisons, exact producer
rosters, owning behavior tests and the ordinary full validation remain separate
evidence. Historical changelog and ADR surfaces retain their original names.
