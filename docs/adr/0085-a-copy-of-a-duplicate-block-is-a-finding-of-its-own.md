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

## Decision

Each copy of a block is a finding located on that copy. Every copy keeps the
block's identity — the project plus the content hash — so the copies of a
block form one baseline group whose count is the number of copies. A new copy
is one member more than the entry accepted: a breach. A deleted copy is one
fewer: accepted, as any shrinking group is. The git scope keeps a finding by
its location, so a new copy is reported in the file it was pasted into.

A finding names at most ten other copies, in its message and as its related
locations, and counts the rest. Every copy is reported by a finding of its
own, so the bound removes no copy from the report; without it a block of N
copies carries N² related locations, and a thousand copies turned the SARIF
report into a memory exhaustion at 512M. Measured on a thousand copies of one
class, `--format=json`: peak PHP memory 104.9 MB against 102.8 MB for one
finding per block.

Inline directives stay refused on the channel, for a reason that changed. A
file or next-line directive now reaches the copy it is written beside, and
would silence that member of a group whose members are one debt, while every
other copy still reports the block and names the silenced one; the baseline
would count fewer copies than exist. A symbol directive binds to a
declaration and the finding's subject is the project, as before.

## Consequences

- A baseline written before this change holds one magnitude per block and
  now meets one finding per copy: every accepted block breaches until the
  baseline is regenerated.
- A breach reports every copy of the block, not only the new one — the
  mechanism cannot tell which member of a group is new (ADR 0017, residual
  limitation 4).
- All copies of a block share one fingerprint, as the members of any
  multi-member group do.

## Rejected alternatives

- **The number of copies as the finding's magnitude.** The ceiling would see
  a fourth copy as a larger value, but a baseline written before the change
  stores the line count, which the count of copies does not exceed for most
  blocks: the old baseline would accept the new copy silently.
- **A finding per copy with every other copy as a related location.** N²
  related locations; measured above.
- **Keying each copy by its file.** A copy that moves or is renamed would
  re-key, and the baseline would read one stale entry and one new finding for
  code that did not change.
