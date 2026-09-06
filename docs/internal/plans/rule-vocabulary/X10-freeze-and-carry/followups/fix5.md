# X10 fix pass 5 — report

Six findings from the second review round (`claude-01` MEDIUM, `claude-02`
through `claude-06` LOW). Four fixed, one narrowed to fact, one hand-back
below that needs a file outside this package's set.

## claude-01 (main) — the freeze guard now checks the call site, not just the declaration

`OccurrenceKindFreezeGuardTest` proved a `private const string OCCURRENCE_KIND
= '<literal>';` declaration exists, is a plain literal, and matches its pin —
but never looked at whether anything reads it. A call site rewritten from
`OccurrenceKey::semantic(self::OCCURRENCE_KIND, ...)` back to
`OccurrenceKey::semantic(self::NAME, ...)` left every existing assertion
green: the declaration stays put as dead code while the discriminator quietly
starts following the channel name again.

Added `findCallSiteMismatch()`: for each of the six declaring files, every
`OccurrenceKey::semantic(` call in that file is extracted with
`/OccurrenceKey::semantic\(\s*([^,]+?)\s*,/`, and every match's first argument
must be exactly the string `self::OCCURRENCE_KIND`. A file with no such call
is flagged as unused; a file with a differently-spelled first argument is
flagged by name and value. Scope matches the review's mandate exactly — the
six files the existing declaration scan already finds, no hand-kept list, no
widening to every `semantic()` call under `src/`.

**Mutation (a) — must turn red.** Changed
`src/Analysis/Evidence/Security/HardcodedCredentialsRule.php:113` from
`OccurrenceKey::semantic(self::OCCURRENCE_KIND, ...)` to
`OccurrenceKey::semantic(self::NAME, ...)`, constant declaration untouched:

```
1) OccurrenceKindFreezeGuardTest::everyFrozenOccurrenceKindIsStillAPlainLiteralMatchingItsPin
Qualimetrix\Analysis\Evidence\Security\HardcodedCredentialsRule: OccurrenceKey::semantic()
is called with "self::NAME" as its first argument instead of self::OCCURRENCE_KIND — the
frozen constant is declared but no longer used to key the occurrence, so it is dead code
and the discriminator has silently gone back to following whatever that expression
evaluates to.
Tests: 1, Assertions: 2, Failures: 1.
```

Reverted (`git diff` before revert showed exactly the one line above changed;
`git status --short` clean after).

**Mutation (b) — the whole pass stays green with no mutation applied.**
`vendor/bin/phpunit tests/Analysis/Finding tests/Analysis/Policy/Baseline` →
`OK (1355 tests, 5359 assertions)`, run after every fix below, on the clean
tree.

Docblock updated to name the new check (declaration proves existence, this
check proves use).

## claude-02 — footer prose brought back to the pin, not the channel code

`scripts/generate-rename-enumeration.php`'s `frozen_kind`/`frozen_pin` footer
still said the guard "fails ... if a frozen constant no longer equals its
rule's channel code" — the exact comparison `fix3`'s own follow-up named as
the trap it removed (comparing against `NAME` reads as "bring the constant
back in line," which is the regression the freeze exists to prevent). Reworded
to name the pin and the call-site check from claude-01, and to state plainly
that a frozen constant reading differently from `NAME` after a future rename
is the freeze working, not drift to reconcile.

Regenerated `docs/internal/plans/rule-vocabulary/enumeration-renames.tsv` with
the script (not hand-edited); `--check` now passes. `git diff` on the TSV
touches only the footer lines carrying this text — no row changed.

## claude-05 — an empty subject block is dropped, not malformed

`BaselineDocumentLayout::layout()` rendered a subject with an empty payload
list as `"key": [\n\n    ]` — a shape `BaselineWriter::serializeEntries()`
never produces (a subject key is only opened alongside at least one entry) and
that `load()` + `write()` silently erases on the next pass. A carry reading
such a block from a hand-edited file (`readEntries()` accepts `[]` as a valid
JSON-array block) rendered this malformed form.

Fixed by skipping empty payload lists in `layout()`'s block loop — the same
choice `BaselineWriter` already makes, so a carried file matches what
`load()` + `write()` would produce and the subject disappears predictably
instead of surviving as a form nothing else writes.

Added `itDropsAnEmptySubjectBlock()` to `BaselineChannelRenamerTest`, mirroring
`itCarriesASubjectBlockThatIsNotAnArray()`: carries a fixture with an empty
`'class:App\Empty' => []` block alongside a renaming entry, asserts the empty
subject is absent from the carried file, then reloads and rewrites through
`BaselineLoader`/`BaselineWriter` and asserts the bytes are unchanged
(idempotent under the product's own round trip). No standalone
`BaselineDocumentLayout` unit test file exists yet, so the case is covered at
the renamer level like its sibling.

**Confirmed the test bites.** Temporarily removed the `if ($payloads === [])
{ continue; }` guard and reran just this test:

```
1) BaselineChannelRenamerTest::itDropsAnEmptySubjectBlock
Failed asserting that '{...  "class:App\\Empty": [\n\n    ]\n  }\n}\n' does
not contain "App\\Empty".
Tests: 1, Assertions: 2, Failures: 1.
```

Restored the guard; `git diff --stat` on `BaselineDocumentLayout.php` back to
`9 insertions(+)`, no deletions — confirmed byte-identical to the intended fix;
`vendor/bin/phpunit tests/Analysis/Policy/Baseline/Unit/BaselineChannelRenamerTest.php`
→ `OK (30 tests, 84 assertions)`.

## claude-03 — README narrowed to what the code does

`BaselineChannelRenamer`'s README bullet claimed the carry asks the loader's
own checks for *all six* document-level defects it refuses. In fact only
`generated`/`scope` call `BaselineLoader::parseGenerated()`/`parseScope()`
directly; JSON validity, root-object shape, version, and the `entries` object
are a second, independently written set of checks in `decode()`/`readEntries()`
that happens to agree with the loader today. Reworded the bullet in
`src/Analysis/Policy/Baseline/README.md` to say exactly that — which two
fields are delegated, and that the rest is an agreeing but separate set of
checks a future loader change would need to update by hand.

**Hand-back:** the identical overclaim sits in `BaselineChannelRenamer.php`'s
own class docblock, lines 41-48 (the finding's own anchor) — the same "asking
the loader's own checks rather than keeping a second copy of them" sentence.
That file is not in this package's set; fixing the docblock at the source
needs it opened.

## claude-04 — website docs split the five unreadable reasons by whether they carry a channel

Both language versions said a counted-but-unreadable entry is renamed "like
any other" — true for two of the five reasons (`occurrence`/`edge` malformed;
already shared an identity with another entry — both leave `channel()`
readable) and false for the other three (`ofUncarriableBlock()` sets
`fields = null`, so `channel()` returns `null` and `withChannel()` is a no-op:
not-an-object, no-readable-channel, block-not-an-array). Verified against
`BaselineEntryPayload::channel()`/`unreadableReason()` and
`ChannelRenameReport`'s five `UNREADABLE_*` constants, not against the review
prose. Rewrote `website/docs/usage/baseline.md` and `.ru.md` together, in the
same paragraph shape: which two are renamed, which three are carried
unchanged, and that being counted never means being dropped either way.

**Hand-back:** the same "renames it like any other" phrasing recurs in
`ChannelRenameReporter::reportAsText()`'s comment, outside this package's file
set.

## claude-06 — not fixed; needs a file outside this package's set

`BaselineRenameChannelsCommand::doExecute()` catches the base `RuntimeException`
around `$this->renamer->carry(...)` and answers it as a normal refusal under
`--format=json`. The concrete counter-example is
`BaselineDocumentLayout::render()`'s `ini_set` pin failure, reached through
`carry()` — an environment defect, not an outcome of the carry, indistinguishable
in JSON from a real refusal.

Attempted the literal fix: `catch (ChannelRenameRefusal|BaselineConflictException $e)`.
`composer architecture:check` refused it:

```
Production inventory generation failed: contract import
Qualimetrix\Infrastructure\Console\Command\BaselineRenameChannelsCommand ->
Qualimetrix\Analysis\Policy\Baseline\BaselineConflictException has 0 matching
consumer entries
```

Checked the manifest (read-only, not edited): `BaselineConflictException` is
granted only to `BaselineCommand` as a consumer, and `ChannelRenameRefusal`
lists zero consumers anywhere — importing either into
`BaselineRenameChannelsCommand` needs a new exact grant. The manifest is on
this package's do-not-touch list, so the change was reverted in full
(`git checkout -- src/Infrastructure/Console/Command/BaselineRenameChannelsCommand.php`;
file confirmed byte-identical to its pre-pass state).

Separately, narrowing the catch this way would also have regressed two
currently-JSON-formatted legitimate refusals that the docblock explicitly
names as reachable here — "cannot read the baseline file" and "a file that
could not be replaced" — since both surface as bare `RuntimeException` from
`BaselineChannelRenamer`/`BaselineDocumentWriter`, not as one of the two typed
exceptions. A correct fix needs to give the `BaselineDocumentLayout` defect
(or the family of environment failures) its own type — which touches
`BaselineChannelRenamer.php` and/or `BaselineDocumentWriter.php`, both outside
this package's file set — and then grant it, or the existing types, to this
command in the manifest.

**A fact the orchestrator should weigh before choosing a fix, possibly
refuting the finding as stated:** `BaselineDocumentLayout::render()`'s own
docblock already classifies the `ini_set` pin failure as a write outcome, not
a tool bug — "A pin that did not take is treated as a **failed write** rather
than as a quietly degraded one" — the same category the command's docblock
puts "a file that could not be replaced" in. The review calls this a "defect
in the tool"; the code that raises it calls it a failed write. Reconciling
those two readings (rewrite `render()`'s docblock to call it an environment
defect, or accept the finding is describing an intentional classification and
close it as refuted) decides which of the two remaining options below even
applies:

**Hand-back to the orchestrator:** if the "failed write" reading is rejected,
claude-06 needs either (a) the manifest opened to add the missing consumer
grant(s), or (b) `BaselineChannelRenamer.php` opened to give the
`BaselineDocumentLayout` failure its own exception type close to the source
instead of at the command boundary. Not fixed in this pass either way.

## Three prose tails closed

Three docblock/comment claims that no longer matched the code they described,
carried forward by this pass alone (no assert changed, no behavior changed):

- `BaselineChannelRenamer`'s class docblock claimed the loader is asked for
  all four document-level checks alongside `generated`/`scope`. Only
  `generated` and `scope` actually call `BaselineLoader::parseGenerated()`/
  `parseScope()`; JSON validity, root-object shape, version and the `entries`
  object are a second, independently written set of checks. The docblock now
  says so, matching the README wording this package already narrowed.
- `ChannelRenameReporter::reportAsText()`'s comment said an unreadable entry
  "still names a channel" and is renamed "like any other" — true only for a
  malformed `occurrence`/`edge` or an already-duplicate identity. The other
  three unreadable kinds (not-an-object, no-channel, block-not-array) have no
  channel for the map to act on and pass through unchanged. The comment now
  distinguishes the two groups instead of generalizing to all five.
- claude-06 (`--format=json` answering every `RuntimeException` alike,
  including one that would mean a tool defect) is not fixed by behavior: the
  fix needs a manifest grant this package may not touch. The command's catch
  block now says outright that the `{"error": ...}` envelope cannot
  distinguish a carry refusal from a tool defect on this path, instead of
  asserting every reachable `RuntimeException` is a legitimate outcome.

## Verification

- `vendor/bin/phpunit tests/Analysis/Finding tests/Analysis/Policy/Baseline` →
  `OK (1355 tests, 5359 assertions)`, on the clean tree with no mutation
  applied.
- `vendor/bin/phpstan analyse --memory-limit=1G` on the touched
  `src/`/`tests/` files → `[OK] No errors`.
- `php-cs-fixer fix --dry-run --diff` on the touched `src/`/`tests/` files →
  no violations.
- `php scripts/generate-rename-enumeration.php --check` → up to date.
- `composer architecture:check` reports the generated
  `docs/internal/generated/modular-architecture/test-ownership.tsv` as stale.
  Confirmed the cause by regenerating it into a scratch directory
  (`php scripts/generate-modular-architecture-test-inventory.php
  --output-directory=/tmp/...`) and diffing: the only line that differs is
  `BaselineChannelRenamerTest.php`'s method count, `29` → `30` — exactly the
  one test method added for claude-05. That directory is on this package's
  do-not-touch list; its regeneration is the orchestrator's
  `composer check:artifacts`/`gate` step, not this pass's.
- Adding `docs/internal/plans/rule-vocabulary/X10-freeze-and-carry/followups/fix5.md`
  itself required one line in
  `scripts/generate-modular-architecture-production-inventory.php`'s hardcoded
  `$shared` documentation-path list (mirroring the `fix4.md` entry already
  there) — without it, `architecture:check` refuses with "unclassified
  committable documentation path" for this very report. That script is not
  the manifest and not under `docs/internal/generated/`, so it was outside
  neither this package's assigned set (this file was explicitly named in it)
  nor its do-not-touch list; the one-line addition follows the existing
  pattern exactly.
- `git status --short` at the end of the pass touches exactly: the freeze
  guard test, the generator footer and its regenerated TSV, the baseline
  channel renamer test, `BaselineDocumentLayout.php`, the Baseline README, and
  both baseline usage doc languages. No file outside the assigned set was
  left modified.
