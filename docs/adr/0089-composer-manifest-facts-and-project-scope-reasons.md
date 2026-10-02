# 0089. Composer Manifest Facts and Project Scope Reasons

> **Amendment:** [ADR 0093](0093-measured-run-scope-and-project-tree-queries.md) retains this captured Composer universe and source lifetime and measures final filesystem selection before building one shared judgement. It replaces the path-only reader gate and shared discovery API below; original gate observations remain historical.

**Date:** 2026-09-29
**Status:** Accepted

Amends [ADR 0084](0084-a-project-scope-has-three-states-and-the-report-names-it.md).
The original three-state decision remains recorded there; this decision adds
an unmeasured subset and replaces independent manifest reads.

## Context

Configuration discovery, scope measurement, namespace attribution, external
ancestry and HTML metadata consumed the analysed project's Composer manifest
through independent readers. They could disagree about rejected records or
observe different bytes during one invocation. Namespace attribution read
the working directory in its constructor, before the command selected its
project.

An intact document and a partially accepted autoload section are different
evidence. Dropping an invalid entry and treating the surviving defaults as the
complete project let whole-project channels assert that omitted code matched
nothing. A missing manifest also supplied no denominator against which a
subset could establish that claim.

Commands had different document doors. Graph export built discovery outside
the normal run configuration, rules listed a container catalog without reading
the current document, and baseline runtime options differed by command.
The graph's output format has its own vocabulary and cannot be a reporting
format written into the analysis document.

## Decision

### One subject owns source facts

`Analysis\ProjectManifest` owns immutable `ComposerManifestFacts`, read state,
typed issues and the pure decoder. It records accepted autoload records,
production and development integrity separately, package metadata and source
identity. Each `ComposerAutoloadSection` owns its accepted mappings, integrity,
targets and PSR-4 roots. Production and development are two values of that
same subject; rejected-record ordering remains with the source facts.
The root must be a JSON object; path records must be strings or lists of
strings. The existing trailing-slash trimming and empty-to-dot normalization
are preserved. Rejected records retain their locations, and valid siblings
survive. Numeric-looking strings remain strings.

`Infrastructure\Composer\ComposerManifestReader` owns IO, classmap glob
expansion and a snapshot keyed by the canonical project directory. Missing,
unreadable and invalid manifests are cached too. `Application` calls
`ManifestSnapshotControlInterface::beginInvocation()` once, after choosing the
working directory and before the first read. Scope resolution and formatters
do not reset it. `observedIssues()` reads only captured facts.

Configuration discovery, Run, Measurement, Console, Reporting and Composer
adapters consume the same reader. Measurement's
`ProjectNamespaceSourceControlInterface::bind()` replaces namespace prefixes
before collection; its constructor does no filesystem work.
`ProjectSourceConfigurator` binds namespace attribution and the existing DIT
install anchor. Bounded install-root discovery preserves its order and
12-step limit and publishes typed omissions through that Infrastructure-owned
anchor. Installed JSON and generated classmap PHP remain separate data readers;
no Composer PHP is executed and no runtime class is loaded to resolve ancestry.

### Run owns one measured scope

`ProjectScopeUniverse`, `ProjectScopeMeasurement` and `ProjectScopeState` live
under `Analysis\Run\Contract\Configuration`. The universe holds seven
immutable initial facts: canonical root, path authoredness, denominator,
pruned targets, reasons, namespace-map usability and written-to-canonical
path resolutions. It resolves captured aliases and computes coverage without
IO. The measurement holds that universe, current paths, state and uncovered
targets. `RunConfiguration` requires the measurement and both explicit enum
policies; paths and its coverage boolean derive from that measurement.

These values have different lifecycles. The initial universe is captured once;
each narrowed verdict retains the same universe instance while paths, state
and uncovered targets can change. Bundling the ten former constructor fields
into a generic parameter array would obscure this distinction.

`measure(root, paths, autoloadDev, PathsAuthorship)` performs the initial
measurement with an explicit authored/inferred enum. Pure
`measurement->narrowTo(finalPaths)` can only close a coverage answer. It
performs no IO and cannot reopen a closed answer. `withProjectScope()`
transfers that measurement with every other run field intact. A different
uncaptured project root is a programmer error.

Initial filesystem path acquisition and default eligibility have separate
responsibilities from the verdict. Private `ProjectScopePaths` owns pruning,
denominator resolution and captured aliases; `ProjectScopeDefaults` owns
selected-section completeness and refusal of unusable inferred paths.
`ProjectScopeCoverage` retains the manifest read and measurement operation.
The former public `reachableTargets()` utility moves to the private path
subject; cross-owner adapters resolve inputs through the Run resolver contract.
No alias or additional manifest read is retained.

| Selected manifest and paths                                                                      | State and whole-project judgement             |
| ------------------------------------------------------------------------------------------------ | --------------------------------------------- |
| Intact selected autoload, every counted target reached                                           | `covered`, judge                              |
| Intact selected autoload, a counted target outside the paths                                     | `narrowed`, withhold                          |
| Damaged selected autoload, an authored whole project root                                        | `unknown`, judge                              |
| Damaged selected autoload, a subset or surviving inferred defaults                               | `unmeasured`, withhold                        |
| Absent manifest or intact document without declared code, whole root including fallback defaults | `unknown`, judge                              |
| Absent manifest or intact document without declared code, a subset                               | `unmeasured`, withhold                        |
| Unreadable defaults or a declared universe with no accepted reachable target                     | Refuse before discovery; write explicit paths |

A metadata error does not damage an otherwise intact selected autoload
section. Namespace location uses accepted PSR-4 facts, independently of the
scope enum. Authored paths include presets and YAML as well as CLI arguments.

The existing treatment of missing on-disk targets remains: they are named as
`missing-target` reasons and omitted from the denominator. An intact
all-missing declaration can therefore leave an empty denominator and cover an
authored subset. This is a limit of this decision, not evidence that every
declared target exists. A missing default path is still refused before
analysis. Paths outside the selected root and the interaction with discovery
filters are separate scope-policy work.

`CheckScopeResolver` reuses the initial measurement. The current Git scope
keeps analysis paths and limits finding publication through `reportScope`;
its pure transfer does not constitute another manifest measurement.

### Reports preserve causes

`projectScope` has five fields in every structured state: `state`,
`uncoveredAutoloadTargets`, `unjudgedChannels`, `unjudgedValues` and
`reasons`. A reason is an object with a closed `kind` and named cause data.
The kinds are `manifest-issue`, `no-declared-code`, `incomplete-universe`,
`pruned-target`, `missing-target` and `omitted-composer-root`.

Console appends already observed manifest issues and root omissions after
analysis, including when channels are withheld. Auxiliary issues explain
degraded ancestry evidence; they do not close main-project coverage.
Suppression-value projection preserves reasons. Document formats always carry
them, and formats with a diagnostic place publish a sentence when there is a
cause, even for a covered run. GitLab and Checkstyle retain their existing
finding-only limitation.

### Command profiles limit consumers

Every document door completes and judges the declared context-free schema.
The existing opaque rule-option and computed-reference boundaries remain
with their named consumers; this is not additional rule-merge validation.

`AnalysisPreflightProfile` selects Console ingress and consumers.
Graph maps paths, excludes, generated/development inclusion, cache, workers,
memory limit, framework namespace configuration and preset ingress. Its `--format/-f` remains
`GraphExportFormat` (`dot|json`), and is never mapped to `ConfigSchema::FORMAT`.
Its prepared input has no document payload or finding configuration.
Graph `--direction` is long-only. Its analyzer receives mandatory
`RunConfiguration` and the configured discovery strategy; file eligibility,
generated-file filtering and skipped-entry coverage share Run's discovery
implementation without executing the Finding-backed exclude audit.

Rules reads the document, configured computed metrics and current selection,
without constructing a Run or requiring analysable files.
Debug supports presets. All four measuring baseline commands share runtime
options through their common definition. Hook commands and baseline channel
renaming do not read a configuration document.

The common input-path validator refuses missing paths and explicit existing
regular non-PHP files before discovery. Direct Finder use refuses the latter
as well. Discovery's existing non-regular PHP skip semantics remain.

Automatic config discovery compares directory-entry names byte-for-byte:
exactly `qmx.yaml` or `qmx.yml`. Two exact files refuse. A near spelling warns
only when there is no exact file; an explicitly named config is read as named.
An unlistable working directory refuses rather than being treated as the
absence of a config file. This decision does not depend on case-insensitive
filesystem lookup.

## Measured price and limits

Before the predicate changed, the locked benchmark install contained 130
packages and 169 shipped manifests, including 39 secondary manifests.
Main roots were 128 intact declarations and two intact documents without
declared code; naturally damaged main roots numbered zero.

In 420 considered modes the predicate was unchanged. The two affected real
proper subsets were one existing `index.php` in each of
`codeigniter/framework` and `johnpbloch/wordpress-core`. Both complete captures
analysed one file and exited 0. They contained six and zero findings
respectively, with zero withdrawn findings in the eight whole-project channels.
All 32 actual gate invocations retained their prior predicate:
31 covered and one narrowed.

These observations price those invocations and one subset per affected
package. They do not price arbitrary subsets or damaged main manifests,
whose natural measured population was empty. Product regressions test damaged
manifest behaviour; synthetic damage is not a substitute for a corpus price.

## Rejected alternatives

Keeping independent readers, resetting between phases, or letting formatters
read fresh bytes would retain disagreement within an invocation.
A generic lifecycle port or a Configuration metadata DTO would obscure the
subject owner. Compatibility readers, boolean-copy scope methods and policy
defaults would retain competing construction surfaces.
Treating accepted fragments as a complete inferred universe would hide the
missing evidence. Refusing every partial document would also discard useful
analysis of explicit inputs.

## Migration

Replace `ComposerReader` and `ComposerAutoloadPathReaderInterface` reads with
`ComposerManifestReaderInterface::read()` and typed facts. Compose its snapshot
control into `Application`; bind Measurement from those facts before collection.
Replace constructor `paths:` and `coversProjectScope:` arguments with
`projectScope:`, and supply `AutoloadDevPolicy` explicitly.
Construct `ProjectScopeUniverse` from the seven initial fields, then construct
`ProjectScopeMeasurement` from that universe, current paths, state and uncovered
targets. Read initial facts through `measurement->universe`; supply
`PathsAuthorship::Authored` or `Inferred` to `measure()`. Replace `narrowedTo()`,
`coveringProjectScope()` and static `ProjectScopeCoverage::narrow()` with
`measurement->narrowTo()` and `withProjectScope()`. Move measurement/state
imports from Run's internal Configuration namespace to its Contract namespace.
Replace `ProjectScopeCoverage::reachableTargets()` calls in adapters with the
Run resolver contract; Run's own resolver uses private `ProjectScopePaths`.
Consumers of `projectScope` accept `unmeasured` and retain `reasons`.
Replace static `HtmlProjectMetadata::of()` with an instance constructed from
the reader and pass it as `HtmlTreeBuilder`'s third argument.
Replace graph analyzer path/root inputs with mandatory Run configuration and
discovery inputs, and compose it with the shared `AnalysisFileDiscovery`.
Correct integrations following the former documented alias: use
`--direction`, while global `-d` selects the working directory. Correct automatic config spelling,
and write explicit paths when damaged Composer defaults cannot establish them.

Replace consecutive `HtmlDebtCalculator::computeDebt()` and `aggregateBottomUp()`
with `calculate(root, findingsByNode, nodesByPath)`. The two former steps are
one complete debt operation; node counts, own debt and aggregated totals remain
unchanged.
