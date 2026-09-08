# Freeze coverage: the twelve leaf-keyed families X10 did not reach

## What was not covered

X10 froze the `OccurrenceKey` discriminator in six families by giving each a
private `OCCURRENCE_KIND` constant holding the channel code, and marked those
places in `enumeration-renames.tsv` with the derived `frozen_kind`/`frozen_pin`
columns, so the final rename step's mechanical sweep knows which occurrences it
must not touch.

Twelve further channels key `occurrence` through a different mechanism, and
neither column saw them. `AbstractCodeSmellRule` and
`AbstractSecurityPatternRule` pass a `SMELL_TYPE`/`PATTERN_TYPE` constant into
`OccurrenceKey::semantic()` through their finding VO. The affected channels:

- `code-smell.boolean-argument`, `code-smell.count-in-loop`,
  `code-smell.debug-code`, `code-smell.empty-catch`,
  `code-smell.error-suppression`, `code-smell.eval`, `code-smell.exit`,
  `code-smell.goto`, `code-smell.superglobals`
- `security.command-injection`, `security.sql-injection`, `security.xss`

The constant holds the channel's **leaf in snake_case** (`'eval'`,
`'sql_injection'`), not the channel code, so nothing in its text says which
channel it belongs to and no spelling-based match finds it.

## What measured it

A consistent rename of one family's leaf, `SMELL_TYPE = 'eval'` to `'evalX'`,
applied to every place that spells it: the declaration in `EvalRule.php`, the
type list in `CodeSmellCollector.php`, the producing literal in
`CodeSmellVisitor.php`, and the `'codeSmell.eval'` bag keys and expected
literals in four test files — 24 changed lines across 7 files.

Result: `vendor/bin/phpunit --no-coverage` reported **8174 tests, exit 0,
green**, while the discriminator moved:
`OccurrenceKey::semantic('eval', [...])` = `cb4db382fbd60b86` before,
`OccurrenceKey::semantic('evalX', [...])` = `044e26abaa4cfd73` after. That is a
silent move of `occurrence` under every already-accepted baseline entry, GitLab
fingerprint and SARIF `partialFingerprints` value on the channel.

An *incomplete* mutation (src plus one test file) reddened 8 tests — figure
carried over from the measurement that opened this followup, not re-measured
here, and no longer reproducible as stated now that the guard below adds two
reds of its own to any such mutation. Only the complete, self-consistent sweep
was invisible — and the complete sweep is exactly what the final rename step
performs.

## What is covered now

- `scripts/generate-rename-enumeration.php` emits two further derived columns,
  `leaf_const` and `leaf_pin`, nonzero on exactly those twelve channel rows.
  `leaf_const` counts the `SMELL_TYPE`/`PATTERN_TYPE` declaration; `leaf_pin`
  counts the `itKeysOccurrenceToItsOwnSmellType`/`...PatternType` test method
  asserting it. The leaf is bound to its channel by the same-file co-location
  of `public const string NAME = '<channel>'` with the leaf constant — derived
  from source text on every run, with no hand-kept list of classes, files or
  pairs. Every way that derivation could under-report throws: a leaf constant
  with no `NAME` to bind to, two files claiming one channel, two channels
  sharing one leaf, a pin asserting a leaf no file declares.
- `tests/Analysis/Finding/Integration/OccurrenceLeafFreezeGuardTest.php`
  re-derives the twelve independently on every `composer test` run and fails if
  the count drifts, if a declared leaf stops matching its hardcoded pin, or if
  the set of leaves the pin tests assert stops matching the pinned set
  one-for-one. Under the `eval` mutation above, both of its assertions redden.
- `BooleanArgumentRule` and `CommandInjectionRule` had no named pin at all
  (10 pins for 12 families); both now have one. Their literals were already
  asserted inside broad behaviour tests, but a literal buried among a dozen
  field checks is invisible to a scan that finds pins by method name, which is
  how both the generator and the guard find them.

Note that these two columns mark a **different** danger from
`frozen_kind`/`frozen_pin`. The six frozen literals *are* among their row's
counted `src`/`tests` occurrences, so a mechanical whole-identifier sweep would
rename them. A leaf is counted **nowhere** in its row — the row's search
identifier is `code-smell.eval`, and neither `'eval'` nor `codeSmell.eval`
matches it. The mechanical sweep leaves leaves alone by construction; what
moves them is a *consistency* rename done by hand ("the channel is now
`code-smell.dynamic-eval`, so the constant should read `dynamic_eval`"). The
columns exist so the rename step knows that temptation is a breaking change.

## What this method does not see

- **Only the two shapes that exist today.** The generator and the guard both
  read `const string SMELL_TYPE|PATTERN_TYPE = '<literal>'` and a pin method
  named `itKeysOccurrenceToItsOwn(Smell|Pattern)Type`. A thirteenth family that
  keyed `occurrence` off some third mechanism would be invisible to both, in
  exactly the way these twelve were invisible before. The guard's fixed count
  of twelve makes a family joining or leaving *these* shapes loud; it says
  nothing about a shape nobody has written yet.
- **The binding is a co-location convention, not a language guarantee.** A
  family that split `NAME` and its leaf constant across two files, or computed
  either from parts, would not bind. That case fails loudly rather than
  reporting zero — but the shape being read is the one every family happens to
  use, not a rule the language enforces.
- **Neither column says WHERE.** `leaf_const`/`leaf_pin` name which channels
  carry a protected leaf and how many places, not file:line. Locating them for
  one channel still needs `grep -rn "SMELL_TYPE = '<leaf>'"` (or
  `PATTERN_TYPE`) plus the leaf's entry in `CodeSmellCollector::SMELL_TYPES` /
  `SecurityPatternCollector::PATTERN_TYPES`, its producing literal in the
  capability's visitor, and the `'codeSmell.<leaf>'` / `'security.<leaf>'` bag
  keys throughout `tests/` — none of which either column counts.
- **The guard proves the value did not move, not that it still reaches
  `occurrence`.** That second half is what the twelve per-family pin tests do,
  each running its own rule's `analyze()`. The guard checks the pins exist and
  still name the frozen leaves; if the pins themselves were rewritten to expect
  the channel code — the shape a "make the base key on `NAME`" refactor would
  produce — the guard's pin-set comparison is what catches it, and nothing
  below that.
- **The values are pinned by literal on purpose.** After the rename step a leaf
  will read differently from its channel's `NAME`. That divergence is the
  freeze working as designed. Respelling a constant to restore consistency, or
  re-pinning the guard to match a changed constant, retires the freeze silently
  through the exact door it exists to hold shut.
