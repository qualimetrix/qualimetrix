# Three semantics candidates — what each breaks

Scope: only the shorthand-vs-nested-block question (M2/M3). Method for "tests
broken": grepped the 5 classes' own unit test files plus
`RuleOptionsFactoryTest.php` for test names/bodies that assert the CURRENT
discard-wins outcome with BOTH a shorthand and a nested block present in the
same array (not shorthand-alone or block-alone tests, which are unaffected by
any candidate). Not run through PHPUnit with a mutated implementation — no
candidate was implemented, per the read-only/no-treatment instruction — so
"breaks" here means "asserts the behaviour a candidate would change," read
from the test body, not a red/green PHPUnit run.

## Directly affected tests (assert today's discard-wins outcome, block+shorthand both present)

| test                                                                                   | file                                    | asserts                                                                              |
| -------------------------------------------------------------------------------------- | --------------------------------------- | ------------------------------------------------------------------------------------ |
| `itStaysDisabledWhenHierarchicalLevelKeysArePresent`                                   | `ComplexityOptionsTest.php:46`          | `enabled:false` + `callable:`/`class:` blocks → still fully disabled                 |
| `itLetsTheBareThresholdDiscardTheClassBlockAndSilenceTheClassLevel`                    | `ComplexityOptionsTest.php:65`          | `threshold:5` + `class:{...}` → class forced off, block's values gone                |
| `itStillLetsABareThresholdWithAValueDiscardTheClassBlock`                              | `CognitiveComplexityOptionsTest.php:55` | same, cognitive family                                                               |
| `itStillLetsABareThresholdWithAValueDiscardTheClassBlock`                              | `NpathComplexityOptionsTest.php:57`     | same, npath family                                                                   |
| `itLetsTheFlatThresholdWinOverAPreExistingNestedClassAndNamespaceConfigInTheSameArray` | `CboOptionsTest.php:76`                 | `threshold:30` + `class:`/`namespace:` blocks → uniform 30 wins, blocks' values gone |
| `itLetsTheFlatThresholdWinOverAPreExistingNestedClassAndNamespaceConfigInTheSameArray` | `InstabilityOptionsTest.php:67`         | same, instability                                                                    |

= **6 tests**, one per class, each explicitly named and commented as
encoding a "deliberate precedence choice" (the `CboOptionsTest`/
`InstabilityOptionsTest` bodies literally say so and cross-reference each
other and the Complexity family by name). `RuleOptionsFactoryTest.php`'s
`itAppliesNestedThresholdShorthandThroughTheFactory`,
`itAcceptsTheThresholdShorthandOnCboAndAppliesItToBothLevels`,
`itAcceptsTheThresholdShorthandOnInstabilityAndAppliesItToBothLevels` test the
shorthand alone (no sibling block) — unaffected by any candidate, since they
don't exercise the block-present case.

A related, NOT-affected pair of tests exists in `CboOptionsTest.php` /
`InstabilityOptionsTest.php`: `itThrowsWhenTheFlatThresholdIsMixedWithBareWarningInTheSameConfigArray`
(and Instability's analog) already refuse `{threshold: 30, warning: 10}` (a
same-level, same-family mode mix) with `ConfigurationRefusal` and the message
`Cannot mix "threshold" with "warning"/"error"`. This is an *existing*,
shipped refusal mechanism for a same-level key clash — relevant precedent for
candidate (a), which would extend the same idea to a cross-level clash
(top-level shorthand vs. nested block) rather than invent a new one.

## (a) Refusal — shorthand beside a nested block of the same rule is a configuration error

- **Breaks the 6 tests above** (all assert a value, not a refusal) — each
  would need rewriting to `expectException(ConfigurationRefusal::class)`.
- **Breaks the "documents today" description on 3 website pages**:
  `getting-started/configuration.md:185-210` (the whole paragraph asserts
  "replaces... not read... silently" — replacing this with "raises a
  configuration error" contradicts the resolution note two paragraphs above
  it about *layers* freely switching mode, which would need re-examination:
  does `threshold` in a config file next to `class:` from a *different*
  layer also refuse, or only same-layer? The current same-level mix refusal
  is same-layer-scoped by construction of `deepMerge`, see `enabled-
  question.md` point 2 — a cross-level refusal has to answer this question
  explicitly, which the current same-level refusal doesn't have to);
  `rules/coupling.md:154-181` and `:425-450` (the two `!!! warning` boxes
  would need to become `!!! danger "refused"` boxes with a different message
  and exit code, i.e. rewritten, not amended).
- **No preset breaks** — none of `strict.yaml`/`legacy.yaml`/`ci.yaml` write
  a top-level shorthand beside a nested block for any of the 5 rules (checked
  by `grep -n "threshold\|enabled: false" src/Analysis/Configuration/Preset/*.yaml`
  — no hits for these rule names in any preset).
- **No finding-gate corpus case breaks** — `layered-threshold`, `applied-
  threshold`, `health`, `coupling`, `suppression` are the only cases touching
  these 5 rules, and all of them write `threshold` **nested inside**
  `callable:`/`class:` (not a bare top-level shorthand beside a sibling
  block) — confirmed by reading `finding-gate/cases/*/qmx.yaml` and the
  `rules` keys in `case.json` for these 5 cases.
- Cost not measured here: how many *real* `qmx.yaml` documents outside this
  repo would start failing (unknown — no external user base per
  `CLAUDE.md`'s Backward Compatibility Policy, so this is a smaller cost than
  it would be for a tool with users).

## (b) Composition — shorthand supplies unset levels, nested block's named keys win

- **Breaks the same 6 tests**, but differently: each would need its
  assertion changed from "block discarded, shorthand's value everywhere" to
  "block's named keys survive, shorthand fills what the block didn't name."
  E.g. `itLetsTheFlatThresholdWinOverAPreExistingNestedClassAndNamespaceConfigInTheSameArray`
  would go from asserting `class->warning === 30` (shorthand value) to
  asserting `class->warning === 10` (the block's own value, since the test's
  own array sets `class: {warning: 10, error: 15}`).
- **Breaks the same 3 website locations**, in the opposite direction from
  (a): "not read — silently" and "the flat threshold takes full precedence"
  become "the flat threshold fills in only what the block leaves unset" —
  a rewrite of the paragraph and both callout boxes, not an addition.
- Introduces a NEW question the mechanism table and the docs don't currently
  have to answer: for `ComplexityOptions`/`CognitiveComplexityOptions`/
  `NpathComplexityOptions`, the shorthand today also **disables** the class
  level outright (not just "supplies a value for it"). Composition would
  have to decide whether a `class:` block beside a `threshold` shorthand
  re-enables the class level (since the user plainly wants it configured) —
  a decision the CBO/Instability shorthand never has to make (it never
  disables a level to begin with). This asymmetry between the two families
  is real code today (`branches.tsv`: ComplexityOptions's shorthand branch
  hard-codes `class: new ClassComplexityOptions(enabled: false)`; CboOptions's
  does not disable anything) and candidate (b) is the only one of the three
  where the two families' answers could legitimately diverge.
- No preset or corpus case breaks, same reasoning as (a) (no fixture exercises
  the combination at all).

## (c) As today, but loud — keep discard-wins, add a printed signal

- **Breaks none of the 6 tests's value assertions** (the resulting
  `Options` values are unchanged) — each would only gain an additional
  assertion (a captured log line, a returned diagnostic list, or similar,
  depending on implementation) rather than a rewritten one.
- **The 3 website locations stay factually correct as prose** — "not read —
  silently" would become "not read" (drop "silently") plus a note on what is
  now printed; smaller edit than (a)/(b), but not zero: the word "silently"
  appears in the very sentence being described, so at minimum that adjective
  is now wrong and both callout boxes' "ignored, not merged with it" framing
  would need a sentence added about the new signal.
- **No preset or corpus case breaks** for the same reason as (a)/(b) — but
  *unlike* (a)/(b), this candidate is the only one with a directly relevant
  **existing precedent already in the project**: the memory note "Инертное
  подавление" / `inert_suppression.md` and `bin/qmx directives` describe an
  existing family of loud-signals-for-inert-configuration mechanisms
  (`inert.tsv`, directive audit exit codes) that this candidate would extend
  by one more instance rather than invent from scratch. Whether the signal
  should be a hard exit-code change (like the removed
  `unreachable_layer_severity` refusal in `LayerViolationOptions`) or a
  softer warning-only channel is a design question this recon does not
  settle.
- Does not resolve the external reviewer's HIGH finding by itself: making the
  discard loud does not change that "a more specific key from a
  higher-priority layer loses to a more general key from a lower-priority
  layer" remains true — it only makes the loss audible. Whether an audible
  loss is an acceptable final answer, or only an interim one before (a)/(b),
  is exactly the decision this recon is feeding, not settling.

## Summary table

| candidate         | tests needing rewrite            | docs needing rewrite     | presets broken | corpus cases broken | new open question introduced                                                          |
| ----------------- | -------------------------------- | ------------------------ | -------------- | ------------------- | ------------------------------------------------------------------------------------- |
| (a) refusal       | 6 (value → exception)            | 3 pages, all 3 rewritten | 0              | 0                   | same-layer-only or cross-layer refusal scope                                          |
| (b) composition   | 6 (value → different value)      | 3 pages, all 3 rewritten | 0              | 0                   | does a nested block re-enable a level the shorthand disables (Complexity family only) |
| (c) loud-as-today | 0 rewritten, 6 gain an assertion | 3 pages, word-level edit | 0              | 0                   | does an audible loss satisfy the reviewer's HIGH finding, or only defer it            |
