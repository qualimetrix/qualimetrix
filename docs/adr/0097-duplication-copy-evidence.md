# 0097. Duplication Copy Evidence

Date: 2026-10-04

## Status

Accepted

## Context

A copied sequence can occupy different physical spans, contain different
comments, and occur several times in one file. A project subject and a
shared span or display hint lose those distinctions. Disconnected longer
matches can also hide a shorter match joining additional files when coverage
is judged without connectivity.

The detector rereads selected input after collection. A failed reread cannot
establish that duplication is absent, and a published path reconstructed after
a file or its parent disappears can lose the identity discovery selected.

## Decision

Duplication keeps one subject owner with private Normalization, Index and
Matching subsubjects. It exposes no new Duplication public API. Its existing
Run-owned file-set inspection port remains the only lifecycle seam.

A normalized stream carries aligned values, token start/end rows, covered-row
prefix totals, a compact data mask, and half-open source byte offsets.
Comments and whitespace do not contribute rows; multiline tokens contribute
all rows they occupy. CR, LF and CRLF are counted consistently.

Coordinates use one interleaved packed stream per file, with independent
unsigned 8-, 16- or 32-bit field widths and a signed native 64-bit fallback.
Scalar reads retain the full PHP integer domain and refuse invalid indexes.
Candidate metadata and positions use bounded chunks without a population cap.
Ordered nonnegative positions encode file and token-offset deltas separately;
signed or unordered inputs preserve the original sequence through a 64-bit
fallback. In-place heap ordering avoids the temporary sorting workspace while
retaining the existing complete candidate order.

The detector returns native allocator caches only after releasing the index
and retokenized dataset. The rule reuses immutable file subjects only within
one invocation, keyed by the exact published relative path; copy evidence and
fingerprints remain individual. These lifetimes avoid retaining dataset pages
and repeated identities into later finding analysis.

Each reported copy owns its covered code-line count and original-source hint.
A finding has a File subject and File symbol. The occurrence payload retains
the normalized content digest and ordinal within that file; the subject
already identifies the file. The published channel is File only. Namespace
suppression does not select a namespace-less file finding; path suppression
continues to apply.

Distinct copies and retained covers use half-open token intervals. Retained
matches must connect all copies of a candidate before it is removed.
Candidates are considered by descending length, descending copy count and
stable insertion order. Reusable flat connectivity state avoids retaining a
cover matrix for every candidate.

Matching considers every eligible balanced segment after an unmatched closer.
If none is admitted, it retains the admitted whole match. If a later segment
is admitted, an undersized preceding tail is not reported independently.
Before the second connected coverage pass, admitted segments with the same
complete normalized token sequence merge their distinct file/offset positions.
One content sequence produces one block, so its per-file ordinal cannot restart
in a second block with that content. The pass then precedes block allocation.
Distinct verified matches can still overlap. Inline-HTML row coverage ends at
the last row containing token bytes; a trailing line break adds no empty row.

Block admission uses token count and the greatest covered code-line count
among distinct copies. Every copy of an admitted block is published, including
a shorter copy below the admission minimum. Each copy is Warning below its
own resolved error boundary and Error on or above it. Duplication has no
warning boundary, threshold shorthand or local threshold override.

Hints use each copy's own source byte interval without additional I/O.
The total display limit is 80 Unicode code points including the three-dot
suffix; invalid UTF-8 uses a bounded byte fallback. Hints are display data,
never semantic identity.

PHP keyword, cast, magic-constant and true/false/null spelling is explicitly
case-normalized; ordinary identifier spelling is preserved. Inline HTML is
represented by an xxh128 digest after collapsing only ASCII whitespace.
Its original byte and row spans remain available. Literal value
normalization remains the existing PHP vocabulary.

Run retains lexical selected input to published path identities immediately
after discovery, before collection. A participant reports unreadable selected
inputs through the narrow Run-owned FileSetInspectionFailure. Run resolves
only exact retained inputs, deduplicates failures, preserves collection
failure precedence and marks affected terminal states unreadable. It does not
reconstruct display paths after late I/O. Other exceptions remain internal
failures.

All detector reread failures clear its reusable result provider and refuse
partial clone publication. A completion marker is written only after success.
The baseline command boundary refuses an incomplete run before absence
classification or mutation, including forced operations. Diagnostic results
from other capabilities remain incomplete; they do not establish a zero
duplication count.

A small shutdown reserve provides an effective memory-limit hint and
environment exit 4 for memory exhaustion after autoload. An unrelated fatal
error retains its original outcome. Fatal OOM does not promise a valid or
complete structured report.

## Consequences and migration

Consumers must replace current duplication Project selectors with File
selectors and regroup findings by their file subject. Older Project baseline
entries remain readable after migrating the envelope to the current baseline
format: cleanup identifies their retired channel level, and explain preserves
accepted evidence while marking that level unmeasured. This copy-identity
change does not change the baseline schema version.

The subject, occurrence and measurement changes intentionally reset affected
fingerprints and require a measured baseline update. Previously split blocks
with identical normalized content could share an occurrence identity. Their
merged copy group gives distinct per-file ordinals; review stale accepted keys
and accept the newly distinct copy identities after a complete measured run. Remove duplication
warning and threshold configuration and its local threshold directives;
keep positive min_lines/min_tokens and the error boundary.

The exact index and connected geometry can retain more valid copies than the
previous detector. Runtime and memory acceptance are measured against the
preceding executable on unchanged corpora; no global threshold,
memory ceiling or output-completeness guard is raised to absorb the change.

Regression tests cover geometry, normalization, bounds and real lifecycle
refusal at their cheapest owning level. Each new test is demonstrated once
on a planted source defect. No new permanent controls, gate forms, coverage
stands or CI jobs are introduced. The full 60-file memory regression
uses the existing manual benchmark group and costs about 52 seconds on the
development machine. It catches pipeline lifetime allocation that the cheaper
retained-coordinate and retained-candidate unit guards cannot observe. It adds
no default aggregate execution. A 40-file/128M trial passed but cost more than
the five-second ordinary-test budget; the ordinary suite therefore retains its
20-file/64M guard and makes no full-lifecycle 128M promise. The 100-file capacity
run remains a one-off acceptance measurement.

Property hooks, multi-property statements and ambiguous bare-static property
syntax remain conservative data-tagging gaps. Multiplicity of overlapping
matches and large structured-output memory remain explicit limits.
Diagnostic stale/resolved projections need the later shared absence
authority; this decision guarantees baseline lifecycle refusal without adding
a temporary reporting guard.

