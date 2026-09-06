# X10 fix pass — report

Three named edits over `baa1df5e`/`7974f742`. All three implemented; DoD met
for all three, with one unauthorized-scope stale-artifact wall documented (not
fixed, per instruction).

## Edit 1 — `coupling.cbo` on `Baseline.php`

**Measured before touching anything:** `php bin/qmx check src/ --workers=0
--format=json` reported `coupling.cbo` 21 (threshold 21, afferent 19 classes)
on `Baseline`, confirming the brief's "measured" claim rather than trusting it.

**Root cause confirmed in code, not docblocks:** grepped every
`Baseline::VERSION` use. `V5BaselineReader` and `BaselineChannelRenamer` each
reference `Baseline` **only** via `Baseline::VERSION` — no construction, no
type hint. `BaselineWriter` and `BaselineLoader` also read the constant but
already depend on `Baseline` for other reasons (they build/consume the type),
so extracting the constant does not touch those edges.

**Decision — new class `BaselineFormatVersion`:**

```php
final class BaselineFormatVersion
{
    public const int CURRENT = 13;

    private function __construct() {}
}
```

Placed in `src/Analysis/Policy/Baseline/` (same subject, no new module —
subject-cohesion skill loaded before creating the file). Named for what it
*is* (a fact about the format), not for the property it holds — follows the
`RuleOptionKey`-style precedent already in the codebase (`final class` +
constants + private constructor). Considered and rejected:
`BaselineFileFormat::VERSION` — defensible too, but would invite other
format-level facts (`GENERATED_FORMATS`, envelope field names) to migrate
there later; keeping scope to exactly the one constant this package asked to
move avoids that scope creep.

**Changes:**
- `Baseline.php` — removed `public const int VERSION = 13`; rewrote the
  docblock paragraph that asserted "the version is a constant rather than a
  field" (now false) to state the constant moved to `BaselineFormatVersion`
  while keeping the still-true claims (this type *is* the current format's
  shape, `BaselineLoader` refuses any other version, no `version` field to
  avoid branching).
- **Removed the `@qmx-threshold coupling.cbo 21` annotation entirely** (not
  just retargeted the reason text). This is a necessary consequence of the fix,
  not one of the three named edits — flagging it explicitly per the task's
  own instruction to name anything beyond the three edits. Reason: after the
  extraction, measured CBO is 19, which sits *below* the default class
  threshold (20), so the annotation would become permanently inert. An inert
  `@qmx-threshold` fails `bin/qmx directives` (part of `check:self`) with
  verdict 2. Verified after removal: `php bin/qmx directives
  src/Analysis/Policy/Baseline/` reports "No inline directives in the
  analysed scope" — clean.
- `V5BaselineReader.php`, `BaselineChannelRenamer.php`, `BaselineWriter.php`,
  `BaselineLoader.php` — `Baseline::VERSION` → `BaselineFormatVersion::CURRENT`
  (same namespace, no new `use` needed in production code).
- `BaselineLoader.php` docblock — its `REJECTED_VERSION_REASONS` doc and two
  other `{@see Baseline::VERSION}` references updated to point at
  `BaselineFormatVersion::CURRENT` too (caught only by grepping every
  occurrence, not just the code lines).
- Tests: `BaselineChannelRenamerTest.php`, `CaptureFromMeasuredSetTest.php`,
  `BaselineRenameChannelsCommandTest.php` — same rename. The first two keep
  their `use Baseline` import (they still construct/type-hint `Baseline`
  elsewhere); the command test's only use of `Baseline` was the constant, so
  its import was swapped rather than left dangling.
- `src/Analysis/Policy/Baseline/README.md` — added the one-line structure
  entry for the new file (required by AGENTS.md: update the owning README
  after adding a class).

**Measured after:**
- `coupling.cbo` for `Baseline`: **19**. Before: `ca 19 / cbo 21` (from the
  finding's own message, "19 classes depend on this (CBO: 21..."). After:
  `ca 17 / ce 2 / cbo 19` (from `--format=metrics`'s raw dump). Exactly −2 on
  the afferent side, matching the two edges removed.
- No `coupling.cbo` finding for `Baseline.php` at all any more (19 < default
  threshold 20).
- `vendor/bin/phpstan analyse --memory-limit=1G` over every touched file: 0
  errors.
- `vendor/bin/phpunit tests/Analysis/Policy/Baseline`: 507 tests, 1404
  assertions, green (run twice, before and after all three edits, to catch
  interference from concurrent unrelated activity in the same tree — see
  "Unrelated concurrent changes" below).
- `composer architecture:check`: **fails**, but on exactly the expected
  reason and nothing else:
  ```
  Production inventory generation failed: manifest declarations do not match
  the production AST; missing=[] extra=[Qualimetrix\Analysis\Policy\Baseline\BaselineFormatVersion]
  ```
  This is the stale-manifest wall named in the brief as expected and out of
  my authority (`docs/internal/generated/**` and
  `docs/internal/modular-architecture-manifest.json` are explicitly excluded
  from my file set). Direct measurement above (`bin/qmx check src/
  --workers=0`) is the substitute evidence requested for this case.
- `bin/qmx directives src/Analysis/Policy/Baseline/`: clean, 0 directives
  left in this capability (the removed `@qmx-threshold` was the only one).
- The new class also produces one **new** `architecture.coverage` error on a
  full `php bin/qmx check src/ --workers=0 --format=json` run: "1 class(es)
  outside all declared layers" naming `BaselineFormatVersion` — because
  `qmx.yaml`'s declared layers do not yet cover it. Same root cause as the
  `architecture:check` manifest mismatch above, same authorization boundary
  (`qmx.yaml` is explicitly out of my file set): named here, not fixed.
- `composer cs-check` (full tree, since `php-cs-fixer` refuses a path list
  without `--config`): exit 0, no violations, including on every file this
  package touched.

**DoD status:** the finding is gone and the CBO reduction is measured and
named (21 → 19). `composer selfcheck` itself cannot go green without the
manifest regeneration that is out of scope — reported as expected per the
brief, with the direct-measurement substitute supplied.

## Edit 2 — return codes on `BaselineRenameChannelsCommand`

**Measured the precedent before changing anything**, per the brief's demand
that precedent beats the plan:

```
$ php bin/qmx baseline:cleanup /tmp/nope.json src/Core; echo $?
Baseline file not found: /tmp/nope.json
1
$ php bin/qmx baseline:explain SomeSubject --baseline=/tmp/nope.json src; echo $?
Baseline file not found: /tmp/nope.json
1
$ php bin/qmx baseline:rename-channels /tmp/nope.json /tmp/nope-map.tsv; echo $?
Not a readable file: /tmp/nope.json
2
```

Read `BaselineCommand.php` (the shared base) to understand *why* the other
four answer 1: `self::EXIT_INVALID_INPUT` (2) is reserved for a CLI value the
command cannot interpret at all (a malformed `--channel`, `--mode`, or
`--format` value — confirmed against `BaselineExplainCommand::readChannel()`
and `BaselineGenerateCommand::readMode()`, both of which return 2 only for
that class of failure). A missing/unreadable **file** is different — "a
refusal to act on input it understood" per the base class's own docblock —
and every other command answers that with 1, either via the base's default
`fail()` exit code or `self::FAILURE` directly.

`BaselineRenameChannelsCommand`'s `is_file`/`is_readable` check was returning
`self::EXIT_INVALID_INPUT` (2) for exactly the "missing file" case the other
four answer with 1. Its separate "unknown `--format` value" check *does*
belong to the malformed-CLI-value class and correctly stays at 2 — left
untouched, since collapsing it into 1 would have hidden a real distinction
the other commands preserve.

**Changes:**
- `BaselineRenameChannelsCommand.php` — the file-readability check now
  returns `self::FAILURE` instead of `self::EXIT_INVALID_INPUT`.
- `ChannelRenameRefusal.php` docblock claimed "[the unreadable-file case] ...
  answers with a different exit code" than a content refusal — now false,
  since both answer 1. Rewrote to say they now share the exit code, matching
  the other four commands, which never distinguished the two either. (This
  file is in `src/Analysis/Policy/Baseline/**`, in scope; the claim would
  otherwise contradict the code the moment someone reads it.)
- `BaselineRenameChannelsCommandTest.php` — renamed
  `itAnswersAnUnreachableFileWithTwo` → `itAnswersAnUnreachableFileWithOne`,
  both assertions `2` → `1`, docblock rewritten to explain the alignment with
  the other four commands instead of a now-false "different exit code" claim.
- `website/docs/usage/cli-options.md` / `.ru.md` — the one line documenting
  this command's exit codes updated: `1` now covers both a content refusal
  and an unreadable file; `2` is only the malformed `--format` value.

**Verified:**
```
$ php bin/qmx baseline:rename-channels /tmp/nope.json /tmp/nope-map.tsv; echo $?
Not a readable file: /tmp/nope.json
1
```
`vendor/bin/phpunit tests/Analysis/Policy/Baseline/Functional/BaselineRenameChannelsCommandTest.php`:
6 tests, 22 assertions, green. `phpstan` on the command, the refusal type, and
the test: 0 errors.

## Edit 3 — CHANGELOG entry

Draft came from `docs/internal/plans/rule-vocabulary/X10-freeze-and-carry/followups/c.md`
§C1. Checked its central claim against code rather than copying blind: read
`EntrySelector.php`, whose docblock states the selector is "a digest of the
*complete* identity — symbol, channel and edge" — confirms "a selector is a
digest of the identity, and the channel name is part of it." Draft text used
verbatim (it already matched measured behavior); added as the last bullet of
`### Changed` in `[Unreleased]`, immediately before `### Fixed`, matching the
surrounding entries' style (imperative command-first framing, backtick
command/option names, one paragraph).

## Deviations / decisions beyond the three literal instructions

1. Removed the `@qmx-threshold` annotation on `Baseline` (edit 1) rather than
   only rewriting its reason text — required for `bin/qmx directives` /
   `check:self` to stay meaningful once the annotation went inert. Named
   above, not silently folded in.
2. Left `BaselineLoader.php` and `BaselineWriter.php`'s coupling to `Baseline`
   untouched — they already depend on the type for construction, so the
   `Baseline::VERSION` → `BaselineFormatVersion::CURRENT` rename there is a
   pure rename, not an edge removal, and does not change their CBO edges.
3. Kept the malformed-`--format` case in `BaselineRenameChannelsCommand` at
   exit 2 — the brief said "four commands respond 1... bring to 1", and a
   literal reading could be misapplied to every early return in the file. The
   base class's own vocabulary (2 = uninterpretable value, 1 = understood but
   refusable) settles which of the two checks that phrase was actually about.

## Assumptions

- "The four existing baseline:* commands" in the brief means
  `baseline:generate`, `baseline:update`, `baseline:cleanup`,
  `baseline:explain` — confirmed via `grep -rn EXIT_INVALID_INPUT` and reading
  each command's `doExecute()`.
- `docs/internal/plans/rule-vocabulary/X10-freeze-and-carry/followups/c.md` is
  read-only reference material, not part of my file set to edit — left
  untouched.

## Unrelated concurrent changes observed in this working tree

While working, `git status` picked up modifications to files **outside my
task and outside anything I edited**: `scripts/finding-gate-controls/Controls.php`,
several `tests/Analysis/Evidence/CodeSmell/Unit/*Test.php` and
`tests/Analysis/Evidence/Security/Unit/*Test.php` files (new
`itKeysOccurrenceToItsOwnSmellType`-style pinning tests), plus two new
untracked files under `docs/internal/plans/rule-vocabulary/X10-freeze-and-carry/`
(`enumeration-smell-type-pins.tsv`, `followups/pins.md`). `stat` timestamps on
these files fall inside my own working window (19:15–19:23), interleaved with
my own edits — this is not something I created or modified; it looks like
another agent/process writing to this same non-isolated working tree
concurrently. **I did not touch, revert, or build on any of these files.**
`git diff --shortstat` on the full tree is contaminated by them; the diff
scoped to exactly the files this task's file set covers is:

```
14 files changed, 50 insertions(+), 44 deletions(-)
```

plus one new file, `src/Analysis/Policy/Baseline/BaselineFormatVersion.php`
(23 lines). Flagging this to you rather than silently working around it,
since it affects how `git diff --shortstat` reads and may need your attention
independent of this package.

## Files changed (mine only)

- `src/Analysis/Policy/Baseline/Baseline.php`
- `src/Analysis/Policy/Baseline/BaselineFormatVersion.php` (new)
- `src/Analysis/Policy/Baseline/BaselineChannelRenamer.php`
- `src/Analysis/Policy/Baseline/BaselineLoader.php`
- `src/Analysis/Policy/Baseline/BaselineWriter.php`
- `src/Analysis/Policy/Baseline/V5BaselineReader.php`
- `src/Analysis/Policy/Baseline/ChannelRenameRefusal.php`
- `src/Analysis/Policy/Baseline/README.md`
- `src/Infrastructure/Console/Command/BaselineRenameChannelsCommand.php`
- `tests/Analysis/Policy/Baseline/Unit/BaselineChannelRenamerTest.php`
- `tests/Analysis/Policy/Baseline/Integration/CaptureFromMeasuredSetTest.php`
- `tests/Analysis/Policy/Baseline/Functional/BaselineRenameChannelsCommandTest.php`
- `website/docs/usage/cli-options.md`
- `website/docs/usage/cli-options.ru.md`
- `CHANGELOG.md`
- `docs/internal/plans/rule-vocabulary/X10-freeze-and-carry/followups/fix.md` (this file)

## Commands to reproduce verification

```bash
# Edit 1
php bin/qmx check src/ --workers=0 --format=json   # coupling.cbo for Baseline: gone (was 21)
php bin/qmx check src/ --format=metrics --workers=0 # coupling.cbo: 19 for Qualimetrix\Analysis\Policy\Baseline\Baseline
composer architecture:check                         # expected failure: extra=[...BaselineFormatVersion]
php bin/qmx directives src/Analysis/Policy/Baseline/ # clean, 0 directives

# Edit 2
php bin/qmx baseline:rename-channels /tmp/nope.json /tmp/nope-map.tsv; echo $?  # 1

# Both
vendor/bin/phpunit tests/Analysis/Policy/Baseline
vendor/bin/phpstan analyse --memory-limit=1G \
  src/Analysis/Policy/Baseline/Baseline.php \
  src/Analysis/Policy/Baseline/BaselineFormatVersion.php \
  src/Analysis/Policy/Baseline/BaselineChannelRenamer.php \
  src/Analysis/Policy/Baseline/BaselineWriter.php \
  src/Analysis/Policy/Baseline/BaselineLoader.php \
  src/Analysis/Policy/Baseline/V5BaselineReader.php \
  src/Analysis/Policy/Baseline/ChannelRenameRefusal.php \
  src/Infrastructure/Console/Command/BaselineRenameChannelsCommand.php \
  tests/Analysis/Policy/Baseline/Unit/BaselineChannelRenamerTest.php \
  tests/Analysis/Policy/Baseline/Integration/CaptureFromMeasuredSetTest.php \
  tests/Analysis/Policy/Baseline/Functional/BaselineRenameChannelsCommandTest.php
```
