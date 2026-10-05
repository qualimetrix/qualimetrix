# Baseline Policy

## Overview

The Baseline policy owns versioned snapshots of accepted findings, their
fail-safe application as ceilings, and the generate, update, cleanup,
rename-channels and explain operations. Inline source controls are a separate peer policy under
`Analysis/Policy/Inline`; this capability consumes their Finding-owned contract
values only where an effective boundary must be explained.

## Structure

```
Baseline/
├── Baseline.php                 # VO: a loaded/captured file (generated, scope, entries, inert entries)
├── BaselineFormatVersion.php    # The current file format's version number, read by the loader, the writer, and the carry that never builds a Baseline
├── BaselineIdentity.php         # VO: what an entry is about — symbol + channel + dependency edge
├── BaselineEdge.php             # VO: the dependency edge half of an identity
├── BaselineEntry.php            # VO: one accepted group (identity, magnitudes, count, mode)
├── BaselineEntryMode.php        # Enum: the optional `mode` (only `suppress`)
├── EntrySelector.php            # VO: the short handle addressing one entry
├── InertBaselineEntry.php       # VO: an entry that cannot be applied, and why
├── InertEntryReason.php         # Enum: why an entry is inert
├── BaselineEntryParser.php      # Parses one raw entry into a valid or inert entry
├── BaselineEntryValues.php      # Strict count/magnitudes/mode value decoding for one entry
├── BaselineEntryRejection.php   # Internal control-flow signal used by the parser
├── BaselineConflictException.php # The file changed between read and write
├── BaselineGenerator.php        # Captures a run's findings as entries (injected clock)
├── BaselineCapture.php          # VO/factory: baseline plus materialized rejected-group outcomes
├── UncapturedGroup.php          # VO: a group that produced no entry, and why
├── UncapturedReason.php         # Enum: undeclared / configuration-error channel / no finite magnitude
├── BaselineFileShape.php        # Shared closed document grammar
├── BaselineExclusionShape.php   # Closed recorded-exclusion grammar and indexed refusals
├── BaselineEntryShape.php       # Closed raw entry and edge keys before value normalization
├── GroupAcceptance.php          # Acceptance policy over complete groups
├── BaselineLoader.php           # Loads the exact typed-subject version 14 file; envelope failures throw ConfigurationRefusal (Analysis/Configuration)
├── CanonicalBaselineReader.php  # Reads the held canonical bytes, or declines to the full-document decoder
├── CanonicalEnvelope.php        # Pure canonical envelope-line recognition and depth-bounded decoding
├── BaselineWriter.php           # Turns a Baseline into the document's fields, and refuses two entries of one identity
├── BaselineDocumentLayout.php   # How a baseline document is spelled: one entry per line, float representation pinned
├── BaselineDocumentWriter.php   # How a baseline file is replaced: sibling lock, compare-and-swap, atomic rename, the snapshot a forced replacement compares; an unusable path throws Core FileTargetFailure
├── BaselineEntryOrder.php       # Where an entry sorts among its siblings, computed identically by the writer and the carry
├── BaselineEntryPayload.php     # One entry line as the file spells it: the identity and ordering the document alone decides, built from the real types
├── RunScope.php                 # VO: a run's analysed paths in the portable form the file records, plus the coverage predicate the scope guard reads
├── RunRuleCoverage.php          # Classifies whether an identity's producer did not run or its stored level is no longer declared
├── RunCoverageGap.php           # Enum: producer/publication coverage gaps
│
├── BaselineUpdater.php          # `baseline:update`: direction-aware monotonic tightening
├── BaselineEntryTightening.php  # Reconciles one existing entry against the whole ceiling outcome
├── NewIdentityAcceptance.php    # Captures complete comparable identities of explicitly selected channels
├── BaselineUpdateResult.php     # VO: the updated baseline, one outcome per entry, and whether anything actually changed
├── BaselineEntryUpdateOutcome.php # VO: what update did to one entry, and why
├── BaselineUpdateDisposition.php  # Enum: updated / unchanged / refused / skipped / accepted / re-recorded
├── BaselineUpdateRefusalReason.php # Enum: why update refused to tighten an entry
│
├── BaselineCleaner.php          # `baseline:cleanup`: candidate enumeration and selector removal
├── BaselineCleanupCandidate.php # VO: one removal candidate — selector, description, reason
├── BaselineCleanupReason.php    # Enum: stale / producer did not run / level not declared / channel no longer declared / configuration-error channel / inert
├── BaselineCleanupRemoval.php   # VO: what one `--remove` run did — removed/not-found/ambiguous
│
├── BaselineChannelRenamer.php   # `baseline:rename-channels`: carries a raw document onto renamed channels, analysing nothing
├── ChannelRenameMap.php         # VO: the declared old -> new map, read from the gate's channels.tsv format
├── ChannelRenameReport.php      # VO: what a carry did — entries moved, idle rows, lines this build cannot read
├── ChannelRenameRefusal.php     # A carry the product understood and declined; the file is left byte-identical
│
├── BoundaryExplanationService.php # `baseline:explain`: assembles the explained identities and sources
├── CurrentBoundaryMeasurement.php # Projects independent current evidence and the whole ceiling verdict
├── CurrentAbsentMeasurement.php # Proves file or aggregate absence only for unrecorded identities
├── IdentityBoundaryExplanation.php # Joins one identity with its configured threshold and annotation
├── ExplainedSubject.php         # What the run knows about the explained symbol: its relevant identities, its typed repository index, its exact subject
├── EffectiveBoundary.php        # VO: one identity's boundary — mandatory current measurement plus nullable baseline, threshold and annotation
├── EffectiveBoundaryBaselineSource.php # VO: the baseline half of an EffectiveBoundary — inert state, mode, acceptance and comparison verdict/reason
├── BoundaryExplanation.php      # VO: every boundary bearing on one symbol, plus the subject's lines whose identity could not be read — what the command prints
├── BoundaryExplanationStatus.php # Current, baseline-only, or unknown symbol classification
│
├── Ceiling/
│   ├── BaselineCeilingStage.php # FindingFilterStageInterface: applies entries as ceilings over groups
│   ├── Absence.php              # Proven absence classification
│   ├── EntryComparability.php   # Full-group comparison evidence
│   ├── EntryJudgement.php       # Present-group acceptance and absent-entry classification
│   ├── ExclusionDelta.php       # Changed discovery definition evidence
│   ├── GroupCapture.php         # Complete finite-vector capture
│   ├── GroupMeasurement.php     # Declared group measurement
│   ├── IncomparabilityReason.php # Why a group cannot be compared
│   ├── Region.php               # File or whole comparison region
│   ├── SubjectRegion.php        # Exact subject placement
│   └── GroupCeilingVerdict.php  # VO: accepted / measured breach / reported, for one group
├── EntryBinding/
│   ├── UnusedEntryAudit.php     # Post-ceiling project audit
│   ├── UnusedEntryOptions.php   # Audit enablement
│   └── UnusedEntryRule.php      # Declared warning channel metadata
└── Contract/
    ├── BaselineAuditChannels.php # Audit channel identity
    ├── BaselineDocument.php     # Immutable bytes and target provenance
    ├── CurrentMeasurement.php   # Independent explain-now evidence
    ├── RecordedExclusions.php   # Explicit stored discovery definition
    ├── RunCoverage.php          # Baseline-specific invocation evidence
    └── CeilingOutcome.php      # VO: the ceiling result with stale and inert entries from one measured set
```

## Baseline Workflow

```
Findings -> BaselineGenerator -> BaselineCapture -> BaselineWriter -> JSON file
                                    |          `-> uncaptured groups -> reported
JSON file -> BaselineLoader -> Baseline -> BaselineCeilingStage -> Findings
                                                                   (accepted dropped,
                                                                    breaches promoted)
```

The stage runs **fourth** in Reporting's finding-projection sequence, after `@qmx-ignore` and the
`suppress_paths` / `suppress_namespaces` filters; `UnusedEntryAudit` follows ceiling judgement, then optional annotation
rejoin and Git scope. That
position is what gives the run a single measured set: suppression is per line while an
identity spans a file or a class, so a baseline placed first would judge *n* findings
where capture recorded *n−1*. A consequence worth stating: a hand-written `@qmx-ignore`
now outranks a generated entry, and an excluded finding is neither captured nor judged —
except on `architecture.*` channels, which `suppress_namespaces` does not apply to at all
and which therefore reach the baseline even inside an excluded namespace.

Two kinds of group never become an entry: one on a channel no rule declares, and a
`magnitude` group where some member reports no finite number. Both are the fail-safe
direction — an entry that could not be applied would be reported as inert forever while
suppressing nothing — but the refusal is **returned** in `BaselineCapture::$uncaptured`
and named in the output. A dropped group is written nowhere, so nothing downstream could
report it otherwise, and "Baseline with 0 entries written" would read as success.

**Version history:**
- **Version 14**: Required recorded discovery exclusions and closed document grammar
- **Version 2**: Introduced canonical symbol path keys
- **Version 3**: Rule naming scheme update (`group.rule-name` format)
- **Version 4**: 16-char finding hashes (was 8-char in v3)
- **Version 5**: Relative file paths in canonical keys (no path resolution needed)
- **Version 10**: Entries record accepted magnitudes under logical symbol keys
- **Version 11**: Identity uses exact typed subjects and may include semantic
  occurrence, dependency target, and dependency type
- **Version 12**: A `magnitude`-shaped entry no longer stores `count` (derived
  from the magnitude list's length instead), and the semantic occurrence key
  is 16 hex characters instead of 64
- **Version 13**: A declaration key carries an assigned ordinal instead of a
  byte offset, so editing text above a declaration no longer moves its key

An ordinal is a rank, so the three identities that carry a non-trivial one — a
closure, the members of an anonymous class, and a declaration sharing its logical
identity with another in the same file (ADR 0026) — move when the siblings they
are counted against change. Such an entry does not become stale: the vacated rank
is reused, so its acceptance silently rebinds to whatever holds that number now.

Only version 14 is loadable. For a v13 file, retain entries, scope and generated
time, change version to 14 and explicitly add the original discovery exclusion
definition. Do not infer an old definition from new configuration. Earlier formats
also require deliberate identity migration; regenerating is a new acceptance.

## Parsing, Capture, and Explanation Boundaries

| Owner                        | Typed input and output                                                                                                                | Responsibility and invariant                                                                                                                                                                                                                                                                                                                                                                                                                                   | Focused contract                                         |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| `BaselineEntryParser`        | `parse(string, mixed): BaselineEntry\|InertBaselineEntry`                                                                             | Reads the outer JSON object and exact identity/occurrence/edge, delegates count/magnitudes/mode to `BaselineEntryValues`, validates channel declaration shape, and demotes known-key semantic/value rejections into raw-preserving inert entries; unknown keys refuse in preflight. Known identities retain their exact selector; unknown identities receive a deterministic raw selector.                                                                     | `BaselineEntryParserTest`, `BaselineWorkflowTest`        |
| `BaselineEntryValues`        | `decode(array): BaselineEntryValues` exposing readonly `count`, `?list<int\|float> magnitudes`, and `?BaselineEntryMode mode`         | Owns only strict JSON value decoding. `count` is required and must be an integer for an occurrence-shaped entry, and is rejected as malformed when it appears (non-null) alongside `magnitudes`; it also rejects non-list/empty/non-numeric magnitudes and unknown modes, with the parser's existing reason/detail. `BaselineEntry` remains the owner of positive count, finite values, and count/list agreement.                                              | `BaselineEntryValuesTest`, `BaselineEntryParserTest`     |
| `BaselineGenerator`          | `generate(list<Finding>, list<string>, RecordedExclusions): BaselineCapture`                                                          | Groups once by complete `BaselineIdentity`, preserves first-seen group/refusal order, asks the channel registry only while capturing a group, and reads the injected clock exactly once after grouping. It passes typed rejected records to `BaselineCapture::fromRejectedGroups`, which alone materializes `UncapturedGroup`. Occurrence is identified by the declaration's null direction; magnitude groups require one finite number per member.            | `BaselineGeneratorTest`, `BaselineWorkflowTest`          |
| `ExplainedSubject`           | `identities()`, `index()`, `recordFor()`, `subjectFor()` over baseline, measured findings and an optional `MetricRepositoryInterface` | Answers which identities bear on the requested symbol and which exact subject and location the run measured for it. Builds one typed repository index from declarations, callables, logical classes, and aggregate rows. Measured evidence wins over the repository; a logical projection invents no declaration subject. Static because the answer is a pure function of the run data handed in.                                                              | `BoundaryExplanationServiceTest`, `BaselineWorkflowTest` |
| `BoundaryExplanationService` | measured findings, threshold maps, declarations, RunCoverage, optional `MetricRepositoryInterface` -> `BoundaryExplanation`           | Turns the identities and subject `ExplainedSubject` resolved into boundaries: status, independent CurrentMeasurement, baseline source, configured threshold and annotation. Annotation matching requires the exact subject and `ThresholdOverride::matches()`; highest control specificity wins, then smallest finite span, then first extraction on a tie. Baseline, configured, and annotation sources stay independently nullable and zero remains a value. | `BoundaryExplanationServiceTest`, `BaselineWorkflowTest` |

These owners retain only their subject dependencies: baseline and capture VOs,
channel declarations and the clock, or repository/subject/path and Finding-owned
override VOs. They add no public option, output shape, compatibility shim, or
second definition of identity, matching, or precedence.

## Applying a Baseline: the Ceiling

Comparison requires complete analysis and compatible evidence for the whole
identity group. Baseline-owned `RunCoverage` combines current paths, recorded
paths/exclusions, `AnalysisCoverage`, metadata and subject-region evidence.
Exact files can be judged without a complete Composer roster; namespaces and
run-dependent channels require their wider region. Unknown metadata does not
mean absence. Equal path sets and exclusion definitions can establish equality
without a metadata scan; changed definitions require evidence about their delta.
A project subject always requires whole-region coverage. A complete project-tree snapshot covers only the
Composer denominator, not every explicitly analyzed path. When path or exclusion
definitions differ, a whole region remains not-compared unless other evidence
establishes equality; a namespace needs positive denominator coverage of all
its roots before a snapshot can establish that its population is unchanged.

A complete comparable missing group is **stale**. An absent unselected producer
is **unmeasured**; an absent incomparable entry is **outside coverage**. A present
incomparable group is **not-compared** and keeps its own severity, accepted level
and reason. Incomplete analysis never establishes acceptance, breach or staleness.
One nonfinite magnitude makes the entire group's magnitude vector unavailable;
no finite fragment is compared or captured. Occurrence groups count all members.
A comparable breach promotes every group member to Error. Suppress mode waives
quantitative comparison after identity applicability is established.

`EntryBinding\UnusedEntryAudit` emits `baseline.unused-entry` project-level
Warnings for stale and inert entries after the full ceiling and before Git
projection. The rule's remediation estimate is 5 minutes. Audit findings never
enter the measured set, capture or accept-new; authored path/namespace
suppression and Git projection cannot hide them. When unselected, stderr reports
counts only. Uncompared entries likewise produce count diagnostics, not path dumps.
Nine finding formats publish the audit; Metrics, Health and Suppressed retain
their own subjects. All twelve preserve the ordinary failure policy: an isolated
audit warning exits 0 by default, with `--fail-on=error` or `none`, and 1 with
`--fail-on=warning`. Incomplete analysis has priority and exits 4.

## The Writing Commands' Domain Services

`baseline:update` and `baseline:cleanup` are pure domain logic here — no
`symfony/console` dependency anywhere in this directory. The command classes
that call these services (Infrastructure, a later package) own argument
parsing, the scope-guard refusal message, and writing the result through
`BaselineWriter`.

### Intentionally excluded empty input

An authored exclusion can remove a written file or directory without an input
refusal. `analyzed=0`, `failed=0`, `excluded + generatedExcluded > 0` identifies
a complete intentionally empty result. `baseline:generate` writes an empty file
and exits 0 with the measured scope explanation on stderr. Update/cleanup retain
their existing complete-run and recorded-scope checks; `--force` still has only
its existing meaning. Explain returns 0 after the scope observation without
claiming the requested subject was remediated. Any incomplete input wins with
exit 4 before semantic classification or destination mutation.

Full-universe metadata participates in the shared RunCoverage judgement.
Exclusion or unknown presence is not remediation; unknown/outside entries remain
retained with an explicit reason.

### The scope guard

Writing operations retain their recorded-scope preconditions. `--force` may
bypass the ordinary scope guard, but cannot establish comparability or make
incomplete evidence acceptable. `--record-exclusions` always requires exact
recorded paths. Full ceiling judgement precedes report Git scope and hook
projection; narrow run-dependent channels can therefore become not-compared.

### `BaselineUpdater` — direction-aware monotonic tightening

Ordinary update only tightens existing accepted groups. It preserves recorded
scope, exclusions, inert payload and modes. Equal acceptance is `unchanged`;
a no-op does not publish, change generated time or acquire a writer lock.
Absent or incomparable groups are retained rather than converted into zero.

`--accept-new=channel` is repeatable and additive: only new complete comparable
measured identities of named selected channels are admitted. Existing caps are
not tightened by this mode. Exact channel admission follows configuration and
precedes analysis; undeclared, wildcard, level-qualified, configuration-error
and `baseline.unused-entry` names refuse with exit 3.

`--record-exclusions` requires exactly the recorded paths even with `--force`.
It records the complete current exclusion definition and recaptures only groups
whose sole comparison obstacle is the exclusion change, preserving modes.
Other entries follow ordinary tightening. Unknown delta, changed generated
policy without sufficient proof, incomplete analysis or unavailable required
groups refuses the whole write. The options cannot combine.

Normalized accepted payload is preserved for arbitrary human JSON input.
Exact unchanged entry bytes are guaranteed only for canonical writer-produced
entries; arbitrary field order and numeric spelling may be normalized.

### `BaselineCleaner` — candidate enumeration and selector removal

Cleanup lists reviewed removal candidates without writing by default. A stale
candidate requires complete comparable absence; an unmeasured/outside entry is
retained and named as such. Unknown channel, undeclared level, malformed payload
and audit entries are inert. Repeated `--remove=<selector>` removes exactly the
reviewed entries; no bulk removal infers remediation from missing findings.
Incomplete analysis exits 4 before classification or mutation.

## Explaining a Boundary

`BoundaryExplanationService` answers one `EffectiveBoundary` per identity.
Its mandatory `Contract\CurrentMeasurement now` is independent of its nullable
baseline source, configured threshold and inline override. No baseline or an
inert entry can still have a current measured group. Known valid entries read
one full `CeilingOutcome`; stored acceptance does not cause a second absence
classification or synthetic baseline.

The renderer prints separate baseline and now lines. Current states are
`reported`, `nothing-reported`, `not-measured`, `outside-coverage`,
`not-compared` and `level-not-reported`. Undeclared subject levels are identified
before coverage classification, with the currently declared levels. Current
channel declarations determine shape; an inert payload does not. Unknown
channels retain an unknown shape. A nonfinite magnitude group retains its total
count and count without a finite magnitude, but no partial magnitude vector.
Accepted caps, suppress mode and inert reason remain on the baseline source.
Unreadable identities are listed separately. Exact subject/repository evidence
continues to own annotation binding; logical identity never invents a declaration.

## Entry Identity

An entry is about an **identity**: the symbol, the channel (its own name),
and — when the finding carries one — the dependency edge (target plus reference kind).
The set of findings in a run sharing one identity is that entry's **group**.

**Deliberately excluded** (for stability across refactoring):
- Line number (shifts when code is added above)
- Method parameters (renaming should not invalidate baseline)
- Message text (rewording should not invalidate baseline)
- Severity (may change when thresholds are reconfigured)

Declaration subjects retain the declaration file and the assigned ordinal, so two
declarations of one FQN in one file are separate groups. Logical class and aggregate subjects
remain their own typed identities. Optional semantic occurrence and dependency edge
(target plus reference kind) participate in the same complete identity.

### Entry selector

Every entry is addressable by a **selector** — 12 lowercase hexadecimal characters,
the truncated SHA-256 of the complete identity. It is printed next to an entry so a
user copies rather than composes it. `<symbol>#<channel>` cannot serve: `#` already
separates the two halves of a channel key, and two forbidden edges out of one class on
one channel agree on everything else.

## File Contract (version 14)

The file is one JSON document, written in a canonical layout: **one entry per
line**, two-space indentation, a subject key on the line above the entries it
owns.

```json
{
  "version": 14,
  "generated": "2026-08-05T12:00:00+03:00",
  "scope": ["src"],
  "exclusions": {"patterns": [], "generated": "excluded"},
  "entries": {
    "declaration:callable:App\\OrderService::calculate@src/OrderService.php": [
      {"channel":"complexity.ccn","occurrence":"bd41b8a3f6cad9e1","magnitudes":[25]}
    ],
    "declaration:class:App\\Widget@src/Widget.php": [
      {"channel":"code-smell.unused-private","magnitudes":[3,3,3]}
    ],
    "class:App\\Web\\Controller": [
      {"channel":"architecture.layer-violation","edge":{"target":"class:App\\Db\\Connection","type":"new"},"count":1}
    ]
  }
}
```

The layout is the schema's presentation, not part of it: the file is ordinary
JSON, and a reformatted copy still loads. What the layout buys is that an entry
is the unit of acceptance *and* the unit of diff — tightening one ceiling is a
one-line change with the subject key visible above it — and that the file is
two thirds the size `JSON_PRETTY_PRINT` produced for the same entries. Version
12 shrinks it further, by 7.2% on this repository's own 264-entry baseline:
`count` is not written for a `magnitude` entry (it is redundant with the
magnitude list's length), and the semantic `occurrence` key is 16 hex
characters instead of 64 (its discrimination domain is one (subject, channel)
pair, not the whole file).

| Field        | Contract                                                                                                                          |
| ------------ | --------------------------------------------------------------------------------------------------------------------------------- |
| `version`    | Exactly `14`                                                                                                                      |
| `generated`  | ISO 8601, from an injected clock (`Core\Time\ClockInterface`)                                                                     |
| `exclusions` | Required `{patterns, generated}`; explicit `exact:`, `subtree:` or `regex:` selectors, and `included`/`excluded` generated policy |
| `scope`      | The analysed path set that produced this file, normalized                                                                         |
| `entries`    | Canonical symbol keys → deterministic entry lists                                                                                 |

Entry invariants:

- `count` is a positive integer. It is present for an `occurrence`-shaped
  entry, and **absent** for a `magnitude`-shaped entry — there it is derived
  from the length of `magnitudes` on load, and a file that writes both is
  rejected as malformed rather than trusted to agree with itself.
- `magnitudes` holds exactly `count` finite numbers and is present exactly for
  channels declared `magnitude`; it is absent for `occurrence` channels. Each value is
  `round($v, 6)` and `-0.0` normalizes to `0`. The list is stored ascending — a
  determinism convention only, since the comparison counts members per severity level
  and never reads it positionally.
- `occurrence` is optional and distinguishes semantic occurrences of the same
  channel. It is 16 lowercase hex characters — a truncated SHA-256 of the
  semantic evidence, via {@see \Qualimetrix\Analysis\Finding\Contract\OccurrenceKey}
  — not the full digest: the domain it distinguishes within is one (subject,
  channel) pair, units of members, so 64 bits of headroom is far more than it
  needs. This value also feeds `Finding::getFingerprint()`, so shortening it
  is a breaking change to the GitLab Code Quality `fingerprint` and the SARIF
  `partialFingerprints.primaryLocationLineHash` it derives — every previously
  computed fingerprint changes.
- `edge` is present exactly when the finding carries a dependency target; target and
  optional dependency type are part of the selector-bearing identity.
- `mode` is optional; `suppress` is the only recognized value.

Entries under one symbol key sort by channel and then by edge, **whatever their state** —
an entry that happens to be inert in the writing process sorts exactly where it would if
it were applicable. Only an entry whose channel could not be read at all has nothing to
sort on; those follow, ordered by selector. Order therefore does not depend on which
configuration produced the file: applicability is not a stable fact about an entry — a
different `--preset`, a different `--config`, or a run with `computed_metrics:` absent
can each change whether a `computed.*` entry resolves as applicable or inert from one
invocation to the next — so a valid-block-then-inert-block layout would move those lines
whenever that changed.

Everything except `generated` is deterministic for the same analysis. The writer pins
the float representation at the encode site (`serialize_precision=-1` for the duration
of the encode), so the same analysis produces byte-identical files whatever the
reader's ini says — six-decimal normalization alone would not do it, since `0.1` has no
exact binary form and prints as `0.10000000000000001` at `serialize_precision=17`. A
normalized `40.0` is written as `40` and reloads as an `int`, which is harmless for a
numeric comparison and stable from the first write.

### Entries that cannot be applied

A malformed entry, an undeclared channel, a channel that reports a configuration
error, a shape mismatch in either direction, an unrecognized `mode`, a component
carrying the identity key separator, or a duplicated identity makes an entry **inert**: it does not
suppress, and it does not fail the load — refusing to load would punish a whole run
for one bad line. An inert entry keeps its symbol, channel, selector and reason for
reporting, and its raw payload so a rewrite preserves its normalized contents.

### Reads

`BaselineLoader::preflight()` physically acquires one immutable
`Contract\BaselineDocument` before analysis for check, update, cleanup, explain
and rename-channels. `BaselineFileShape` judges closed document grammar, including
unknown envelope, entry, edge and exclusion keys, before configured semantics.
`load(document)` resolves channel and level semantics against the configured run.
The canonical recognizer scans held bytes line by line; a declined layout uses
full-document decoding over those same bytes. No second path acquisition or
alternate grammar is introduced. The snapshot's target and content hash remain
the publication/CAS provenance.

A single local measurement on identical 185,517-entry inputs compared the old
load(path) with preflight(path) plus configured load(document). Canonical input
changed from 1.302083 to 1.443789 seconds and peak memory from 181,452,800 to
205,586,432 bytes: 24,133,632 additional bytes, roughly 23 MiB of retained raw
content. Pretty input changed from 45.362897 to 90.528244 seconds with the same
436,125,696-byte peak. These are single samples, not statistical or portable
performance promises. Acquisition happens once; grammar and semantic validation
still walk the retained document separately in memory.

### Writes

`BaselineWriter` decides what a `Baseline` serializes to; `BaselineDocumentLayout`
decides how that document is spelled and `BaselineDocumentWriter` how the file is
replaced. The split is not decoration: `BaselineChannelRenamer` renders and replaces
the same document without ever building a `Baseline`, and a second spelling of either
half would show up as an unexplained diff in a user's baseline rather than as a
failing test. `BaselineEntryOrder` is shared for the same reason.

`BaselineDocumentWriter` writes to a temporary file and renames. A sibling `<baseline>.lock`
file (worth adding to `.gitignore`) holds an exclusive lock across both the
content-hash check and the rename, so a read-modify-write cannot silently discard a
concurrent writer: a `Baseline` loaded from a file carries that file's content hash,
and writing it back to a file that no longer matches raises
`BaselineConflictException`. A command that observed an absent target carries that
expectation explicitly and is likewise refused if a file appears before the locked
check. The provenance is a property of the guard, never a field of the file.
`write()` returns the token for the bytes it wrote, and
`Baseline::withSourceContentHash()` carries it back — without which a caller writing one
instance twice would be refused by its own first write.
`BaselineWriter::destinationSnapshot()` returns `{target: ResolvedTarget, hash:
?string}` before analysis, including an explicitly absent target. Pass that target
as the second argument of `write()`; the writer does not resolve an independent
string again. Existing targets must be readable and claimable without truncation.
The parent directory must already exist and permit sibling publication: create it
before invoking the command. Replacement preserves an existing file's mode.
Closed symbolic links retain their own entry and publish their resolved referent;
exposed links refuse through Core. An occupied name still needs `--force` when
generating, including a dangling link. Update and cleanup keep the loaded content
hash as their compare-and-swap expectation.

The sibling lock is held across the identity/content check and publication. Its
wait is bounded (10 seconds by default) with monotonic `hrtime`, and its named
entry is retained after release. FileTarget failures preserve the requested path
and available system cause without printing PHP warnings; Console classifies
storage and lock contention as environment exit 3. Configuration refusal remains
the loader's response to an invalid document.

**Every entry read is an entry written.** The writer never groups entries under a key two
of them can share, because resolving such a clash by overwriting would delete a line
nobody decided to delete. Two identities that are distinct in memory but collapse onto
one symbol key once `file:` paths are made project-relative are refused outright rather
than merged.

### Carrying a baseline onto renamed channels

`baseline:rename-channels` runs no analysis and does not build a loaded Baseline.
It substitutes named channel values, preserves subject, occurrence, count,
magnitudes, mode, edge, scope, exclusions and generated payload, and uses the
shared `BaselineDocumentLayout`/`BaselineEntryOrder`. Unknown keys refuse through
the same closed `BaselineFileShape` grammar; known unusable entry values remain
carried and counted. Formatting is rendered, not promised byte-for-byte for
arbitrary human input. A newly created identity collision refuses the write;
pre-existing duplicates are retained. No renamed entries means no publication.
New channel names are validated for form, not current registry membership.

## Related Documents

- [Finding](../../Finding/README.md) — finding and filtering contracts
- [Inline policy](../Inline/README.md) — source suppressions and threshold extraction
- [Infrastructure](../../../Infrastructure/README.md) — Console adapters and filter ordering
- [Baseline usage](../../../../website/docs/usage/baseline.md) — user-facing documentation


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
