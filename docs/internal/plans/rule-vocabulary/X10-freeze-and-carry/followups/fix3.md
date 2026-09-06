# X10 fix pass 2 — report

Two findings from `/tmp/x10-review2/native.md`: claude-02 (golden-pin gap,
`JSON_UNESCAPED_SLASHES` unpinned) and claude-03 (`enumeration-renames.tsv`
counts frozen occurrences as ordinary rename targets). Both fixed. File set:
`scripts/generate-rename-enumeration.php`,
`docs/internal/plans/rule-vocabulary/enumeration-renames.tsv` (via
regeneration), `tests/Analysis/Finding/**`, this file. `src/**` was touched
twice, each time as a throwaway mutation reverted in the same step to prove a
test reddens — no net change; see "Mutation proofs" below.

## claude-02 — `OccurrenceKeyTest` golden pin, JSON flag gap

**Confirmed the finding first.** The three existing golden literals in
`itPinsTheHashOfFixedInputsAsLiteralHexToCatchMechanismDrift()` contain no `/`
character, so `JSON_UNESCAPED_SLASHES` never has anything to escape or not —
removing the flag from `OccurrenceKey::semantic()` cannot move any of the
three hashes.

**Fix:** added a fourth case, `$unescapedSlash`, using the exact production
shape `RepeatedExpressions::binaryFinding()` emits for `$a / $a`
(`'... / ...'`) under the frozen `code-smell.identical-subexpression` channel
— realistic evidence, not an invented string. Docblock updated to name what
this fourth case pins and to state the un-escaped-form hash
(`1a1f35ec7f642d86`) the mutation below reproduces.

**Mutation proof (required by the task):**
1. Edited `src/Analysis/Finding/Contract/OccurrenceKey.php`, removed
   `\JSON_UNESCAPED_SLASHES` from the `json_encode()` flags.
2. `vendor/bin/phpunit tests/Analysis/Finding/Unit/OccurrenceKeyTest.php` —
   **reddened**, exactly on the new case: expected `1f87f4725f39c69f`, got
   `1a1f35ec7f642d86` (the value the finding's own evidence section names).
   The other three cases stayed green, confirming they still prove nothing
   about this flag.
3. Reverted (`mv OccurrenceKey.php.bak OccurrenceKey.php`), re-ran — green
   again, `git diff` on `src/` clean.

## claude-03 — `enumeration-renames.tsv` does not mark frozen occurrences

**Confirmed the finding first.** `grep -rn OCCURRENCE_KIND src/` found exactly
the six constants `01-freeze-kind.md`'s table names
(`HardcodedCredentialsRule`, `SensitiveParameterRule`, `CircularDependencyRule`,
`IdenticalSubExpressionRule`, `CodeDuplicationRule`, `LayerViolationFinding`),
each a private literal equal to that family's channel code, and six
`itKeysOccurrenceToTheFrozenChannelSpelling*` pin tests, one per family,
each asserting the same literal via `OccurrenceKey::semantic('<literal>', ...)`.
The generator's existing occurrence count folds both into the ordinary `src`/
`tests` column totals with no distinguishing mark — confirmed against a fresh
`php scripts/generate-rename-enumeration.php` run before touching the script.

**Decision: derived columns, not a hand-kept list.** The task allowed two
shapes — a marked column/section, or an exclusion with a footer note — and
asked that whichever shape is chosen be *derivable from code*, not a written
list that drifts the next time a family is frozen. Went with two new derived
columns, `frozen_kind` and `frozen_pin`, appended to every `channel` row
(`0` for `producer`/`metric-key` rows, which can never be a `kind` argument):

- `frozen_kind` — count of `private const string OCCURRENCE_KIND = '<this
  row's literal>';` declarations found by scanning current `src/` text. New
  function `frozenKindLiterals()`.
- `frozen_pin` — count of `itKeysOccurrenceToTheFrozenChannelSpelling*` test
  method **declarations** (matched by `function itKeys...\(\)`, never a
  docblock or comment merely naming the method — the first version of this
  regex false-positived on this very file's own docblock, fixed by requiring
  the `function` keyword) whose body calls
  `OccurrenceKey::semantic('<this row's literal>', ...)` within a bounded
  window. New function `frozenPinLiterals()`.

Both scan the **current source text** on every run — nothing here mirrors the
plan's class list by hand. A seventh family frozen the same way is picked up
automatically the next time the script runs; a family whose freeze regresses
(constant rewritten as `self::NAME`, pin deleted) silently drops its count
back to `0` instead of staying flagged by a stale hand-written marker. This is
the same shape the file already uses for the channel/producer/metric-key sets
themselves (measured from the container, never enumerated by hand) — the fix
extends that principle to the one class of occurrence the freeze plan singled
out, rather than introducing a second way of marking things.

**Why not per-occurrence file:line locations.** Considered producing an exact
list (file, line) instead of a count. Rejected for cost/value: the surface
readers concatenate every file's content per surface for the existing
whole-identifier count, so locating an exact line would need a parallel
per-file pass duplicating that machinery for a benefit the footer note below
already gets without it — a sweep only needs to know *which* channels carry
protected occurrences and *that* it must `grep` for them before touching
anything; it does not need this file to hand it pre-computed coordinates.
Named explicitly in the new footer section and in the "WHAT THIS METHOD DOES
NOT SEE" list: `frozen_kind`/`frozen_pin` name which channels and how many,
not where — the concrete `grep` commands for finding them are spelled out in
the footer instead.

**Footer additions:**
- A new section, right after the `new`/`step` decision-column explanation,
  states in imperative terms that a row with `frozen_kind` or `frozen_pin`
  above zero **must not** have every one of its counted occurrences renamed
  by a mechanical sweep — the exact "не переименовывать" sentence the task
  asked for, as a property of the artifact rather than of whoever reads it.
  It also names the durable guard (below) as the second line of defense.
- A new bullet in "WHAT THIS METHOD DOES NOT SEE" states the file:line
  limitation and gives the two `grep` commands a sweep still needs to run.
- The row-count line now reports how many channel rows carry a nonzero frozen
  column.

**Regeneration, not hand edit.** Ran `php scripts/generate-rename-enumeration.php`
(not `--check`) to rewrite `enumeration-renames.tsv`; `--check` afterward
reports up to date. Verified all six and only six channel rows carry
`frozen_kind=1 frozen_pin=1`:
`architecture.circular-dependency`, `architecture.layer-violation`,
`code-smell.identical-subexpression`, `duplication.code-duplication`,
`security.hardcoded-credentials`, `security.sensitive-parameter` — the exact
six 01-freeze-kind.md names. Every other row carries `0 0`. The regeneration
also picked up an unrelated, already-pending `tests` count change
(`code-smell.identical-subexpression` 6→7, `code-smell.goto` and
`complexity.cyclomatic` bumped) — the first is this package's own new
golden-pin case (claude-02 fix above); the other two come from separate
uncommitted work already present in the working tree before this package
started (`git status` showed modified `Baseline*` files and their tests,
unrelated to X10's occurrence freeze). Not touched, not reverted — out of
this package's file set.

## Durable guard: `OccurrenceKindFreezeGuardTest`

The task named this as more important than the marking. New file
`tests/Analysis/Finding/Integration/OccurrenceKindFreezeGuardTest.php`
(alongside the existing `RuleIdentifierLiteralGuardTest` in the same
directory — a cross-capability, container/reflection-driven guard, not a
per-rule unit test, so `Integration/` under `Finding` is where it belongs;
Finding owns `OccurrenceKey` itself).

**What it asserts**, re-derived on every `composer test` run rather than
trusted from memory:
1. Scans `src/` for `private const string OCCURRENCE_KIND = '<literal>';`
   (same shape and regex the generator uses) and asserts the count is
   exactly six — a class that rewrites its constant as `self::NAME` (or any
   other expression) drops out of this scan and reddens the count assertion.
2. For each found declaration, reflects the owning class and asserts the
   runtime constant value still equals the source-text literal (guards
   against the regex and the runtime value silently disagreeing).
3. Asserts the constant still equals its rule's own channel code: for five
   families that is `SameClass::NAME`; for `LayerViolationFinding` — the one
   family whose constant freezes a channel it does not itself name — it is
   `LayerViolationRule::NAME`, stated by an explicit two-entry map
   (`CHANNEL_CODE_OWNER`) that names only *which* class to compare against,
   never the value being compared, which is always read fresh by reflection.

**Mutation proof (required by the task):**
1. Edited `src/Analysis/Evidence/Security/HardcodedCredentialsRule.php`,
   replaced `private const string OCCURRENCE_KIND = 'security.hardcoded-credentials';`
   with `private const string OCCURRENCE_KIND = self::NAME;`.
2. `vendor/bin/phpunit tests/Analysis/Finding/Integration/OccurrenceKindFreezeGuardTest.php`
   — **reddened**: "Expected exactly 6 declarations ... Found 5" (the
   `self::NAME` form no longer matches the literal-declaration regex, so the
   scan silently lost this family instead of comparing a value — exactly the
   regression class the task described).
3. Reverted (`mv HardcodedCredentialsRule.php.bak HardcodedCredentialsRule.php`),
   re-ran — green again, `git diff` on `src/` clean.

## Verification

- `vendor/bin/phpunit tests/Analysis/Finding/` — 838 tests, 3921 assertions,
  green (includes both touched files and the new guard).
- `vendor/bin/phpunit tests/Analysis/Finding/Integration/RuleIdentifierLiteralGuardTest.php`
  — still green: the six frozen constants live in their own capability's
  files, so the literal-ownership guard stays silent as `01-freeze-kind.md`
  predicted.
- `vendor/bin/phpstan analyse scripts/generate-rename-enumeration.php
  tests/Analysis/Finding/Integration/OccurrenceKindFreezeGuardTest.php
  tests/Analysis/Finding/Unit/OccurrenceKeyTest.php --memory-limit=1G` —
  no errors (two findings fixed along the way: a disallowed short ternary,
  a useless cast PHPStan flagged once type narrowing was visible).
- `vendor/bin/php-cs-fixer fix` — applied once to the new test file
  (`\`-prefixed global function calls); re-run confirms clean.
- `php scripts/generate-rename-enumeration.php --check` — up to date.
- `php scripts/generate-rename-enumeration.php --runtime-channels --check` —
  up to date (unaffected by this package, checked for regression only).

## Follow-up: the guard's second check was itself a trap

Reported after this file's original text above was written: the guard's third
assertion — constant equals `SameClass::NAME` (or `LayerViolationRule::NAME`
via `CHANNEL_CODE_OWNER`) — encoded the exact mistake the freeze exists to
prevent. The freeze's whole point is that a future channel rename (X10's own
plan, step П4) changes `NAME` while `OCCURRENCE_KIND` must NOT follow it, to
keep `occurrence` stable under every baseline entry, GitLab fingerprint and
SARIF `partialFingerprints` value already accepted against these six findings.
Comparing against `NAME` therefore reddens **by design** the day П4 lands, and
its message — "OCCURRENCE_KIND no longer equals NAME — the freeze has drifted
from the channel it was frozen against" — reads as an instruction to bring the
constant back in line with `NAME`. Following that instruction is precisely the
regression the guard was written to catch, done by the guard's own hand.

**Fix:** replaced the `NAME`-comparison assertion with a literal pin,
`FROZEN_SPELLING` (`array<class-string, string>`, one entry per family, the
six current spellings written directly — not derived from any `NAME`
constant). The assertion now compares each declaration's runtime value against
its own pinned entry, and separately flags a class found in `src/` scanning
but absent from `FROZEN_SPELLING` (covers a family swapping its owning class
without changing the count). `CHANNEL_CODE_OWNER` and the `LayerViolationRule`
import it existed for are gone — there is no second class to compare against
once the comparison target is a literal, not a class's `NAME`. Renamed the
test method from `...EqualToItsRulesChannelCode` to
`...MatchingItsPin` since it no longer touches any rule's channel code at all.
Docblocks on `FROZEN_SPELLING` and the mismatch message now state explicitly:
divergence from `NAME` after a channel rename is the freeze working as
intended, not a defect, and repairing a pin mismatch by copying the current
runtime value into the pin — rather than treating it as a breaking change to
baseline/GitLab/SARIF — is exactly the wrong response.

The first two assertions (declaration count == 6; runtime value == source-text
literal) are unchanged — they were correct, not part of the trap.

**Mutation proofs (required by the task), all three run against
`HardcodedCredentialsRule`, one at a time, each reverted before the next:**

1. **`self::NAME` rewrite** — replaced
   `private const string OCCURRENCE_KIND = 'security.hardcoded-credentials';`
   with `private const string OCCURRENCE_KIND = self::NAME;`. Guard **red**:
   "Expected exactly 6 declarations ... Found 5" — the literal-declaration
   regex no longer matches, same failure mode as the original mutation proof
   in this file. Reverted; `vendor/bin/phpunit
   tests/Analysis/Finding/Integration/OccurrenceKindFreezeGuardTest.php` green
   again.
2. **Literal rewrite** — changed the same constant's value to
   `'security.hardcoded-credentials-x'` (declaration and runtime both change
   together, so the first two assertions still pass). Guard **red**, on the
   new pin check: `OCCURRENCE_KIND is now "security.hardcoded-credentials-x"
   but the frozen pin says "security.hardcoded-credentials"` plus the
   breaking-change wording. Reverted; guard green again.
3. **`NAME` rename, constant untouched** — changed only
   `HardcodedCredentialsRule::NAME` to
   `'security.hardcoded-credentials-renamed'`, leaving `OCCURRENCE_KIND`
   exactly as declared. This is the case the fix exists to prove: under the
   old `NAME`-comparison assertion this would have reddened; under the pin it
   must not, because `OCCURRENCE_KIND` did not move. Ran the guard filtered
   (`vendor/bin/phpunit --filter OccurrenceKindFreezeGuardTest
   tests/Analysis/Finding/Integration/OccurrenceKindFreezeGuardTest.php`, to
   isolate from any other test that might read `NAME`) — **green**, 1 test, 2
   assertions. Reverted; `git diff
   src/Analysis/Evidence/Security/HardcodedCredentialsRule.php` clean.

**Verification:** `vendor/bin/phpunit
tests/Analysis/Finding/Integration/OccurrenceKindFreezeGuardTest.php
tests/Analysis/Finding/Integration/RuleIdentifierLiteralGuardTest.php` — 4
tests, 319 assertions, green. `vendor/bin/phpstan analyse
tests/Analysis/Finding/Integration/OccurrenceKindFreezeGuardTest.php
--memory-limit=1G` — no errors. `vendor/bin/php-cs-fixer fix
tests/Analysis/Finding/Integration/OccurrenceKindFreezeGuardTest.php --diff`
— no changes needed. `git diff --shortstat` for this follow-up touches only
the test file (no `src/` net change — every mutation above was reverted in
the same step it was proved).

## Not done / left as-is

- The pre-existing uncommitted `Baseline*` changes in the working tree
  (outside this package's file set) were left untouched; they are the
  source of the two unrelated `tests` column bumps noted above.
- No opinion offered on whether a seventh family should ever be frozen this
  way — `EXPECTED_FROZEN_COUNT = 6` in the guard test is a deliberate,
  visible tripwire: growing the frozen set is a decision for
  `01-freeze-kind.md`, not something this guard should absorb silently.
