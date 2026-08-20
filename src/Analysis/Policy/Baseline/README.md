# Baseline Policy

## Overview

The Baseline policy owns versioned snapshots of accepted findings, their
fail-safe application as ceilings, and the generate, update, cleanup, migrate,
and explain operations. Inline source controls are a separate peer policy under
`Analysis/Policy/Inline`; this capability consumes their Finding-owned contract
values only where an effective boundary must be explained.

## Structure

```
Baseline/
├── Baseline.php                 # VO: a loaded/captured file (generated, scope, entries, inert entries)
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
├── BaselineLoader.php           # Loads the exact typed-subject version 11 file
├── CanonicalBaselineReader.php  # Reads the canonical one-entry-per-line layout without decoding the whole document, or declines so the loader decodes it
├── BaselineLoadException.php    # Envelope failure (missing/unreadable/invalid JSON/version); exit 3
├── BaselineWriter.php           # Writes atomically under a compare-and-swap guard
├── RunScope.php                 # VO: a run's analysed paths in the portable form the file records, plus the coverage predicate the scope guard reads
│
├── BaselineUpdater.php          # `baseline:update`: direction-aware monotonic tightening
├── BaselineUpdateResult.php     # VO: the updated baseline plus one outcome per entry
├── BaselineEntryUpdateOutcome.php # VO: what update did to one entry, and why
├── BaselineUpdateDisposition.php  # Enum: updated / refused / skipped
├── BaselineUpdateRefusalReason.php # Enum: why update refused to tighten an entry
│
├── BaselineCleaner.php          # `baseline:cleanup`: candidate enumeration and selector removal
├── BaselineCleanupCandidate.php # VO: one removal candidate — selector, description, reason
├── BaselineCleanupReason.php    # Enum: stale / channel no longer declared / inert
├── BaselineCleanupRemoval.php   # VO: what one `--remove` run did — removed/not-found/ambiguous
│
├── BaselineMigrator.php         # Historical continuity report logic for a fresh capture against v5 records; not a v11 conversion route
├── BaselineMigratorResult.php   # VO: the migrated baseline plus its MigrationReport
├── MigrationReport.php          # VO: carried/dropped/fresh pair counts, dropped entries and unreadable v5 rows enumerated in full
├── MigrationReportDroppedEntry.php # VO: one v5 (symbolKey, rule) pair the fresh capture no longer backs
├── V5Baseline.php               # VO: a parsed version 5 snapshot — read-only, never applied; holds what parsed, what did not, and its source-byte hash
├── V5UnreadableRecord.php       # VO: one v5 row that did not parse — the symbol it was listed under and what failed
├── V5Entry.php                  # VO: one v5 record — symbol, rule, and the opaque hash v5 stored instead of a magnitude
├── V5BaselineReader.php         # Reads a v5 `violations` snapshot (or checks it is one, for --force's guard) with its source-byte hash; BaselineLoader refuses version 5 outright
│
├── BoundaryExplanationService.php # `baseline:explain`: builds a BoundaryExplanation from the baseline, qmx.yaml-configured thresholds, and @qmx-threshold annotations
├── EffectiveBoundary.php        # VO: one identity's boundary — baseline source, configured threshold, annotation, each independently nullable
├── EffectiveBoundaryBaselineSource.php # VO: the baseline half of an EffectiveBoundary — the accepted level plus what the measured set currently compares against it
├── BoundaryExplanation.php      # VO: every boundary bearing on one symbol — what the command prints
├── BoundaryExplanationStatus.php # Current, baseline-only, or unknown symbol classification
│
├── Filter/
│   ├── BaselineCeilingStage.php # ViolationFilterStageInterface: applies entries as ceilings over groups
│   └── GroupCeilingVerdict.php  # VO: accepted / measured breach / reported, for one group
```

## Baseline Workflow

```
Violations -> BaselineGenerator -> BaselineCapture -> BaselineWriter -> JSON file
                                    |          `-> uncaptured groups -> reported
JSON file -> BaselineLoader -> Baseline -> BaselineCeilingStage -> Violations
                                                                   (accepted dropped,
                                                                    breaches promoted)
```

The stage runs **fourth** in Reporting's finding-projection sequence, after `@qmx-ignore` and the
`exclude_paths` / `exclude_namespaces` filters and immediately before git scope. That
position is what gives the run a single measured set: suppression is per line while an
identity spans a file or a class, so a baseline placed first would judge *n* findings
where capture recorded *n−1*. A consequence worth stating: a hand-written `@qmx-ignore`
now outranks a generated entry, and an excluded finding is neither captured nor judged —
except on `architecture.*` channels, which `exclude_namespaces` does not apply to at all
and which therefore reach the baseline even inside an excluded namespace.

Two kinds of group never become an entry: one on a channel no rule declares, and a
`magnitude` group where some member reports no finite number. Both are the fail-safe
direction — an entry that could not be applied would be reported as inert forever while
suppressing nothing — but the refusal is **returned** in `BaselineCapture::$uncaptured`
and named in the output. A dropped group is written nowhere, so nothing downstream could
report it otherwise, and "Baseline with 0 entries written" would read as success.

**Version history:**
- **Version 2**: Introduced canonical symbol path keys
- **Version 3**: Rule naming scheme update (`group.rule-name` format)
- **Version 4**: 16-char violation hashes (was 8-char in v3)
- **Version 5**: Relative file paths in canonical keys (no path resolution needed)
- **Version 10**: Entries record accepted magnitudes under logical symbol keys
- **Version 11**: Identity uses exact typed subjects and may include semantic
  occurrence, dependency target, and dependency type

Only version 11 is loadable. Versions 5 and 10 cannot supply exact declaration
identity and are rejected with guidance to run a fresh analysis, deliberately map
or split accepted entries, review the mapping, and write a version 11 baseline.

## Parsing, Capture, and Explanation Boundaries

| Owner                        | Typed input and output                                                                                                        | Responsibility and invariant                                                                                                                                                                                                                                                                                                                                                                                                                        | Focused contract                                         |
| ---------------------------- | ----------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| `BaselineEntryParser`        | `parse(string, mixed): BaselineEntry\|InertBaselineEntry`                                                                     | Reads the outer JSON object and exact identity/occurrence/edge, delegates count/magnitudes/mode to `BaselineEntryValues`, validates channel declaration shape, and converts every rejection into a raw-preserving inert entry. Known identities retain their exact v11 selector; unknown identities receive a deterministic raw selector.                                                                                                           | `BaselineEntryParserTest`, `BaselineWorkflowTest`        |
| `BaselineEntryValues`        | `decode(array): BaselineEntryValues` exposing readonly `count`, `?list<int\|float> magnitudes`, and `?BaselineEntryMode mode` | Owns only strict JSON value decoding. It rejects missing or non-integer count, non-list/non-numeric magnitudes, and unknown modes with the parser's existing reason/detail; `BaselineEntry` remains the owner of positive count, finite values, and count/list agreement.                                                                                                                                                                           | `BaselineEntryValuesTest`, `BaselineEntryParserTest`     |
| `BaselineGenerator`          | `generate(list<Violation>, list<string>): BaselineCapture`                                                                    | Groups once by complete `BaselineIdentity`, preserves first-seen group/refusal order, asks the channel registry only while capturing a group, and reads the injected clock exactly once after grouping. It passes typed rejected records to `BaselineCapture::fromRejectedGroups`, which alone materializes `UncapturedGroup`. Occurrence is identified by the declaration's null direction; magnitude groups require one finite number per member. | `BaselineGeneratorTest`, `BaselineWorkflowTest`          |
| `BoundaryExplanationService` | measured violations, threshold maps, optional `MetricRepositoryInterface` -> `BoundaryExplanation`                            | Builds one typed repository index from declarations, callables, logical classes, and aggregate rows. Measured evidence wins over the repository. Annotation matching requires the exact subject and `ThresholdOverride::matches()`; highest control specificity wins, then smallest finite span, then first extraction on a tie. Baseline, configured, and annotation sources stay independently nullable and zero remains a value.                 | `BoundaryExplanationServiceTest`, `BaselineWorkflowTest` |

These owners retain only their subject dependencies: baseline and capture VOs,
channel declarations and the clock, or repository/subject/path and Finding-owned
override VOs. They add no public option, output shape, compatibility shim, or
second definition of identity, matching, or precedence.

## Applying a Baseline: the Ceiling

An entry bounds the **group** of findings sharing its identity, and `BaselineCeilingStage`
returns one of three verdicts per group:

| Verdict             | What happens                                                                                                    |
| ------------------- | --------------------------------------------------------------------------------------------------------------- |
| **Accepted**        | Every member is removed from the report                                                                         |
| **Measured breach** | Every member is reported and **promoted to `Severity::Error`**, carrying the `AcceptedLevel` it was accepted at |
| **Reported**        | Every member is reported at the severity its own rule gave it                                                   |

**Acceptance is cumulative, never rank-paired.** A group is accepted when every current
magnitude is finite and, for every value `t`, the number of current members at least as
bad as `t` is no greater than the number of stored members at least as bad as `t` —
`>=` on a `higher` channel, `<=` on a `lower` one. On an `occurrence` channel there are
no magnitudes and one level: the group holds no more members than `count`.

Counting rather than pairing is what makes a repair safe. With `[40, 100]` stored and
the 40-line duplicate deleted, a rank comparison from the best end would measure the
surviving `100` against the vacated `40` and fail the build on code nobody touched. The
price of counting is recorded rather than hidden: a survivor may grow into a slot a
repair vacated, bounded above by the worst magnitude already accepted.

**The shape decides, not the value.** A `marker` channel emits a fixed `1.0` and
`coupling.class-rank` emits a real PageRank score; both are declared `occurrence`, and
both numbers are ignored by contract.

**An entry that cannot be applied does not suppress — and does not promote.** An
undeclared channel, a shape mismatch in either direction, a group whose magnitude is
absent or non-finite, an entry the loader turned inert, a renamed symbol: each reports
the findings unchanged. None of them is evidence that the debt got worse, so promoting
on one would fail a build over a stale file.

**A configuration error may not be accepted at all.** A channel declaring
`ChannelAcceptability::ConfigurationError` — today the layer-policy diagnostics —
reports a mistake in the configuration rather than debt in the code, so no entry bounds
it on any of the five paths: the loader refuses the line
(`InertEntryReason::ConfigurationErrorChannel`), `generate` does not capture it
(`UncapturedReason::ConfigurationErrorChannel`), `update` refuses it
(`BaselineUpdateRefusalReason::ConfigurationErrorChannel`), `cleanup` lists it for
removal (`BaselineCleanupReason::ChannelIsConfigurationError`), and the ceiling reports
the group while naming the entry inert. This is stronger than inapplicability: the
others say *this* entry cannot be applied, this one says none could be. The finding
also fails the run without consulting `fail_on`.

The invariant has no `mode` exception, so applicability is settled before `mode` is
read: `mode: suppress` waives the comparison of magnitudes and count, not the question
of whether the entry bounds that channel at all. An entry naming an undeclared channel
or disagreeing with its channel's shape does not suppress, whatever its `mode`.

**Both sides of the comparison are normalised.** The stored side is rounded to six
decimal places by `BaselineEntry`'s constructor and the recomputed side by the same
`BaselineEntry::normalizeMagnitude()`, which is what earns the comparison's zero
tolerance.

`judgeAll()` judges a whole list in one pass and returns a `CeilingOutcome` bundling
the filtered/promoted result together with the stale entries and the entries the
loader could not apply, as ADR 0017 requires — one call, one measured set, so the three cannot be read
from different lists by accident. `apply()`, required by `ViolationFilterStageInterface`,
is `judgeAll()->result`.

## The Writing Commands' Domain Services

`baseline:update` and `baseline:cleanup` are pure domain logic here — no
`symfony/console` dependency anywhere in this directory. The command classes
that call these services (Infrastructure, a later package) own argument
parsing, the scope-guard refusal message, and writing the result through
`BaselineWriter`.

### The scope guard

Before the recorded-scope guard is even constructed, `BaselineRun` requires
the analysis coverage to be complete. A parse or processing failure stops all
lifecycle commands with the dedicated analysis-failure outcome: no requested
path is recorded as proven coverage, no baseline is interpreted, no cleanup
candidate is reported, and no destination is created or mutated. `--force`
does not override this invariant; it applies only to the narrower recorded-
scope check below.

`RunScope` owns both halves of the guard: the **portable form** a run records
and the **coverage predicate** every reader of that form applies. They are one
type because they are one rule — while they lived apart, each side derived the
portable form itself and the two disagreed about the project root.

```php
$scope = RunScope::record($absolutePaths, $projectRoot);  // what a run writes
$scope = RunScope::fromRecorded($baseline->scope);        // what a file holds
$scope->paths();                                          // list<string>
$scope->covers($baseline->scope);                         // bool
$scope->uncoveredPaths($baseline->scope);                 // list<string>
```

`record()` writes each path project-relatively where it can. **A path equal to
the project root records as `.`**, never as the absolute machine path it sits
at: a baseline is a tracked file, and `/Users/<you>/...` in one both breaks
portability between checkouts and violates the repository's own rule on
absolute home paths (CLAUDE.md §10). A path genuinely *outside* the project
root has no relative form and is kept as given — the analysed tree really is
elsewhere.

Coverage is by whole path segment: `src` covers `src/Foo` but neither covers
nor is covered by `srcfoo` or by `src/Foo` itself. Two paths cover
unconditionally — `.` (every project-relative path) and `/` (everything).

Both writing commands check the predicate before doing anything else (ADR 0017):
**the current run's scope must cover the file's recorded `scope`**, overridable
with `--force`. The hazard is one-directional — a run *narrower* than the
recorded scope makes every identity outside it look absent, so `cleanup` would
offer to delete the rest of the file and `update` would silently believe
nothing changed. A *wider* run is harmless. `check` reports the same mismatch
and never fails on it.

### `BaselineUpdater` — direction-aware monotonic tightening

`BaselineUpdater::update(Baseline $baseline, list<Violation> $measured, RunScope $scope): BaselineUpdateResult`
reconciles every entry the loaded baseline holds against the run's measured
set (ADR 0017):

- an identity **absent from the measured set is left untouched** — a
  vanished group is `cleanup`'s business, not a reason to rewrite an entry;
- `update` **never adds an identity** — a measured finding with no existing
  entry is ignored;
- a measured group **replaces the stored one exactly when `GroupAcceptance`
  accepts it against the stored one** — the identical primitive
  `BaselineCeilingStage` uses at `check` time, never a second definition of
  "not more permissive". Because the cumulative rule subsumes the count
  condition (ADR 0017), a magnitude channel needs no separate "count may only
  shrink" check — the comparison already refuses a group that grew, even
  when every individual member improved.

Every other measured group is **refused** and its entry is written back
byte-for-byte unchanged — never partially adjusted, since a partial write
disguised as a refusal would be an undocumented second acceptance rule.
`BaselineUpdateResult::$outcomes` names, per entry, one of `Updated`,
`Refused` (with a `BaselineUpdateRefusalReason`: `UndeclaredChannel`,
`ConfigurationErrorChannel`, `ShapeMismatch`, `CurrentMagnitudeUnavailable`, `Worsened`,
`WorsenedUnderSuppression`) or `Skipped`. The last two are the same declined
comparison: a `mode: suppress` entry is tested like any other — otherwise
`update` would be a way to widen an acceptance — but calling its refusal
"worsened" would point a user at a red build that cannot happen, since the
ceiling never compares that entry's numbers at `check` time. `mode` and
`Baseline::$inertEntries` are carried forward verbatim; `update` does not read
either to decide anything.

**The recorded `scope` is not overwritten by a narrower run.** `update` writes
the run's own scope only when it covers what the file already records, and
otherwise keeps the recorded one. The guard above is a per-invocation
override; if one `--force` over `src/Legacy` also narrowed the file's claim,
every later narrow run would cover it and the guard would never fire again.

### `BaselineCleaner` — candidate enumeration and selector removal

`BaselineCleaner::candidates(Baseline $baseline, list<Violation> $measured, ChannelDeclarationRegistryInterface $declarations): list<BaselineCleanupCandidate>`
lists every entry `cleanup` would offer to remove — **and changes nothing**.
A valid entry is offered for `Stale` (absent from the measured set, via
`Baseline::staleEntries()`), `ChannelNotDeclared`, or
`ChannelIsConfigurationError`; an entry whose channel is no longer declared
is reported under the second even when it is also stale, since a channel
nothing declares can never produce a measured finding and the more permanent
cause is the more useful answer. The third holds even while the finding is
still being measured: a channel declaring
`ChannelAcceptability::ConfigurationError` may never be accepted by any
entry, so the entry can only be removed. Every
`InertBaselineEntry` is offered too, under `Inert`, carrying its own
`InertEntryReason` — it already has a selector, and the user is entitled to
delete an unreadable line.

`BaselineCleaner::remove(Baseline $baseline, list<EntrySelector> $selectors): BaselineCleanupRemoval`
is the only method that writes anything, and only for the selectors it is
given — **there is no bulk "remove everything listed" form**. ADR 0017's
cleanup decision rejects the withdrawn `--all-listed` shape: the candidate list is recomputed
inside the same call that would consume it, so a bulk flag would be
inference-by-absence wearing a flag). Each selector resolves through the
selector index the cleaner builds for the call into exactly one of three
outcomes — `removed`, `notFound`, or `ambiguous` (more than one entry shares
the selector; neither is removed, since the digest is not a proof of
uniqueness). A selector addresses the *complete* identity including the
dependency edge, so it can remove one of two entries differing only by edge
without touching the other.

### Historical migration report types

The retained v5 reader and migration report VOs describe historical continuity
data only. They are not a conversion route into version 11: neither a v5 nor a
v10 logical symbol key can infer the exact declaration subject now required.
Version 11 construction therefore starts from a fresh analysis and an explicit,
reviewed map or split of every acceptance.

A v5 row that never parsed into a record belongs to none of those groups, and
`V5BaselineReader` **collects rather than skips** it: `read()` returns it in
`V5Baseline::$unreadable`, and `BaselineMigrator` carries it into
`MigrationReport::$unreadableV5Records` so historical inspection can name it;
it does not make the record applicable to the current schema.

`BoundaryExplanationService` is unrelated to migration but shares this
package's "read-only against the measured set" shape: `baseline:explain`
gives it the loaded baseline, the run's violations, and configuration read
by its own command (thresholds, annotations), and it answers with one
`EffectiveBoundary` per relevant identity — never touching a file itself.

The explanation also classifies the requested symbol explicitly. `Current`
means the run measured the symbol (whether or not a rule currently fires),
`BaselineOnly` means only the baseline still names it, and `Unknown` means
neither source does. The command rejects `Unknown` as input instead of
presenting a misspelling as a clean symbol; `BaselineOnly` remains explainable
and is labelled as absent from the current scope or result.

It also takes the run's `MetricRepositoryInterface` (optional, last argument)
as exact typed-subject evidence for symbols with no current finding. Current
violations take precedence by canonical subject alone: a different semantic
occurrence or dependency edge does not change annotation ownership. The
repository then supplies the exact declaration/callable subject when needed;
logical and aggregate projections may prove that a subject is current but do
not invent a declaration subject. Without either exact source, annotation is
reported absent rather than guessed.

## Entry Identity

An entry is about an **identity**: the symbol, the channel (`ruleName#violationCode`),
and — when the finding carries one — the dependency edge (target plus reference kind).
The set of violations in a run sharing one identity is that entry's **group**.

**Deliberately excluded** (for stability across refactoring):
- Line number (shifts when code is added above)
- Method parameters (renaming should not invalidate baseline)
- Message text (rewording should not invalidate baseline)
- Severity (may change when thresholds are reconfigured)

Declaration subjects retain declaration file and start-position identity, so two
declarations of one FQN are separate groups. Logical class and aggregate subjects
remain their own typed identities. Optional semantic occurrence and dependency edge
(target plus reference kind) participate in the same complete identity.

### Entry selector

Every entry is addressable by a **selector** — 12 lowercase hexadecimal characters,
the truncated SHA-256 of the complete identity. It is printed next to an entry so a
user copies rather than composes it. `<symbol>#<channel>` cannot serve: `#` already
separates the two halves of a channel key, and two forbidden edges out of one class on
one channel agree on everything else.

## File Contract (version 11)

The file is one JSON document, written in a canonical layout: **one entry per
line**, two-space indentation, a subject key on the line above the entries it
owns.

```json
{
  "version": 11,
  "generated": "2026-08-05T12:00:00+03:00",
  "scope": ["src"],
  "entries": {
    "declaration:callable:App\\OrderService::calculate@src/OrderService.php:0": [
      {"channel":"complexity.cyclomatic#complexity.cyclomatic.callable","occurrence":"body","magnitudes":[25],"count":1}
    ],
    "file:src/Legacy/dup.php": [
      {"channel":"duplication.code-duplication#duplication.code-duplication","magnitudes":[40,100],"count":2}
    ],
    "class:App\\Web\\Controller": [
      {"channel":"architecture.layer-violation#architecture.layer-violation","edge":{"target":"class:App\\Db\\Connection","type":"new"},"count":1}
    ]
  }
}
```

The layout is the schema's presentation, not part of it: the file is ordinary
JSON, and a reformatted copy still loads. What the layout buys is that an entry
is the unit of acceptance *and* the unit of diff — tightening one ceiling is a
one-line change with the subject key visible above it — and that the file is
two thirds the size `JSON_PRETTY_PRINT` produced for the same entries (60 401 B
against 90 365 B on this repository's own baseline, at 264 entries).

| Field       | Contract                                                      |
| ----------- | ------------------------------------------------------------- |
| `version`   | Exactly `11`                                                  |
| `generated` | ISO 8601, from an injected clock (`Core\Time\ClockInterface`) |
| `scope`     | The analysed path set that produced this file, normalized     |
| `entries`   | Canonical symbol keys → deterministic entry lists             |

Entry invariants:

- `count` is a positive integer and is always present.
- `magnitudes` holds exactly `count` finite numbers and is present exactly for
  channels declared `magnitude`; it is absent for `occurrence` channels. Each value is
  `round($v, 6)` and `-0.0` normalizes to `0`. The list is stored ascending — a
  determinism convention only, since the comparison counts members per severity level
  and never reads it positionally.
- `occurrence` is optional and distinguishes semantic occurrences of the same channel.
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
reporting, and its raw payload so a rewrite preserves the line verbatim.

### Reads

Two paths, one meaning. A file in the canonical layout is read by
`CanonicalBaselineReader` a line at a time, so no entry is held in decoded form
beyond the line it came from; the content hash is accumulated over the raw bytes
as they go past. Any other layout — including a canonical file someone
reformatted — is decoded whole by `BaselineLoader` instead.

The line reader is a **recogniser, not a second parser**. Every shape it does not
recognise it declines, and the whole-document path answers instead; it never
interprets, never repairs, and never throws on layout. That asymmetry is what
makes it safe to rely on: a shape wrongly declined costs one full decode, while a
shape wrongly accepted would mean reading a file as something it is not. A
repeated subject key is declined for that reason — `json_decode` keeps the last
of two identical object keys, and a streaming reader that kept both would apply
ceilings the other path discards.

Beyond that split the two paths share everything: the same entry parser, the same
envelope checks in the same order, the same duplicate demotion. Which path read
the bytes is not observable in what the caller gets, whether that is a baseline or
a refusal.

What this does **not** buy is a `Baseline` that holds less: it still materializes
every entry, because the ceiling stage asks it for stale entries, which are only
computable against the whole set. Reading a 23 MB, 100 000-entry file peaks at
2 MB in the scan itself against 131 MB for the decoded document — the saving is in
how the file is turned into entries, not in what is kept afterwards.

### Writes

`BaselineWriter` writes to a temporary file and renames. A sibling `<baseline>.lock`
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

The wait for the lock is bounded (10 seconds by default): a crashed writer releases
through the OS, but a hung one would otherwise stop the next `qmx` invocation with no
output at all, which in CI reads as a job timeout rather than a baseline problem.

**Every entry read is an entry written.** The writer never groups entries under a key two
of them can share, because resolving such a clash by overwriting would delete a line
nobody decided to delete. Two identities that are distinct in memory but collapse onto
one symbol key once `file:` paths are made project-relative are refused outright rather
than merged.

## Related Documents

- [Finding](../../Finding/README.md) — violation and filtering contracts
- [Inline policy](../Inline/README.md) — source suppressions and threshold extraction
- [Infrastructure](../../../Infrastructure/README.md) — Console adapters and filter ordering
- [Baseline usage](../../../../website/docs/usage/baseline.md) — user-facing documentation


## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
