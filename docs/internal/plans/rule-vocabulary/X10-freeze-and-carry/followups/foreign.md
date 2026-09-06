# Followup for FOLLOWUPS.md — closing C2, the real-foreign-baseline check

## X10 (2026-09-06) — `baseline:rename-channels` verified on a real foreign baseline

`followups/c.md` (C2) left the plan's own question open: does a baseline the
current product takes off a third-party tree in `benchmarks/vendor/` count as
"a real foreign baseline", or must the check wait for an actual consumer
project. The owner's decision, recorded in the task that produced this file:
the vendor tree qualifies. This closes C2 with that decision executed.

**Subject and map.** `bin/qmx baseline:generate` was run against the
`nikic/php-parser` vendor tree (a public dependency already vendored under
`benchmarks/vendor/`, so naming it is not a private-project leak under rule
#10) with no other project code involved, producing a **1056-entry** baseline.
A four-row TSV map was built from channels that baseline actually carries:
three renames of channels present in double digits or more, plus one row
whose `old` name matches nothing in the file, to exercise the idle-row path.
Working files (the generated baseline, the map, command logs) live under the
session scratchpad, not in the repository.

**Claim 1 — only `channel` fields changed, everything else identical.**
Confirmed. A structural diff comparing the pre- and post-carry documents
found the envelope (every field but `entries`) byte-for-byte identical, the
same 1056 entries under the same subject keys on both sides (no subject
appeared or disappeared), zero entries where any field other than `channel`
differed, and exactly the expected number of entries with a changed
`channel` value.

**Claim 2 — renamed count equals the count matched by the map.** Confirmed.
The command's own JSON report gives per-row hit counts that sum to its
top-line `renamed` count, and both equal the independently-computed diff's
changed-field count from Claim 1 — three independent countings of the same
number agree. The idle row (matching nothing) is reported with a hit count of
zero and listed under `idle_rows`, not as an error.

**Claim 3 — an empty map (header only) on the same file gives byte
identity.** Confirmed on a separate copy of the pre-carry file: the command
reports `written: false`, and `cmp` together with a SHA-256 comparison shows
the file untouched byte-for-byte.

**Inert count before/after, today's loader.** Measured via `bin/qmx
baseline:cleanup <baseline> <same-tree>` (no `--remove`, so nothing is
written) run before and after the carry — this is the same
`ChannelDeclarationRegistryInterface` a real analysis run resolves, per the
class's own docblock, so it is a stronger witness than a bare
`BaselineLoader::load()` call outside the DI container. Before the carry: the
freshly generated file has zero entries in the `Inert` bucket (unsurprising —
it was written by this same build). After the carry: every one of the
carried entries — same count as Claim 2's `renamed` — moved into the `Inert`
bucket, each for the reason "channel is not declared by any rule", while the
untouched entries stayed valid; total entry count is unchanged. This matches
the documented intended behavior exactly (`ChannelRenameMap`'s own docblock
and `02-baseline-channel-carry.md`'s "Развилка 1"): a carry onto a name this
build has never declared produces entries `check`/`cleanup` report as inert
rather than ones silently dropped, and the carry itself never removes a line.

**No defect found.** All three claims and the before/after inert accounting
match the command's documented and specified behavior; nothing here required
an implementation change.

**Reproduction** (paths below are the session scratchpad used for this
check, not part of the repository):

```
bin/qmx baseline:generate <scratch>/foreign-baseline.json benchmarks/vendor/nikic/php-parser/lib --no-progress
# -> 1056 entries

bin/qmx baseline:cleanup <scratch>/foreign-baseline.json benchmarks/vendor/nikic/php-parser/lib --no-progress
# -> before: "No entry is a removal candidate." (0 inert)

bin/qmx baseline:rename-channels <scratch>/foreign-baseline.json <scratch>/map.tsv --format=json
# -> {"written":true,"entries":1056,"renamed":758,"rows":{...:625,...:95,...:38,...:0},
#     "idle_rows":["...one idle row..."],"unreadable":[]}

bin/qmx baseline:cleanup <scratch>/foreign-baseline.json benchmarks/vendor/nikic/php-parser/lib --no-progress
# -> after: "758 entries could be removed", all reason
#    "cannot be applied: channel is not declared by any rule"

# structural diff (custom script comparing decoded JSON, ignoring "channel"):
# envelope_identical=true, total_entries_before=1056, total_entries_after=1056,
# channel_changed_count=758, missing_subjects=[], extra_subjects=[],
# unexpected_field_diff_count=0

# empty-map byte-identity, on a separate copy of the pre-carry file:
printf 'old\tnew\treason\n' > <scratch>/empty-map.tsv
bin/qmx baseline:rename-channels <scratch>/foreign-baseline.emptymap-test.json <scratch>/empty-map.tsv --format=json
# -> {"written":false,"entries":1056,"renamed":0,"rows":[],"idle_rows":[],"unreadable":[]}
cmp <scratch>/foreign-baseline-before-carry.json <scratch>/foreign-baseline.emptymap-test.json
# -> identical (exit 0); sha256sum matches
```
