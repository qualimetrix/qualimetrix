# 0093. Measured Run Scope and Project Tree Queries

**Date:** 2026-10-02
**Status:** Accepted

Amends [ADR 0015](0015-relative-path-vo.md),
[ADR 0078](0078-an-entry-the-run-did-not-read-makes-it-incomplete.md),
[ADR 0084](0084-a-project-scope-has-three-states-and-the-report-names-it.md), and
[ADR 0089](0089-composer-manifest-facts-and-project-scope-reasons.md).
Composer snapshot lifetime, typed rule preparation, phase order and metric
algorithms remain unchanged.

## Context

A path-root predicate could say a run covered a project while discovery removed
PHP through authored exclusions or generated-file policy. Conversely, a complete
list of individual PHP files could appear partial merely because no directory
root was selected. A single boolean then answered two different questions:
whether declarations could be absent, and whether an authored exclude selector
bound to anything. A removed subtree made that second question uncertain, but
using the declaration predicate hid its audit entirely.

Separate discovery/probe walks also lost the relationship between selected
entries, hidden subtrees and configuration origin history. Equal file/CLI writes
could retain only the surviving contributor even though public write history
preserved both authors. A graph/debug caller rebuilding configuration from paths
could lose the captured universe, aliases and generated policy.

Source bytes independently read for parsing and metrics could disagree. Path
fallbacks could publish an outside target under an invented relative identity.
Finally, commands disagreed about a written root removed intentionally by an
exclude, and could mistake failure-plus-exclusion for complete empty success.

## Decision

### Capture input, then measure final selection once

Keep the mandatory Run configuration and captured `ProjectScopeUniverse`.
Resolution captures Composer facts, authored paths, origins, policies and accepted
aliases. `ProjectFilesInterface::discover(RunConfiguration): DiscoveredProjectFiles`
returns eligible files, generated exclusions, named exclusions, skips and measured
filesystem/selector facts from one walk. It replaces the discovery factory,
list-only and side-channel ports and the separate prune/probe implementations.

`ProjectWalk` and `EntryInspector` are the filesystem owners. Private per-walk
`ExcludeSelectorLedger` accumulates passed facts without listing, traversal, source
reads, a second classifier or DI registration. Graph/debug use the same captured
configuration and file service without executing the Finding-backed exclude audit.
The analysis pipeline materializes findings at its existing analysis seam.

The pipeline combines captured universe and discovery facts into one final
`ProjectScopeMeasurement`. It holds Finding's `ProjectScopeJudgement`, shared by
context, copied threshold contexts and analysis result. PreparedRun retains its
context rather than duplicating scope. Console publishes final measurement; an
initial omitted target-directory state is not the final PHP-path verdict.
Pure narrowing retains evidence without another IO read.

### Ask the two measured questions separately

`judgesNamespaceClaims()` asks whether namespace/declaration absence can be judged.
Its measured doors are omitted PHP paths, actual removed run entries with regular
PHP or unavailable search metadata, generated PHP removal and an unknown
denominator.

`judgesExcludeSelectors()` asks path completeness and universe certainty instead.
It does not accept Exclude/Generated as inputs. Already settled `Removed` selectors
and named inaccessible evidence survive withholding; only unsettled absence is
withheld. Consumers still need behavioral regressions for choosing the right
question: this separation does not make all wrong-consumer calls impossible.

Finding owns `ProjectScopeChannels` and both channel families:

| Question                   | Channels                                                                                                                                                                                                            |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Declaration absence        | `architecture.empty-template`, `architecture.unmatched-exclude`, `architecture.unreachable-layer`, `cohesion.unmatched-exclude-method`, `coupling.unmatched-framework-namespace`, `suppression.unmatched-namespace` |
| Selector/path completeness | `discovery.unmatched-exclude`, `suppression.unmatched-path`, `suppression.unmatched-rule-ledger`                                                                                                                    |

Namespace-valued rule-ledger entries also ask declaration absence. These are nine
current consumer channels, not a generic lifecycle or channel-registration API.
Report state (`covered`, `narrowed`, `unknown`, `unmeasured`) remains evidence
representation, not independent permission for both questions.

Path completeness compares observed regular case-sensitive `.php` files. A full
named roster can cover a known denominator; omitting an empty target directory
alone does not establish missing PHP. An observed outside-selection regular PHP
file remains missing even when it matches an authored exclusion. An observed
asset or special entry is not inferred missing PHP.

An excluded directory outside selection has unseen descendants. Record its path
as `IncompleteUniverse`, withhold both questions, and do not list or search PHP
inside it. Its selector remains bound. Even an actually empty/assets-only hidden
outside directory costs certainty: the run did not observe its contents. Outside
metadata refusal is universe uncertainty, not failure of the selected run.

### Preserve all matching selectors and directed origins

Group effective selectors by canonical display. Obtain source kind/name from
`ResolvedWriteHistoryInterface::writes()` and each write's provenance rather than
only surviving item contributors. No list-member position is promised by this
history-derived selector roster. The lawful ingress is resolved effective Run
configuration, not an artificial duplicate authored roster assembled independently
of that grouping.

Bind every matching selector before pruning any entry: files, directories, links
and special entries can all bind. Hidden subtrees are removed physical directories,
including the physical target of an accepted named directory alias. Walked links
and individual removed files are not hiders. Exact/subtree selectors use literal
containment; regex may match in any hidden directory.

The Finding-owned verdict has a closed constructor and a facts-based factory.
Measured source and hidden-directory facts reach that factory; callers do not
choose a contradictory free outcome. Source lists must already be nonempty and
distinct; the factory rejects duplicate origins instead of normalizing them. Derive
the directed difference: a possible hider is other-source if any of its origins is
absent from the query's source set. Intersecting sets are valid: hider A+B/query A
is Other, while hider A+B/query A+B is Same in the absence of another foreign
hider. Other-source takes priority over same-source.

For an unsettled selector, retain named inaccessible metadata; with known complete
paths, no possible hider means Unmatched, a foreign hider means
CoveredByOtherSource, otherwise a same-source hider means CoveredBySameSource.
Same-source warning describes matches outside what the hider removes, without
proving absolute staleness. Keep the skipped `{channel, option, pattern}` value and
publish a linked Exclude scope reason naming the queried selector, chosen hider,
only its origins absent from the query, and a rerun without that exclusion. The
reason describes source origins; it does not restore list-member positions.
Existing producer identity
and unmatched/inaccessible occurrence kinds remain.

PHP evidence may come only from actual removed run entries. Bounded metadata
search stops at the first regular `.php` in each search and can continue to the
next removed entry until it finds PHP. Assets, FIFO `.php` and file links do not
witness hidden PHP. The audit opens no source and does not search a removed
outside-selection directory. Regular PHP or `unlistable` search evidence from an
actual removed run entry closes only declaration absence, with the selector and
actual evidence retained in an Exclude reason. Neither opens a second filesystem
walk nor closes selector completeness.

### Treat intentional empty input as measured success

Authored exclusions apply to named files and directories from CLI, YAML and
presets. They no longer refuse a written directory or bypass a named file.
`analyzed=0`, `failed=0`, `excluded + generatedExcluded > 0` identifies a complete
intentionally empty result. Named exclusions count entries, not unseen PHP
children; narrative reports counts rather than claiming every named path was
excluded when another path was simply empty.

| Consumer                              | Complete intentionally empty                                   | Incomplete input                |
| ------------------------------------- | -------------------------------------------------------------- | ------------------------------- |
| `check`                               | 0 and selected coverage report                                 | 4 and partial diagnostic report |
| `directives`                          | 0 and measured text/JSON note                                  | 4 and partial diagnostic report |
| `graph:export`                        | 0, empty graph and stderr explanation                          | 4, no authoritative graph       |
| `baseline:generate`                   | 0, empty baseline and stderr explanation                       | 4, no destination mutation      |
| `baseline:update`, `baseline:cleanup` | Existing complete-run/recorded-scope behavior with explanation | 4, no mutation                  |
| `baseline:explain`                    | 0 after scope observation, without remediation claim           | 4                               |
| `debug:layer-assignment`              | Existing class-outside-set explanation                         | Existing refusal                |

Truly undiscovered empty input retains each command's existing checks/outcomes.
Failures take priority over intentional exclusions, policy findings and `--force`.
This accepts the price that an accidentally all-excluding configuration can return
0; measured stderr/machine evidence is the visible trace.

`DirectiveAuditPresenter` retains text/JSON serialization ownership and derives an
optional note for intentionally empty coverage from its report: it constructs
`ReportCoverage` and asks `CoverageNarrator`. Text prints the note; JSON renders
it as `scope.note`. The command passes no separate note parameter.
`ReportCoverageProjection` transfers named `excluded` separately from `discovered`,
which sums analyzed PHP, generated-excluded PHP and selected failed terminal entries.
Project scope preserves skipped `{channel, option, pattern}` values. A channel is
in `unjudgedChannels` only when none of its values was judged; a partial channel
can be absent there while its skipped values remain named.

### Keep input preflight separate from file publication

Accept `.`, `./`, absolute root and canonically equivalent root aliases. For an
existing directory input, its canonical target must be inside canonical root;
for a file, its canonical dirname must be inside. Written prefix containment is
not required when canonical binding is internal, so external-to-internal directory
aliases are admitted. Internal aliases targeting outside are refused. Unresolved
bindings require lexical containment and retain missing-input handling.

`PathFactory::published(file, canonicalRoot)` returns canonical dirname plus
lexical basename, preserving POSIX backslashes. Outside/unresolvable parents
throw LogicException; best-effort and structure-preserving fallbacks are removed.
The final named file-link target is not canonicalized for publication, even when
outside, if its parent is valid. Walked directory links are not followed.
Selected regular targets win over links to them regardless of discovery order;
without a selected regular target, the first named link wins. Ordinary files and
hardlinks are not deduplicated by inode.

Input refusal retains the exact original item's provenance through its existing
ResolvedValue refusal. Inferred Composer targets retain a positionless Composer
origin; default root belongs to Defaults. Missing/IO failures and publication
LogicException are not relabeled as CLI authorship errors.

### Supply the same source bytes to parser, LOC and Inline

Run privately creates SourceReader in file processing and graph analysis without
new constructor injection or a public IO lifecycle. A read refusal yields
`unreadable-file` before parser invocation and crosses worker transport as that
failure. `parseContent(originalAbsoluteFile, bytes)` parses the same bytes supplied
to LOC and Inline extraction. Parser/cache do not reopen source or infer cwd.
Cache keys use `generateForContent()`.

Only LocCollector implements Measurement-owned
`SourceMeasuringCollectorInterface`. Run owns reading and supplies captured bytes
through that narrow collection contract.
CompositeCollector resets before snapshot handoff and traversal; generic
collectors do not receive a source API. Generated-header inspection keeps its
short separate read and reports refusal without PHP warnings. Duplication still
rereads source; its silent-read-refusal limitation is separate work. This decision
does not promise every source reader opens a file only once.

### Make full-universe metadata an opt-in query

`ProjectTreeQueryInterface::snapshot(ProjectScopeUniverse): ProjectTreeSnapshot`
returns sorted distinct regular PHP paths under captured autoload targets plus
inaccessible metadata. `complete()` is false for inaccessible metadata. Built-in
floor applies; authored exclusions and generated policy do not. It opens no source
and follows no walked directory links, using the same EntryInspector and captured
alias facts. Empty/unknown targets do not become a known empty universe.

`hasFile(AbsolutePath root, RelativePath file): ProjectEntryPresence` distinguishes
Present, Absent and Unknown. Unknown is not absence. Normal analysis does not
request a full snapshot merely to serve a future baseline operation; a lifecycle
metadata consumer can request it explicitly once for its invocation.

The opt-in price is O(entries) metadata traversal and O(PHP files) memory,
including excluded autoload subtrees. There is no cap, persistent index, new
benchmark fixture or unconditional additional walk. The snapshot is metadata,
not a guarantee that bytes cannot change between filesystem observations.

### Preserve Git publication context

Keep a finding if its channel is one of the nine project-scoped configuration
channels, or its file location changed, or (non-strict) its namespace is a changed
namespace/ancestor, or (non-strict) it is a location-free project finding with
nonempty changed PHP. File location wins for code findings, including duplication.
Strict mode removes namespace/project widening but retains configuration diagnostics.

Diff uses `--no-relative`; empty range endpoints become HEAD before ref validation.
Git 2.28 flag refusal names the requirement without another version probe.
Namespace query uses `lstat` and does not read source through links.
`GitRepositoryLocator` requires an explicit AbsolutePath. The sole hook delivery
boundary captures effective cwd after Application applies `--working-dir` and
passes it once; the locator does not infer cwd.

## Consumer migration

| Removed surface                                                                                                                      | Replacement                                                                                                   |
| ------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------- |
| `FileDiscoveryInterface`, `FileDiscoveryFactoryInterface`, `SkipReportingDiscoveryInterface`, `AnalysisFileDiscovery`                | `ProjectFilesInterface::discover(RunConfiguration)` and its result                                            |
| `DiscoveredAnalysisFiles`, `FinderFileDiscovery`, `DirectoryPruner`, `DirectoryWalk`, `ExcludeBindingProbe`, `ExcludeBindingVerdict` | One Run file service/walk with passed selector facts; consume declared contracts, not private walk helpers    |
| `LayerAssignmentResolver` path/exclude/root arguments and `resolveIncludingGenerated()`                                              | `resolve(capturedRunConfiguration, symbol)`                                                                   |
| `AnalysisContext::$coversProjectScope` and `ProjectScopeCoverage::WHOLE_PROJECT_CHANNELS`                                            | Shared Finding ProjectScopeJudgement and its owning `ProjectScopeChannels` roster; choose the actual question |
| `FileParserInterface::parse(SplFileInfo)`                                                                                            | `parseContent(originalAbsoluteFile, sourceBytes)` supplied by the caller                                      |
| `CacheKeyGenerator::generate(SplFileInfo)`                                                                                           | `generateForContent(sourceBytes)`                                                                             |
| `PathFactory::bestEffortRelative()` / `structurePreservingFallback()`                                                                | `published(file, canonicalRoot)` after Run input preflight                                                    |
| No-argument Git locator methods; unused `GitClient::isRepository()`                                                                  | Explicit-root locator methods and existing checked Git scope resolution                                       |
| Unjudged values with only option/pattern                                                                                             | Retain `channel` alongside both fields and interpret partial channel lists correctly                          |

There are no compatibility shims or alternative configuration/discovery paths.
Outward history lives in CHANGELOG Breaking; migration must preserve captured
input rather than synthesize an approximate run from old arguments.

## Limits and rejected alternatives

The hand-built AnalysisContext default remains an intentional complete judgement
for unrelated metric fixtures. Production passes the measured object explicitly;
constructor typing alone does not prove every variable was measured.

Suppression regex is conservative when authored removal could hide a match.
An unrelated stale regex may remain unjudged. Without new suppression-author
provenance, a full run without the exclusion is the way to settle it.

Baseline metadata lifecycle integration is not implemented by this query alone.
Until consumers use it, cleanup can label an unmeasured excluded entry stale,
and partial explain has no per-entry outsideCoverage. Explanations qualify
absence by measured coverage. Exclusion is not remediation; review a full run
without the relevant exclusion before removing an acceptance.

Rejected: deriving both judgements from a report enum; another provenance source;
an outcome caller can contradict with flags; a second filesystem classifier;
unconditional excluded-tree enumeration; promising a complete universe while
forbidding all metadata descent; normalizing an external final file target to
admit an invalid parent; and command-side JSON decode/re-encode. No new governance
control or generic lifecycle port follows from this decision.
