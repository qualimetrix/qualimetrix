# 0055. A Rule Option Declares the Shape of Its Value

**Date:** 2026-09-11
**Status:** Accepted

## Context

[ADR 0049](0049-rule-option-key-recognition.md) closed the question of which
option keys exist: `RuleOptionsInterface::acceptedOptionKeys()` returns a
`RuleOptionKeySet`, the class says and the reader asks, and an unrecognised key
stops the run. It left the next question open. A recognised key still carried no
statement about what its **value** may be, so the form was decided wherever the
value happened to land — an `(int)` cast here, an `is_string()` guard there, a
`?:` further down — and a value of no usable form was quietly coerced into one.

The one refusal that existed was worse than nothing. `RuleOptionsFactory` held a
`validateNumericFields()` that refused a value only when two things coincided:
the value was a **string**, and the key's **name** contained one of thirteen
substrings (`threshold`, `warning`, `error`, `min`, `max`, …). A key named
outside that list was never checked, and a wrongly shaped value of any other
type — a list, a map, a boolean, a fraction — was never checked at all. The
measurement of round X17 put observations on the gap: `enabled: [7331]` and
`enabled: "false"` both left the rule **on**, because a non-empty list and a
non-empty string are truthy; `warning: "15"` and `warning: 10.5` were cast to a
whole number; `suppress_paths: 7331` matched neither the string branch nor the
array branch of its extractor and suppressed nothing, in silence; and a map
written where a list belongs was not ignored but *applied* — `array_values()`
threw the user's keys away and used the values.

Three keys sat outside the scheme entirely. `suppress-paths`,
`suppress-namespaces` and `suppress-namespace-channels` are recognised on every
rule as framework keys; no options class declares them, because no options class
reads them — they are applied by `FindingExclusionLedger::keeps()`, which decides
by producer name and finding location. Their refusals reached the user without
the `Configuration error:` frame.

## Decision

**1. The shape lives in `RuleOptionKeySet`, beside the key, not in a registry of
its own.** One accepted entry is a name plus a `RuleOptionShape`. A second
declaration standing next to the key set could go out of step with the key set
that admits it; a central registry of shapes would be a third authority on a
subject that already has two.

Rejected, and why:

- **A second method on the interface** (`acceptedOptionShapes()` beside
  `acceptedOptionKeys()`). Two methods can disagree about which keys exist. The
  shape is a property of the accepted key, so it belongs inside the entry.
- **A registry of shapes** keyed by rule and option. Same defect one level up,
  plus a new file to keep in step with every options class.
- **Deriving the shape by reflection over constructor parameters.** The tree had
  already rejected this for the key set itself and said so twice —
  `RuleOptionKeySet`'s own docblock ("a rule's own `fromArray()` body is the
  authority on which keys it reads, and reflection over constructor parameters
  cannot see into a method body") and `RuleOptionsInterface::acceptedOptionKeys()`
  ("the single statement of the key set — constructor parameters are no longer
  read as a second one"). The argument transfers unchanged: a constructor
  parameter typed `int` says nothing about whether `fromArray()` accepts the
  string `"15"` on the way to it, and several bodies accept a scalar *or* a list
  for one parameter.
- **A shape meaning "anything".** `RuleOptionShape` deliberately has no `mixed`.
  A form that cannot be named is not declared, and an undeclared key is refused
  as unknown — so the vocabulary had to name every form the tree actually holds:
  nullability, a free map, a nested block, the empty string, and the form of a
  list's elements.

**2. The declaration is consumed by the parse, not by the reading site.**
`RuleOptionsFactory` checks a value against its declared shape before the value
reaches `fromArray()`, and refuses through the `ConfigurationRefusal` carrier of
[ADR 0050](0050-configuration-refusal-carrier.md) on the single ladder of
[ADR 0051](0051-refusal-is-not-routed-by-command.md). `validateNumericFields()`
is deleted. A value is described in the refusal by **what it is**, never by what
was expected, so the two halves of the sentence cannot agree by accident.

**3. The shape is derived from what the reading code does, not from what the key
is called.** The cast's target (`(int)` → `integer`, `(float)` → `number`,
`(bool)` → `boolean`) and the guard's predicate (`is_string()` → `text`,
`array_is_list()` → `listOf`) are the authority. This is the inverse of the rule
the deleted validator used, and it is the point of the change.

**4. The half of ADR 0049 where the class speaks for itself is preserved.** A key
in the *answered by the class* state carries no shape: the class, not the
reader, decides what may stand there, and a shape check in front of it would
take that decision away.

**5. Ownership of the form follows ownership of the key.**

| Where the key lives                        | Who declares its form                                  |
| ------------------------------------------ | ------------------------------------------------------ |
| a rule's own options                       | that options class, in its `RuleOptionKeySet`          |
| a hierarchical rule's level slot           | the level's options class, via `levelOptionsClasses()` |
| a configuration root outside `rules:`      | `ConfigSchema`                                         |
| a user-named subtree (`computed_metrics:`) | the subtree's owner (`ComputedMetricEntryKeys`)        |
| the architecture document                  | `ArchitectureConfigurationFactory`                     |

`levelOptionsClasses()` remains the **single** source of a level slot's existence
and name; the key set does not restate either, and the level map is derived from
presence. Restating the slot in two places was rejected for the same reason as
the second method: two statements of one fact can disagree.

**6. The promise this makes is about form, not about layer.** A refusal raised
after the configuration layers are merged can say "this is not the declared
shape" but not "a preset wrote it": `ConfigurationSource` (ADR 0050) has no case
for defaults or for Composer discovery, and the merged document is `Resolved`.
Rather than widen the source vocabulary for a claim this round does not need, the
promise is written narrowly — **the refusal answers for the shape, not for the
layer that supplied it**. Widening it belongs with source composition, which this
round defers (see below).

## What this ADR deliberately does not decide

Three questions were left open, together and on purpose:

- unfolding the `threshold` shorthand **before** the layers merge, rather than
  after;
- the fate of `RuleThresholdKeyGroupRegistry` and its silent suffix heuristic —
  built out, or deleted;
- the second reader of `RuleOptionsRegistry`.

Each is a decision about *which source wrote a value and which one wins*, not
about what form the value may take. Deciding any one of them alone would fix an
answer the other two could contradict. They were subsequently resolved together
by [ADR 0058](0058-a-layers-value-survives-the-layers-above-it.md).

## Consequences

- **Configurations that used to run now stop.** A quoted number where a whole
  number is declared, a fraction where a whole number is declared, a map where a
  list is declared, and a scalar where a list is declared each end the run with
  exit code 3 and a message naming the rule, the key, the level, the expected
  form and the written one. `CHANGELOG.md` carries the migration per shape.
  Command-line values are unaffected: they are text by construction and are
  converted before the shape is judged.
- **The form of one key is still decided in two places.** The inline door —
  `withOverride()`, `ThresholdOverride` and the inline appliers — was frozen for
  this round and keeps its own coercions. Until the source-composition round
  merges them, the declaration and the inline path can disagree about the same
  key. This is named here rather than discovered later.
- **The shape vocabulary is a surface that grows with the tree.** A new form —
  one no existing option takes — must be added to `RuleOptionShape` with the
  words its refusal will print, not worked around with a broader existing shape.
- **Refusals are now framed everywhere in this subtree**, the three framework
  `suppress-*` keys included, so a caller may detect a configuration refusal by
  the `Configuration error:` frame rather than by matching prose. Those three
  have no declaring options class, so their form is stated where they are
  recognised rather than beside a key set — named here because it is the one
  place the ownership table above does not reach.
