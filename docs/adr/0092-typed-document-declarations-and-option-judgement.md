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

An enablement decision is constructed from its declared cell address, authored
switch and admission, complete decisive writers and option activity. The existing
readonly observations remain available. Statement and provenance are projected
from the first decisive writer, so callers cannot supply independent display facts.
Private selection indexes and option builders keep the decide/build/conclude
publication boundary and preserve writer ordering and refusals.

CLI writes retain both the machine option locator and the complete authored
flag/value expression. A normalized address cannot reproduce what the caller
wrote, so `CommandLinePathWrite` requires the original expression and provenance
carries it through value refusal, selection and activity presentation. Rule-option
keys and levels accept only their declared snake, camel and kebab spellings at
every door; folding wrong case before judgement hid an authored error.
Symfony YAML remains the scalar parser. Parsed floats such as `+2` and `2.0`
remain refused for integers, with the value and a hint to write `2`; no source
rewriting or float-to-integer conversion is used.

Finding owns a threshold override request with its numeric values, syntax and
actually authored axes. Inline parses that request and judges every declared
level and axis before handing it to the rule-specific validator. Worker
transport keeps the existing ThresholdOverride value. Absence is a consumer
choice to skip validation; a malformed authored annotation still diagnoses.

## Consumer migration

Supply the complete authored flag/value expression after the option name when
constructing `CommandLinePathWrite`. Replace previously folded wrong-case CLI
keys and levels with the spelling named by the refusal.

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

Replace independent `EnablementDecision` constructor arguments with
`SelectionCellAddress`, `AuthoredCellDecision` and `OptionActivity`. Choose
`CellSwitch::On` or `::Off` and `CellAdmission::Direct` or `::Filtered`.
Provide all decisive writers; the first writer supplies statement and provenance.
Readers retain the existing readonly observation names.

Replace validate(warning, error, errorWasExplicit) with
validate(ThresholdOverrideRequest). Skip constructing a request when no override
was authored. A shorthand supplies equal non-null warning/error values,
OverrideSyntax::Shorthand and no written axes. Explicit tokens supply
OverrideSyntax::ExplicitAxes with each distinct OverrideAxis whose value is
non-null. Warning alone remains lawful; WarningOnlyValidator refuses authored
Error. Unequal algorithm pairs use both explicit axes. An all-null request,
empty or mismatched explicit axes and unequal shorthand now refuse at
construction instead of representing an absent or inconsistent override.

## Consequences

The public declaration and override programming APIs change while configuration grammar,
numeric defaults and metric formulas remain the same. CLI spelling admission
and authored refusal statements follow the explicit rules above. Exact manifest consumers must reflect the new contracts; internal
matching, construction and delivery helpers remain private to their owners.

Readonly typed facts make dependencies and invalid combinations explicit.
Forwarding facades, associative state bags and further traits were rejected
because they preserve the same independent responsibilities behind another name.
