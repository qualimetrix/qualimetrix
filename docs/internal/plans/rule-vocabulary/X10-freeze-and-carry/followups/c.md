# Followup for FOLLOWUPS.md — package C of X10-freeze-and-carry

## X10 (2026-09-06) — C: the baseline channel carry shipped; two items stay open

### C1 — CHANGELOG entry the package could not write itself

`CHANGELOG.md` is outside the package's file set. The `Unreleased` /
`Changed` entry it owes, from the consumer's point of view:

> - `baseline:rename-channels <baseline> <map>` carries an existing baseline
>   onto renamed channel names along a declared tab-separated map, without
>   running an analysis — use it instead of regenerating, which silently
>   accepts whatever the tree has accumulated. **Carrying an entry changes its
>   selector** (a selector is a digest of the identity, and the channel name is
>   part of it), so a saved `baseline:cleanup --remove=<selector>` stops
>   addressing a carried entry; re-read selectors from a fresh
>   `baseline:cleanup` listing.

### C2 — the check on a real foreign baseline is not done

`02-baseline-channel-carry.md` puts the question to the owner: does a baseline
taken with the current product off a third-party tree in `benchmarks/vendor/`
count as "a real foreign baseline", or must the check wait for an actual
consumer project? The package stopped at that question, as instructed.

What already exists in its place, and what it does not replace:

- The carry was run over this repository's own tracked `qmx-baseline.json`
  (a copy in a temporary directory; the tracked file was not touched):
  209 entries, 54 carried on one declared row, everything but the `channel`
  values and the sibling ordering identical, and an empty map over the result
  byte-identical. That is a real file with real content — but it is *this*
  project's, so it proves nothing about a vocabulary the product does not
  declare.
- The plan's own item "the product loads the result without inert lines" was
  already struck from the check by the plan, and correctly: a carry onto a name
  no release has declared yet produces exactly
  `InertEntryReason::UndeclaredChannel`, so the intended outcome would have had
  to fail its own criterion.

### C3 — exit code 2 for an unreachable file diverges from the other four commands

The plan fixes the return codes as `0` carried, `1` refused on content, `2` the
baseline or the map is not a readable file. Measured on this tree, the four
existing commands answer a missing baseline file with `1`:

```
php bin/qmx baseline:cleanup /tmp/nope-does-not-exist.json src/Core; echo $?
# Baseline file not found: /tmp/nope-does-not-exist.json
# 1
```

The plan was executed as written, and the divergence is reported rather than
resolved silently: `baseline:rename-channels` is now the only `baseline:*`
command that distinguishes "that path is not a file" from "I read it and
declined". Either the other four should follow, or this one should not — it is
a surface decision, not an implementation one.

### C4 — `bin/qmx` was outside the named file set and had to be edited

The plan named `OutputConfigurator.php` as the mandatory registration point.
That is necessary and not sufficient: `bin/qmx` holds the
`ContainerCommandLoader` name-to-class map, and a command absent from it is
unreachable however well the container knows it. One import and one map entry
were added there. Nothing else in the file changed.

### C5 — three artifacts the package could not regenerate, and one number it did

**Regenerated, as the plan's named step:** `P6_C_BASELINE_PATHS_SHA256` in
`scripts/generate-modular-architecture-test-inventory.php` moves from
`a4aadf128b0104978e97a5f86f7c2f765b61dd8ceb88a671f553c6d526a6ceb2` to
`72b419a19c39c6dfe93240e46f58f85eb9fefe0442335a9acb978d286c2de50f`. It was
verified to differ by exactly the five paths the package adds and nothing else:
the digest of the tree *minus* those five reproduces the old constant byte for
byte. The added paths are

```
tests/Analysis/Policy/Baseline/Fixtures/ChannelRenameTsvCorpus.php
tests/Analysis/Policy/Baseline/Functional/BaselineRenameChannelsCommandTest.php
tests/Analysis/Policy/Baseline/Unit/BaselineChannelRenamerTest.php
tests/Analysis/Policy/Baseline/Unit/ChannelRenameMapTest.php
tests/Analysis/Policy/Baseline/Unit/ChannelRenameTsvGateAgreementTest.php
```

**Declared, because only this package had the information:** nine rows in
`docs/internal/modular-architecture-manifest.json`, plus two consumer entries
on `FindingChannel` (`ChannelRenameMap` and `BaselineEntryPayload`) and one
consumer entry *removed* from `DependencyType` (see C7). Without them the
production inventory refuses to generate at all (`manifest declarations do not
match the production AST`, then `has 0 matching consumer entries`). The file
grew by pure insertion — 911 declarations against 902 — and the single deletion
is that one stale consumer row; no other row was reordered or reformatted.
Visibility follows measured cross-owner use:
`BaselineChannelRenamer`, `ChannelRenameMap` and `ChannelRenameReport` are
`contract` (the command and the configurator import them);
`ChannelRenameRefusal`, `BaselineDocumentLayout`, `BaselineDocumentWriter`,
`BaselineEntryOrder` and `BaselineEntryPayload` are `internal`.

**Left to the orchestrator**, because they are whole-tree artifacts that every
in-flight package of this заход moves:

- `qmx.yaml` and `docs/internal/generated/modular-architecture/*` are stale and
  must be regenerated with `php scripts/generate-modular-architecture.php`.
  `qmx.yaml` is explicitly outside this package's file set, and
  `test-ownership.tsv` was already stale from the other packages' edits before
  this one touched anything. Until that runs, exactly three cases across the whole suite are red, all on
  the same cause: `ModularArchitectureGovernanceIntegrationTest`'s freshness
  check, its composition-binding count (70 read from the stale `qmx.yaml`
  against the 71 the manifest now declares), and
  `DogfoodingTopologyTest::itProjectsEveryManifestDeclarationToItsOwnerOrSingletonSeam`,
  which finds `BaselineChannelRenamer` in the manifest and not yet in the
  projection. All three go green on regeneration; none is a defect in the
  carry. `vendor/bin/phpunit --no-coverage tests/` reports 8151 tests, 3
  failures, and those are they.

### C6 — `coupling.cbo` on `Baseline.php` tips over its threshold by one edge, and `check:self` is red until it is decided

```
php bin/qmx check src/ --workers=0 --format=json --baseline=qmx-baseline.json
# error coupling.cbo src/Analysis/Policy/Baseline/Baseline.php
# Afferent coupling too high: 19 classes depend on this (CBO: 21, threshold: 21)
```

`BaselineChannelRenamer` reads `Baseline::VERSION` to refuse a file of another
version, and that is the single new edge — measured, not inferred: replacing the
three `Baseline::VERSION` references in that file with the literal `13` and
re-running the same command drops the finding entirely (`violations: 1`, only
the coverage error). The refactoring answer does not apply — the version constant belongs
to `Baseline`, and what the metric is really reporting is that `Baseline` is a
hub with nineteen dependents, which is not this package's to split. The two
remaining answers, tuning the threshold in `qmx.yaml` and accepting the entry
into the ratchet, both need files this package may not touch. Owner's call.

Everything else the self-check flagged on the new code **was** refactored away
rather than tuned: the first cut of `BaselineChannelRenamer` came back with
cognitive 18, two cyclomatic warnings, WMC 84 and an instability warning.
Extracting `BaselineEntryPayload` — the entry line as the file spells it, which
is a subject and not a split-for-the-metric — cleared all five, and deciding
`?array $fields` once in its constructor instead of re-asking `is_array` in
every accessor cleared the two the extraction then raised on it. `bin/qmx check
src/` now reports nothing on any file this package added.

### C7 — one edit to existing production code the plan did not name

`BaselineEntryPayload` has to decide whether a line's `edge` field forms an
edge, and the rules for that already live in `BaselineEntryParser::readEdge()`
— including that an unknown `edge.type` is a refusal, which a second reading
would very plausibly have got wrong. Restating them would have been exactly the
drift this package spent a round removing elsewhere.

So the shape reading moved to its owner: `BaselineEdge::fromArray()`, with the
parser delegating to it and turning the `InvalidArgumentException` into its own
`BaselineEntryRejection`. Two consequences worth naming:

- **It is behaviour-identical, and that is now checked.** `fromArray()` throws
  the parser's exact sentences, including the empty-`target` one the first cut
  of it silently changed. `BaselineEntryParserTest::itNamesWhatIsWrongWithAMalformedEdge`
  pins all five messages; removing the empty-target guard reddens it.
  `readOptionalObject()` and `describe()` were left unused by the move and are
  deleted — `bin/qmx check src/` reported both as dead code, which is how they
  were noticed.
- **It removes a coupling edge rather than adding one.** `BaselineEdge` already
  imported `DependencyType`; the parser no longer does, and neither does the
  payload. Without this, `DependencyType` picked up a `coupling.cbo` warning of
  its own (20 dependents against a threshold of 20) — measured, then removed.
