# Duplication

## Subject, promise and owners

- **Subject:** token- and block-based code duplication evidence.
- **Promise:** inspect one discovered PHP file set, retain the complete result
  for one analysis run, and emit `duplication.clone` findings from
  that result.
- **Semantic owner:** `Analysis.Evidence.Duplication`.
- **Owned paths:** `src/Analysis/Evidence/Duplication/`,
  `tests/Analysis/Evidence/Duplication/`, and the English/Russian Duplication
  rule pages.
- **Non-goals:** file discovery and run sequencing belong to `Analysis.Run`;
  configuration source merging belongs to `Analysis.Configuration`; Finding
  owns rule and finding primitives plus `Analysis\Finding\RuleExecution`.

## Structure

```text
Duplication/
├── DuplicationDetector.php
├── DuplicationResultProvider.php
├── Index/
│   ├── HashIndexBuildResult.php
│   ├── HashIndexBuilder.php
│   ├── PackedPosition.php
│   └── SaturatingCandidateFilter.php
├── Matching/
│   ├── BalancedSegments.php
│   ├── ContentHintExtractor.php
│   ├── CopyCoverIndex.php
│   ├── DuplicateBlock.php
│   ├── DuplicateBlockFinder.php
│   ├── DuplicateContentMerger.php # collision-checked content groups and copy reduction
│   ├── DuplicateLocation.php
│   ├── DuplicateMatchCandidates.php
│   └── DuplicateSearchRequest.php
├── Normalization/
│   ├── DataDeclarationTagger.php
│   ├── RetokenizedFiles.php
│   ├── TokenNormalizer.php
│   └── TokenStream.php
├── CodeDuplicationOptions.php
└── CodeDuplicationRule.php
```

## External integration

Duplication publishes no contract of its own. `DuplicationDetector` implements
the consumer-owned
`Analysis\Run\Contract\FileSetInspectionParticipantInterface`; every
Duplication entity, option, result provider, rule, and detector remains internal.
Infrastructure registers the detector as a FileSet participant by
autoconfiguration and never publishes a Duplication alias.

## State and lifecycle

| State                  | Scope   | Owner                       | Created/reset by                                                                                        | Typed readers         |
| ---------------------- | ------- | --------------------------- | ------------------------------------------------------------------------------------------------------- | --------------------- |
| `list<DuplicateBlock>` | per-run | `DuplicationResultProvider` | Created by `DuplicationDetector::inspect()` and cleared in O(1) by `DuplicationDetector::resetForRun()` | `CodeDuplicationRule` |

`inspect()` computes a complete local result before replacing the provider's
value. Replacement never appends to a previous run, and `all()` returns the
typed list by PHP array value semantics, so consumers cannot mutate the
provider's stored array.

After matching, the detector releases the index and retokenized files, then
returns unused native allocator caches before publishing blocks. The rule
shares immutable file subjects and their symbol paths only within one
`analyze()` invocation, keyed by the exact published relative path. Copy
occurrences, locations and values remain independent; another invocation
creates fresh identities.

The provider is intentionally an instance-owned lifecycle state holder, not a
DTO or public data surface. Its private array is available only through the
replace/read/reset semantics required by one analysis run. It carried a point
`@qmx-ignore design.data-class` until `design.data-class` was corrected to gate
on a low share of functional public methods; its replace/read/reset methods are
functional, so the control is no longer needed.

## Dependencies and ports

There is one narrow Run phase port. The generic composite invokes it without a
Duplication-specific branch; Duplication retains its result and emits its own
completion log through its implementation.

| Dependency/port                                        | Owner                   | Direction                    | Typed input/output                           | Why required                                                                          |
| ------------------------------------------------------ | ----------------------- | ---------------------------- | -------------------------------------------- | ------------------------------------------------------------------------------------- |
| `FileSetInspectionParticipantInterface`                | Run                     | Run -> Duplication           | `list<SplFileInfo>` -> provider-owned result | Run invokes a selected participant without importing the detector.                    |
| `RuleConfigurationInterface` and Run file-set input    | Finding / Run           | Duplication -> Finding / Run | named rule options and project root          | Detection reads its named rule and receives root only through Run's participant call. |
| Path and symbol primitives                             | Core.Path / Core.Symbol | Duplication -> Core          | absolute/relative paths and metric subjects  | Stable file, subject, and report identities.                                          |
| Rule/finding contracts, including `Rules\AbstractRule` | Analysis.Finding        | Duplication -> Finding       | rule/options and findings                    | The owned rule participates in Finding's execution and publication boundary.          |

## Test ownership

The module owns test classes at three levels, and the level is decided by what
the body does. Unit tests live below `Unit/`, where the owning test root is
`Duplication`; subject folders group the classes within that root. `Unit/Index/`
contains `PackedPositionTest` and `SaturatingCandidateFilterTest`.
`Unit/Matching/` owns balanced segments, display hints, connected coverage,
block finding, candidate storage and copy identity. `Unit/Normalization/`
owns data tagging, normalization and packed coordinate alignment.
`CodeDuplicationRuleTest` stays directly under `Unit/` because the rule stays
in the root. These tests run in memory.

Two Integration classes under `tests/Analysis/Evidence/Duplication/Integration/`,
both writing real files into a temporary directory:
`DuplicationDetectorTest` runs the detector's whole pipeline over them, and
`DuplicateCopyIdentityTest` runs the detector and the rule before and after an
edit, pinning which edits keep the block and every copy's identity and which
re-key untouched copies, and that each admitted copy reports its own covered
code-line count and hint, including a copy below the admission minimum.

Four Functional classes under `tests/Analysis/Evidence/Duplication/Functional/`:
`DuplicationMemoryLimitProcessTest`, which builds temporary projects and runs
`bin/qmx` in real PHP subprocesses under a configured `memory_limit`. It covers
the detector's memory-limited CLI path and highly repetitive inputs; these test
limits are regression fixtures, not a general time or memory guarantee. Its
60-file lifecycle case runs only in the existing `benchmark` group; the ordinary
20-file JSON memory guard remains in the default suite. Run the manual case
with `vendor/bin/phpunit --no-coverage --group=benchmark --filter=itCompletesTheDuplicationLifetimePipelineUnder128M`.

> **Limitation:** the ordinary suite has no 128M full-lifecycle guarantee. A
> 40-file/128M trial passed but exceeded the five-second ordinary-test budget;
> `itCompletesTheDuplicationLifetimePipelineUnder128M` therefore remains the
> 60-file manual benchmark. The ordinary memory guard uses 20 files at 64M.

It is the reason the module has a Functional level at all. `DuplicationGitScopeProcessTest`
runs `--report=git:staged` over a git repository in which only a new copy is
staged, and pins that the copy is reported in its own file.
`DuplicationCopyFingerprintProcessTest` runs `--format=gitlab` and
`--format=sarif` over two copies and pins that each carries its own
fingerprint. `DuplicationCopyBaselineProcessTest` accepts two copies into a
baseline, adds a third copy, and pins that only the new copy is reported.

Run the complete owned suite with:

```bash
vendor/bin/phpunit --no-coverage tests/Analysis/Evidence/Duplication
```

## Extension registration

`CodeDuplicationRule` emits one File-level finding per admitted copy. Admission
uses the greatest number of covered code lines among a candidate's copies and
the normalized-token minimum; each emitted finding then carries that copy's own
`codeLines`, hint and identity. A copy below `min_lines` may still be reported
when another copy admits the block. Severity uses only the positive `error`
option: a copy below it is Warning and a copy at or above it is Error. The
positive defaults are `min_lines: 5`, `min_tokens: 70` and `error: 50`; there is
no duplication `warning`, `threshold` shorthand or local `@qmx-threshold`
override. Disabling the rule skips inspection.

The subject and `symbolPath` identify the copy's project-relative file. Its
semantic occurrence is derived from the normalized content digest and its
order among copies in that file, not from a project subject or line number. A
new copy, moved or renamed file, or changed matching block can therefore
re-key baseline and GitLab/SARIF identities; this is an identity migration, not
a baseline schema change. `@qmx-ignore` channel directives remain refused.
A file subject has no namespace, so `suppress_namespaces` does not suppress a
Duplication finding; path selectors can suppress copies in their files.
Population copy identities use the existing byte-safe canonical file subject in
JSON tuples, alongside native block and copy ordinals. Finding occurrence keys
keep their normalized content digest and per-file copy order.

The detector counts token rows over CR, LF and CRLF correctly; CRLF is one line
break. A trailing line break in an inline-HTML token does not cover an empty
next line. Admitted balanced segments with the same complete normalized token
sequence form one block with all distinct copy positions before the second
connected-coverage reduction. The ordinal is local to each file in that merged
block. If no balanced segment is large enough, the whole match is the fallback. It retains connected file-pair evidence
while removing containment witnesses that add no distinct reportable evidence;
this is not a blanket rule to discard every contained copy. Some nested
matching multiplicity remains an acknowledged behavior; it is not claimed
fixed. A group is excluded only when all its copies lie wholly in constant
or property array initializers; data matched with executable code remains reportable.

HTML token identity collapses ASCII whitespace before an `xxh128` digest. PHP
keyword identifiers are normalized case-insensitively; ordinary identifiers
and the existing non-HTML hash vocabulary are unchanged. CR/LF/CRLF row spans
are kept separate from token coverage. Each hint is extracted from that copy's
source byte range, joins up to three substantive excerpts from its first ten
lines, and collapses whitespace. It is limited to 80 Unicode codepoints;
invalid UTF-8 uses a byte-safe fallback.

Each finding names at most ten other copies in its message and related
locations, and counts the rest. `DuplicateBlockFinder` keeps candidate lengths
and packed positions until non-reportable contained witnesses are removed,
then constructs blocks from the retained connected evidence.
`DuplicateContentMerger` groups verified segments by complete token content,
checks hash collisions token by token, and reduces sorted unique reportable
copies. Its streams and reduction callback live only for that merge; the finder
releases request state at the end of each search.

`TokenStream` retains token values, a data mask and interleaved packed
coordinates. `startLine(int)`, `endLine(int)`, `coveredPrefix(int)`,
`startByte(int)` and `endByte(int)` expose individual integer coordinates;
invalid token indexes refuse. `coveredLines(int, int)` derives an interval's
covered rows from the same prefix and boundary coordinates. Each field uses
the smallest sufficient unsigned 8-, 16- or 32-bit width, with signed native
64-bit storage preserving the full PHP integer range. Normalization builds and packs one file at a time.

Candidates retain chunked metadata and copy positions. Ordered nonnegative
positions encode file and token-offset deltas separately; signed or unordered
inputs preserve their sequence through a 64-bit fallback. In-place heap
ordering preserves descending length, descending copy count and insertion
order without retaining a sorting workspace. There is no candidate cutoff.
Work within one exact token-sequence group does not promise linear work over
all nested matches in a varied corpus.

`CopyCoverIndex` owns retained token intervals and reusable flat connectivity
state. `BalancedSegments` admits eligible structural segments through the
finder's reportability callback and retains the whole match only when none
qualifies. The finder owns copy filtering and block allocation.

`CodeDuplicationRule` is a `qmx.rule` implementation. Registration is delegated
to the infrastructure `DuplicationConfigurator`; compiler passes inject its
options, add it to rule/channel registries, and reject duplicate rule/channel
identities. The rule's deterministic id is `duplication.clone`.

## Run integration

The former `MetricEnricher -> DuplicationInspectionInterface` temporary import
and the capability-owned interface are gone. The final route is Run's
FileSet participant port implemented by `DuplicationDetector`. Disabling
`duplication.clone` prevents both inspection and allocation; a second
analysis run begins with an empty provider.


## Typed per-inspection configuration

`DuplicationDetector` consumes the prepared immutable `CodeDuplicationOptions`
from the invocation snapshot. It does not reread raw configuration or substitute
defaults when the snapshot is absent. `min_lines`, `min_tokens` and `error` are
positive integers with defaults 5, 70 and 50; `enabled` defaults to true. Only
the first two admit blocks. `error` sets the per-copy severity boundary after
admission. The rule has no local `warning`, `threshold` or `@qmx-threshold`
override. A false read during inspection clears partial provider output and
makes the run incomplete with exit 4; that empty result cannot prove there are
no copies. An out-of-memory failure in the detector produces a short stderr
hint and environment exit 4; a complete JSON report is not promised after fatal
OOM.

## Locality

This README is part of the subject boundary: keep its production code, tests, fixtures, support, and documentation with the named owner. External consumers use declared contracts only; mutable runtime state has one owner, reset point, and typed readers. Composition-only access to a private declaration requires a reviewed exact binding, not a generic qmx permission.
