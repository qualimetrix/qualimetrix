# 0058. A Layer's Value Survives the Layers Above It

**Date:** 2026-09-12
**Status:** Accepted

## Context

[ADR 0056](0056-source-composition-is-measured-and-left-alone.md) measured source
composition, found exactly one mechanism with the axis built for it, and
deliberately shipped it unfixed: a round that measures a mechanism should not
also change it, because the grid that would judge the cure is the grid the cure
moves. It fixed the direction, left four floor rows red until the cure landed,
and handed the round that unfolds two of [ADR 0055](0055-a-rule-option-declares-the-shape-of-its-value.md)'s
three open questions — the shorthand, and the fate of the group registry.

This ADR records what that round cured, what it found beside it, and what it
had to repair in its own instrument before the instrument could judge any of it.

## The defect, and the two beside it

One sentence covers all three: **a value a layer wrote must still be there after
a higher layer rewrote something else.**

| what broke                                                         | measured effect                                                                                   |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------- |
| eviction removed a shorthand the higher layer only half-rewrote    | the other half fell to a compiled default no layer wrote                                          |
| an overlay's `threshold: ~` evicted a band while selecting no mode | the lower layer's whole band was silenced                                                         |
| both merge sites wrote an overlay's `null` over a value            | `warning: ~` erased an explicit `warning: 2`; `rules: {x: ~}` erased a rule's whole configuration |

The first was ADR 0056's. The second it recorded as understated — measurement
here shows BOTH halves are lost, not one. The third was not on any axis: it was
raised by the review of this round's plan, before any code existed, and
reproduced afterwards.

## Decisions

**The shorthand is unfolded in EVERY layer before they merge.** Unfolding only
the lower layer turns the reverse case into a refusal, because both spellings
then survive into one array and the parser refuses the mix. Unfolding is
therefore a rewrite of one layer that each merge site applies to base and
overlay alike, which is why `RuleOptionThresholdModeResolver` became
`RuleOptionThresholdShorthand` — its old signature took two layers and returned
one, and that asymmetry is what made the correct cure unexpressible.

**Eviction is deleted, not repaired.** Once both layers are unfolded, nothing is
left to evict — established by enumeration over all 32 rule×path pairs rather
than asserted. Keeping a function that provably cannot act, and testing it
against cases it can no longer reach, would be worse than deleting it.

**The suffix heuristic is deleted; the registry stays and becomes a checked
claim.** This is ADR 0055's second question. A wrong guess that REMOVES a key
loses configuration; the same guess asked to WRITE a pair fabricates it. The
price is named rather than called conservative: a rule with no registry entry
now refuses a cross-layer mode change instead of being guessed at. That is only
acceptable because completeness stopped being a claim — a guard derives call
sites by AST and the rule-name→options-class join from the rule class list, so
neither side of the comparison is the registry itself.

**`~` above a written value means silence, not a reset.** The tree had already
settled this one level down: a `~` must not shadow a populated alias behind it.
Across layers it is the same question, and answering it differently would give
one symbol two meanings — the defect class this round exists to remove. An
explicit `false` still switches a rule off; only `null` changed.

**The `threshold` group declaration keeps a form, per use site.** Unfolding
rewrites one key into two, so it must not change which key a refusal names: a
`threshold` whose value is not the scalar form the graduated pair declares is
left alone and refused under the name the author wrote. Writing the group's form
into the registry surfaced that `BARE_PAIR` and `MAX_PREFIXED_PAIR` were shared
by rules that declare DIFFERENT forms (`integer()` and `number()`), so the form
belongs to the use site and not to the shared constant.

## What the round had to repair in its own instrument

Three of these were found because the cure landed, not before.

**The floor could not express a cure that lands after its own snapshot.** Both
halves read the same `cure` column, so the first such cure turns its own rows
red on a half that is behaving correctly. Git ancestry cannot decide it — the
commits already in the column are not ancestors of `main`, because the
repository squash-merges — so the column gained a `pending:` sentinel that says
which half is which, and `--freeze-before` refuses while one stands.

**The floor counted a row it had stopped observing as CURED.** A row absent from
the grid produced `held = false`, which the cure branch read as proof of repair.
It is now a miss under every disposition. `--axis=` narrowing a `--freeze-before`
run, which could retire an axis into exactly that state, is refused too.

**The floor compared the NAME of a verdict where it should have compared the
BIT.** This one caught the round out: after the cure the four rows changed from
`LOST_SIBLING` to `FRANKENSTEIN` and stayed defects, and the floor reported them
as repaired because the name no longer matched. A row that swapped one defect
for another read as cured. The floor's own docblock had already written the rule
down for the opposite direction — "wrongness is the bit, not the word" — and the
cure side had not been held to it.

**The triple probe observed two of its three layers.** `low` and `high` are the
sides written alone; a triple has a middle layer, and it was never written
alone. Before the cure that was invisible, because the lost slot was detected
without it. After the cure the surviving value IS the middle layer's, so
confirming the cure needed the observation that did not exist. The probe now
takes it, and the classifier's `survives` check compares each leaf against the
highest of THREE layers that wrote it.

## What this ADR does not claim

- **The stand still cannot judge a promise it carries in one place.**
  `promised_survival = survives` sat in the ledger with no branch in the
  classifier able to award it; before the cure the rows never reached that code
  and the gap was invisible. Other promise values may be in the same state, and
  nothing in the stand enumerates promise values against the branches that
  award them.
- **The sample is still the narrow one ADR 0056 described.** Four triples, one
  scalar path, `#[CliAlias]` never in the L3 position. This round cured what
  that sample found; it did not widen it.
- **Four things are deferred with their price named as a number**: axis F
  (its population must be rebuilt — of eight canonical forms in a map key, five
  are refused by the YAML parser and two collapse into one), axis B (four
  mechanisms, 81 of 82 cells one copy-pasted early return, nothing blocking
  them), the ten stand rows with their retake, and axis A's bool blindness.
- **A premise this round refutes:** axis B was recorded as blocked on the rule
  layer having no notion of "channel boundary", citing a plan about finding
  identity that never mentions the axis. The real reason recorded in X17 was
  that no owner held a mandate over pair semantics.

## Consequences

- Configuration behaviour changes in a way a consumer can see: the severity of
  existing findings moves, so a `--fail-on=error` run can change colour with no
  configuration change. `CHANGELOG.md` carries it as `Breaking` with the
  re-check step written from the consumer's side.
- Two documented refusals are new: a layer mixing `threshold` with a graduated
  key is no longer masked by the eviction that removed the other mode, and a
  rule with no registry entry refuses a cross-layer mode change.
- **A measurement instrument that has never seen a cure succeed has not been
  tested against success.** Three of this round's four instrument defects were
  invisible until something was repaired: the floor's absent-row branch, its
  name-versus-bit comparison, and the triple's missing middle observation all
  only fire on the path a working cure takes. A stand exercised only against
  broken products is exercised on half its range.
