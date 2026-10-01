# 0092. Typed document declarations and option judgement

**Date:** 2026-10-01

**Status:** Accepted

## Context

A document node described scalar constraints, map membership, collection entries,
diagnostic wording and per-layer judgement in one record with independent copy
arguments. Its forwarding methods obscured which facts each reader needed.
Rule option forms also combined their declaration, compound matching and
document-schema projection.

Selection, option construction and delivery need the same authored facts.
Separating an algorithm must preserve every writer, refusal and channel decision;
a second option dictionary or a generic value bag would create another authority.

## Decision

A document node composes typed scalar, map and collection facts, its merge policy,
wording and layer judgement. Readers consume those exact readonly observations.
The node retains the declaration DSL; scalar constraints, named entry reading and
shorthand expansion remain Configuration subjects. A word vocabulary declares
sensitive or folded comparison through its own value rather than a boolean switch.

Finding owns rule option declarations, compound forms and their projection into
the Configuration document schema. A private declaration carries their linked
facts; conversion does not maintain a second accepted-key dictionary. Public
option word sets use the same explicit comparison choice. A vocabulary may still
contain a blank word; refusing blank text requires the independent non-empty
text constraint.

## Consumer migration

Replace `NodeSchema::stringList()` with a list of ScalarForm::String nodes.
Replace `oneOf(words, foldCase)` with `words(SchemaWordSet)`; choose
`SchemaWordSet::of()` or `::foldingCase()` explicitly.
Replace scalar getters with the scalar facts, map/key/name getters with the map
facts, list element getters with collection facts, and wording/judge getters
with their readonly observations. An absent required entry still refuses.

Replace `RuleOptionShape::oneOf()` and `::oneOfIgnoringCase()` with
`::words(RuleOptionWordSet::of())` and
`::words(RuleOptionWordSet::foldingCase())`. Read its declared vocabulary through
the readonly word set. The DSL remains the authority for schema conversion.

## Consequences

The public declaration APIs change while configuration grammar,
numeric defaults, metric formulas, provenance and published diagnostics remain
the same. Exact manifest consumers must reflect the new contracts; internal
matching, construction and delivery helpers remain private to their owners.

Readonly typed facts make dependencies and invalid combinations explicit.
Forwarding facades, associative state bags and further traits were rejected
because they preserve the same independent responsibilities behind another name.
