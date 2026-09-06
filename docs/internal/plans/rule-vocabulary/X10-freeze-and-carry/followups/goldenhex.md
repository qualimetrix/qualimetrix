# X10 golden-hash pin — report

## Gap closed

Every existing test in `tests/Analysis/Finding/Unit/OccurrenceKeyTest.php`
computed its expected value by calling `OccurrenceKey::semantic()` itself
(directly, or by reassembling the same payload/hash construction inline).
Confirmed by grep across the tree: no test pinned a literal hex string, so a
change to the hashing mechanism itself — payload shape, `ksort`, JSON flags,
or the `LENGTH` constant — could not have been caught; expectation and actual
would drift together.

## Fix

Added one test, `itPinsTheHashOfFixedInputsAsLiteralHexToCatchMechanismDrift`,
to `tests/Analysis/Finding/Unit/OccurrenceKeyTest.php`. Three fixed inputs,
each pinning a distinct part of the mechanism, expected values hardcoded as
hex literals (computed independently, once, and verified against the current
implementation before pinning — not derived from a second call to the method
under test):

- `dbbe0e35ed4a985b` — ordinary two-key evidence (`code-smell`,
  `{name: '_GET', type: 'superglobal'}`), keys already alphabetical. Pins the
  payload shape (`{"kind":...,"evidence":...}`) and the SHA-256 choice.
- `30e2c440d8e96d81` — same shape but evidence keys passed to `semantic()` in
  non-alphabetical order (`gamma, alpha, beta`). Pins that `ksort` runs before
  hashing, independent of input order.
- `e4e9e77c9057a83b` — one evidence set spanning bool, int, float, and string
  in a single payload, with the float a whole number (`2.0`). Pins
  `JSON_PRESERVE_ZERO_FRACTION`: without it, `2.0` encodes as `2` and the
  digest changes.

Each value's `strlen()` is also asserted at 16, independently of the literal
itself, so a `LENGTH` change is caught even if a shorter prefix happened to
still start with the same characters.

The test's docblock states the invariant explicitly: these values are
literals by design, because an expectation computed by the same
implementation cannot detect a change to the implementation, and changing
either the mechanism or these literals is a breaking change to every consumer
that persists this value across runs (the baseline file, and, through
`Finding::getFingerprint()`, GitLab and SARIF output).

## Mutation verification

Each mutation applied alone to `src/Analysis/Finding/Contract/OccurrenceKey.php`,
run against `tests/Analysis/Finding/Unit/OccurrenceKeyTest.php`, then reverted
before the next:

**(a) `LENGTH` 16 → 12:**
```
3 failures, including the new test:
itPinsTheHashOfFixedInputsAsLiteralHexToCatchMechanismDrift
Failed asserting that two strings are identical.
- 'dbbe0e35ed4a985b'
+ 'dbbe0e35ed4a'
```
(Two pre-existing tests also failed on this mutation — the strlen assertion
already present, and the pre-existing full-SHA256-prefix test. The new test
adds coverage on top, it wasn't the only thing that reddened here.)

**(b) removed `ksort($scalarEvidence);`:**
```
3 failures, including the new test:
itPinsTheHashOfFixedInputsAsLiteralHexToCatchMechanismDrift
Failed asserting that two strings are identical.
- 'dbbe0e35ed4a985b'
+ '28f7ec107c899c7b'
```

**(c) removed `JSON_PRESERVE_ZERO_FRACTION` from the `json_encode` flags:**
```
1 failure — only the new test:
itPinsTheHashOfFixedInputsAsLiteralHexToCatchMechanismDrift
Failed asserting that two strings are identical.
- 'e4e9e77c9057a83b'
+ '9ba28a978a478653'
```
This is the mutation that demonstrates the gap directly: none of the four
pre-existing tests noticed, because all of them (including the
full-SHA256-prefix test) recompute the payload with the same
`JSON_PRESERVE_ZERO_FRACTION` flag they're checking against — a flag change
in the class under test is silently mirrored into their own expected value.

All three mutations reverted; final state:
```
$ git diff --stat -- src/Analysis/Finding/Contract/OccurrenceKey.php
(empty)
$ vendor/bin/phpunit tests/Analysis/Finding/Unit/OccurrenceKeyTest.php
OK (5 tests, 12 assertions)
```

`git status --porcelain -- src/` at the end of this task shows only
pre-existing, unrelated modifications under `src/Analysis/Policy/Baseline/`
and `src/Infrastructure/Console/Command/BaselineRenameChannelsCommand.php`
— a concurrent package already documented in
`docs/internal/plans/rule-vocabulary/X10-freeze-and-carry/followups/fix.md`
("Unrelated concurrent changes observed in this working tree"). Nothing under
`OccurrenceKey.php` or any other file was left modified by this task.

## Files changed

- `tests/Analysis/Finding/Unit/OccurrenceKeyTest.php` — added the golden test
- `docs/internal/plans/rule-vocabulary/X10-freeze-and-carry/followups/goldenhex.md` (this file)

`src/` was not modified (mutations applied and reverted for verification only).

## Commands to reproduce

```bash
vendor/bin/phpunit tests/Analysis/Finding/Unit/OccurrenceKeyTest.php

# Mutation (a) — LENGTH
sed -n '26p' src/Analysis/Finding/Contract/OccurrenceKey.php   # confirm baseline: 16
# edit LENGTH to 12, rerun the test above (expect failures), revert to 16

# Mutation (b) — ksort
grep -n 'ksort' src/Analysis/Finding/Contract/OccurrenceKey.php
# remove that line, rerun the test above (expect failures), restore the line

# Mutation (c) — JSON_PRESERVE_ZERO_FRACTION
grep -n 'JSON_PRESERVE_ZERO_FRACTION' src/Analysis/Finding/Contract/OccurrenceKey.php
# remove the flag from the json_encode() call, rerun (expect exactly 1 failure), restore
```
