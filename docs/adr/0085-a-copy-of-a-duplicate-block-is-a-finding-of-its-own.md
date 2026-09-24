# 0085. A Copy of a Duplicate Block Is a Finding of Its Own

**Date:** 2026-09-24
**Status:** Accepted

## Context

`duplication.clone` groups the copies of one block by content and used to
publish one finding per block, located on the copy that sorts first and
reading "N occurrences". Grouping is what keeps a block copied a thousand
times inside the memory limit, and it stays.

One finding per block hid a new copy in two places. Its identity is the
project plus the block's content hash, so a baseline entry accepting a block
of three copies accepted a fourth unchanged: the entry stored one magnitude,
the run produced one finding, and the ceiling passed. And the finding sat in
one file, so `--report=git:staged` with only the new copy staged reported
nothing.

A third consumer compares findings by fingerprint rather than through a
baseline. GitLab Code Quality shows findings sharing a fingerprint as one
entry and decides what a merge request introduced by comparing fingerprints
with the target branch; SARIF consumers match alerts across runs by the
partial fingerprint. Both fingerprints are built from the finding's identity
alone, never its location. A finding on each copy that kept the block's
identity would still collapse the copies into one entry there, and a new
copy would carry a fingerprint the target branch already had.

## Decision

**Each copy of a block is a finding located on that copy, with an identity of
its own:** the block's content hash, the project-relative path of the file
holding the copy, and the copy's place among the block's copies in that file,
counted in line order. The subject stays the project. No line number enters
the identity, so code added or removed around a copy re-keys nothing.

A new copy is then the only new identity. A baseline reports it as a new
finding, on the new copy alone, and keeps every copy it accepted accepted; a
GitLab merge request and a SARIF consumer see one new fingerprint. A deleted
copy leaves its entry stale, as any repaired finding does. The git scope keeps
a finding by its location, so a new copy is reported in the file it was
pasted into.

A finding names at most ten other copies, in its message and as its related
locations, and counts the rest. Every copy is reported by a finding of its
own, so the bound removes no copy from the report; without it a block of N
copies carries N² related locations, and a thousand copies turned the SARIF
report into a memory exhaustion at 512M. Measured on a thousand copies of one
class, `--format=json`: peak PHP memory 104.9 MB against 102.8 MB for one
finding per block. Keying each copy apart did not move it, measured again
by `memory_get_peak_usage()` with one worker: 85.1 MB against 85.4 MB for
`--format=json` and 86.6 MB against 86.8 MB for `--format=sarif`, with 1000
distinct fingerprints where there was one.

Inline directives stay refused on the channel. A symbol directive binds to a
declaration and the finding's subject is the project. A file or next-line
directive now reaches the copy it is written beside and would silence it:
that copy's debt would never reach a baseline, so a copy pasted together with
such a directive would pass unseen, while every other copy still reports the
block and names the silenced one.

## Consequences

- A baseline written before this change holds one entry per block and now
  meets one finding per copy under a different identity: every accepted
  block's entry is stale and every copy reports as new until the baseline is
  regenerated.
- A block of N copies is N findings, where v0.27.0 reported N − 1 pairs, so
  the violation count and the technical debt grow by one finding and one
  remediation time per block — twice the debt for a block of two copies.
- `suppress_paths`, global or per rule, silences only the copies inside its
  paths. The block's other copies are still reported; silencing a block means
  listing every file it has a copy in.
- A copy moved to another file, and every copy in a renamed file, is a new
  copy and leaves a stale entry behind, as a renamed symbol does (ADR 0017,
  residual limitation 7).
- A copy pasted above another copy of the same block in the same file takes
  the lower place, and the copy it displaced is the one reported as new. The
  count of new copies is right; which copy of that file it names is not.
- A new copy of an accepted block is reported at its own severity, not
  promoted to Error as a measured breach of a group is: it is a new finding,
  and `--fail-on` decides whether it fails the run, as for any other.

## Rejected alternatives

- **Every copy keeps the block's identity.** A baseline entry then bounds the
  number of copies and a new copy breaches it, but every copy of the block is
  reported with it — the ceiling cannot tell which member is new (ADR 0017,
  residual limitation 4) — and every fingerprint-matching consumer collapses
  the copies into one entry and never sees a new one.
- **A formatter-only fingerprint that tells copies apart.** GitLab and SARIF
  would see each copy while the baseline kept one identity for all: two
  identities for one finding, with the published `occurrence` in JSON
  disagreeing with the fingerprint built from it.
- **The copy's line in its identity.** Every edit above a copy would re-key
  it, and a baseline would read one stale entry and one new finding for code
  that did not change.
- **The copy's place among all of the block's copies.** A copy added in a
  file that sorts earlier would re-key every copy after it.
- **The number of copies as the finding's magnitude.** The ceiling would see
  a fourth copy as a larger value, but a baseline written before the change
  stores the line count, which the count of copies does not exceed for most
  blocks: the old baseline would accept the new copy silently.
- **A finding per copy with every other copy as a related location.** N²
  related locations; measured above.
