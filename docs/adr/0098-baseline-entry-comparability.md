# 0098. Baseline Entry Comparability

**Date:** 2026-10-05
**Status:** Accepted

## Context

A baseline ceiling is evidence about a measured group, not permission to
interpret every missing finding as a repair. A disabled producer, narrower
input scope, changed exclusions, unknown file metadata or incomplete analysis
can all remove a finding without removing the underlying debt. Comparing a
surviving fragment of a group can also accept a ceiling that the complete
group would breach.

The existing lifecycle has separate readers of group magnitudes and absence.
It also reads a baseline after analysis and validates the raw document again
when carrying renamed channels. These paths can disagree about the measured
set, the document or the meaning of an unavailable value.

## Decision

### One baseline-owned judgement

Baseline owns group measurement, capture, comparability, document grammar and
unused-entry audit. Its private ceiling implementation lives under `Ceiling`;
`BaselineCeilingStage` is the explicitly declared outward service. Values read
by Console or Reporting live under Baseline's `Contract`, including
`CeilingOutcome`, `RunCoverage`, `RecordedExclusions`, `BaselineDocument` and
`CurrentMeasurement`. Delivery and composition remain in Infrastructure.

`judgeAll()` supplies one `CeilingOutcome` to check, update, cleanup and
explanation. Known-entry absence is classified once. `GroupMeasurement` and
`GroupCapture` share finite-magnitude extraction; no unavailable member is
silently discarded. Occurrence channels count members regardless of a marker
value. The cumulative acceptance rule and complete entry identity remain
unchanged.

### Evidence needed for comparison

The immutable, baseline-specific `RunCoverage` combines the captured scope,
analysis coverage, current exclusion definition, project universe, optional
metadata snapshot, PSR-4 roots and the existing Run-owned project-tree query.
It adds neither another filesystem traversal nor a general invocation context.
Present, absent and unknown file metadata retain their distinct meanings.

Measurement declares whether a metric depends on its members or the whole
run. Derived metrics use member reach; global metrics use run reach. Computed
metrics own transitive formula reach at the requested level. Finding exposes
that reach by channel and level; producers without judged metrics explicitly
declare run reach. A project-level subject always needs the whole region.

A member declaration or file uses its exact file region. A namespace uses
compatible PSR-4 roots, including autoload-dev; an unavailable placement or an
observed member outside it requires the whole region. A logical class without
one exact declaration file also requires the whole region.

For complete analysis, the region's intersection with current input paths
must equal its intersection with recorded paths. An analyzed, present exact
file can establish this without Composer metadata. Equal path sets establish
equal intersections even for a whole region without a Composer roster.
Equal canonical exclusion definitions establish an empty exclusion delta
without requesting a snapshot. Changed definitions require evidence that the
delta does not intersect the region; unknown or incomplete metadata cannot
prove that a broad delta is empty. A complete project-tree snapshot enumerates
only the Composer denominator. It can expose population differences but cannot
prove equality for a whole region across changed definitions; namespace equality
also requires positive denominator coverage of every namespace root. This avoids
calling omitted explicit analysis paths stale without a new project-tree query.
A generated-policy change conservatively
affects every region wider than a file, because metadata alone cannot identify
every generated marker when generated files were included.

An incomplete analysis takes priority over quantitative judgement: present
groups remain diagnostic findings at their original severity, and absent
entries are unmeasured. Neither acceptance, promotion nor staleness follows.
Lifecycle commands retain their existing exit 4 refusal before mutation.
With complete analysis, an explicit suppress-mode entry still waives the
quantitative comparison after identity applicability is established.

### Recorded definition and one document read

Version 14 requires `exclusions: {patterns, generated}` beside the existing
envelope fields. `RecordedExclusions` has no implicit or nullable definition.
Baseline construction and generation require it explicitly; copies preserve
the original definition. Load takes the file's definition and capture takes
the resolved run configuration. Unknown envelope, entry, edge or exclusion
keys refuse with their exact position; an invalid value of a known entry key
remains a raw-preserving inert entry. An undeclared subject level and the
baseline audit channel are inert rather than quantitative coverage gaps.

Check, update, cleanup, explain and rename-channels preflight the document
before analysis or carry. `BaselineDocument` retains one byte snapshot and
its hash. Grammar is judged first; channel and level semantics follow current
configuration. The canonical recognizer may decline to the same full-document
reader over those retained bytes, never to another grammar. Rename-channels
carries the raw document without building a Baseline. Existing Core file-target
publication, mode preservation, locking and compare-and-swap remain unchanged.

### Publication, explanation and updates

A complete, comparable missing group is stale. An absent incomparable entry
is outside coverage; an absent unselected producer is unmeasured. A present
incomparable entry remains reported with its accepted level and an explicit
reason. JSON and HTML publish `baselineVerdict: breached|not-compared` and
`baselineReason`; an accepted level alone does not imply a breach.

The warning channel `baseline.unused-entry` audits stale and inert entries at
project level after ceiling judgement and before Git projection. Its findings
never enter the measured set, capture or accept-new. Authored path/namespace
suppression and Git scope cannot hide the audit. When its producer is not
selected, stderr retains a count rather than publishing findings. The channel
uses the existing finding formats and exit policy; metrics, health and
suppressed reports keep their own presentation subjects.

Explanation reports current measurement independently for each boundary,
including when no baseline exists or an entry is inert. It distinguishes
reported, nothing reported, not measured, outside coverage, not compared and
level not reported. Known entries read the shared outcome; a requested
identity without an entry may use one direct classification path.

Ordinary update preserves recorded scope and exclusions. Equal payload is
unchanged; only a stricter accepted payload is updated. `--accept-new=channel`
adds comparable measured identities of explicitly named channels, preserving
existing normalized accepted payloads, modes and inert entries. Exact entry
bytes are guaranteed only for canonical writer-produced input. Undeclared,
configuration-error and audit channels refuse before analysis.

`--record-exclusions` requires equal current and recorded path sets, even with
`--force`. It records the complete new definition and recaptures only entries
whose sole comparison obstacle is the exclusion delta, preserving their mode.
Unavailable required groups or an unknown delta refuse the write. The two
update options cannot be combined.

These are two explicit exceptions to the ordinary-update rules of
[ADR 0017](0017-baseline-ceiling.md). `--accept-new` may add identities only
for named channels and does not tighten existing entries. `--record-exclusions`
may recapture the affected groups under a deliberately changed definition,
without requiring their old and new magnitudes to form a tightening. Ordinary
update retains the stricter-only rule. Combining additive migration with
tightening, silently replacing exclusions, overloading `--force`, and using
`generate --force` instead are rejected: each hides the acceptance decision or
loses previously tightened ceilings and suppress modes.

### Alternatives rejected

Subject-name prefixes do not establish measurement coverage. Requiring every
PHP file in a namespace region to be analyzed would invalidate stable authored
exclusions. Refusing all narrow baseline checks would break hook use. An
analyzed-file list alone cannot distinguish a removed file from an excluded
one; an autoload universe misses existing files outside its targets; a roster
limited to traversed paths cannot prove removal outside a narrow run.

Separate absence calculations in projection and lifecycle are rejected because
they can classify different measured sets. An undeclared level is inert, not
stale: a producer that cannot report it gives no evidence of repair. Dropping
nonfinite members before capture invents a group the ceiling cannot compare.
Inferring a layer-violation's source file from its current projected subject is
also rejected; its source cannot be recovered from the stored occurrence hash,
so that channel conservatively retains run reach.

An unused entry is debt in acceptance configuration, not a configuration-error
channel. A separate report section, dedicated failure flag or split stale/inert
channels would create parallel reporting and exit policy. Existing suppression
debt can be accepted before the ceiling; this audit is emitted after it. An
undeclared audit channel or a new declaration flag would add another authority
without replacing the pipeline boundary. Unknown keys cannot merely warn or
become inert: that would allow a misspelled file grammar to look accepted.
Holding a decoded tree or reading the path twice is rejected in favor of one
retained document snapshot and the existing canonical recognizer.

## Consequences

Incomplete or narrow analysis can expose findings that an older ceiling hid.
This includes narrow hook checks on run-dependent channels: Git reporting
scopes publication after the full ceiling operation, not its evidence.
Missing findings no longer automatically count as resolved entries.

Consumers must migrate version 13 files by retaining their entries, scope and
generated time while explicitly recording the exclusions and generated policy
under which those entries were accepted. A new configuration is not evidence
of an old acceptance definition. Version 14 is the only current loadable
format; older formats retain their historical refusal rather than acquiring
an automatic converter.

The common outcome and narrow outward values add explicit evidence to callers
without creating a shared runtime store, new Run port or alternate writer.
Metadata remains conservative: unknown state can prevent comparison and is
reported as such. Canonical entry byte stability does not extend to arbitrary
human JSON spelling. A single local measurement on identical 185,517-entry inputs compared the old
load(path) with preflight(path) plus configured load(document). Canonical input
changed from 1.302083 to 1.443789 seconds and peak memory from 181,452,800 to
205,586,432 bytes: 24,133,632 additional bytes, roughly 23 MiB of retained raw
content. Pretty input changed from 45.362897 to 90.528244 seconds with the same
436,125,696-byte peak. These are single samples, not statistical or portable
performance promises. Acquisition happens once; grammar and semantic validation
still walk the retained document separately in memory.

A green finding-equivalence gate proves agreement only on its captured corpus
and declared surface changes. It does not prove comparability for removed files,
changed exclusions or unknown metadata, early grammar refusal in every reader,
not-compared accepted levels, or the two explicit update modes. Their owning
product regressions must establish these behaviors independently; gate color
is not a substitute for those tests.
