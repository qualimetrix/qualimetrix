# 0065. The Manifest Records Ownership, Not a Migration Schedule

**Date:** 2026-09-20
**Status:** Accepted

## Context

The capability migration accepted in [ADR 0022](0022-capability-oriented-modular-monolith.md)
was carried out in packages labelled `P1` to `P8`, some of them suffixed `-A` to
`-E`. The internal manifest named those packages in two places, under one shared
schema pattern `^P[1-8](?:-[A-E])?$`:

| Home              | Shape                                                     | Question it answered                    | Published in                                                                                                     |
| ----------------- | --------------------------------------------------------- | --------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| `closure_package` | one `required` slot per declaration, 959 of 959 populated | which package carried this declaration  | seven generated artifacts, as its own column — six as `closure_package`, one as the aggregate `closure_packages` |
| `closes_in`       | one `required` key per consumer, 2508 of 2508 present     | in which package this permission closes | one artifact, inside the `consumers` JSON cell                                                                   |

`closes_in` was declared on `enforcement_seams` entries too, with the same
pattern and no `null` allowed.

Together they are one dictionary with two homes because they label two different
things: a declaration's origin, and a permission's expiry. That is also why a
check aimed at one of them says nothing about the other, and why the two were
retired in separate steps.

**The data had stopped saying anything long before the fields went.** Measured
over every commit in this history that touched the manifest, 88 of them:

- A consumer carried a non-null `closes_in` in exactly one commit, `ceb50ac7`
  (manifest version 1): `Analysis.Run`'s `MetricEnricher` on Duplication's
  inspection contract, closing in `P3`. Every manifest since has been null on
  every consumer.
- `enforcement_seams` held 12 entries at `ceb50ac7` and 11 at `57fa22fa`, with
  real labels (`P3`, `P4`, `P5`, `P6`). It emptied at `2c83c285`, still at
  version 1, and has been `{}` for the whole life of versions 2, 3 and 4.

So the seam variant's `closes_in`, with its `required` and its pattern, described
real data only under version 1. For the whole of versions 2, 3 and 4 it was
declared strictness over an empty section — a rule no tracked manifest could
break, kept through three format revisions because nothing asks what a `$defs`
entry is still for.

## Decision

Retire the dictionary from both homes. Each removal drops a `required` property,
so each is a format change and each carries its own version.

| Version | Landed in           | Shape                                              |
| ------- | ------------------- | -------------------------------------------------- |
| 1       | `ceb50ac7`          | both labels present, and both carrying values      |
| 2       | `9d6f7b8e`          | both present, `closes_in` null everywhere          |
| 3       | `4a701bb0` (PR #93) | `closure_package` gone, `closes_in` still required |
| 4       | `6bad11a0`          | neither                                            |

**Two bumps for one retirement is the honest record, not noise.** A manifest of
the version 3 shape exists in history, and it is a shape neither 2 nor 4
describes; one number covering two shapes would make `version` a label for "the
current file" rather than an identifier of a format. The bump is cheap by
construction: `version` is pinned by the schema's `const` and by one assertion in
`governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php`,
and `.gitattributes` marks `/docs/` `export-ignore`, so neither the manifest nor
its schema ships in the composer package and nothing outside this repository
reads the number.

### The branch collapse, and the field it cost

The consumer schema is a `oneOf` over variants. `closes_in` was the **only**
field distinguishing two of them:

- one accepting a string `source_fqcn` with `closes_in` pinned to `null` — the
  permanent exact consumer;
- one accepting a string `source_fqcn` with `closes_in` matching the P-pattern —
  the temporary exact consumer.

Remove the field from both and the two become byte-identical — verified by
deleting `closes_in` from each pre-stage variant and diffing them sorted:
identical, and equal to the single variant that now survives. Keeping both would
then be refused by `oneOf`'s exactly-one rule, and not marginally: a schema that
strips the field from all six variants without merging the pair rejects the
current manifest with "Failed to match exactly one schema" **2428 times**, once
for each plain-import consumer that names a source.

The removal is therefore a **branch collapse, six consumer variants to five**,
not a subtraction from six.

Nothing else in the tree records that those two branches ever differed, or by
what. That is the reason this section exists.

### The vocabulary is not the field

Permanent versus temporary was the *idea* the field expressed, and it outlived
the field's spelling in places no sweep for `closes_in` reaches. With the
distinction gone, the names that drew it go too:

| Carrier                                   | Before                                                                            | After                                                     |
| ----------------------------------------- | --------------------------------------------------------------------------------- | --------------------------------------------------------- |
| published summary row                     | `permanent_composition_bindings`                                                  | removed — see below                                       |
| published summary row                     | `temporary_contract_consumer_entries`                                             | removed                                                   |
| consumer refusal                          | "must be permanent owner-wide, permanent exact-source, or temporary exact-source" | "must be owner-wide or exact-source"                      |
| `composition_binding` refusal             | "must permanently authorize an exact DI source to an internal target"             | "must authorize an exact DI source to an internal target" |
| `contract_surface` refusal                | "must name a permanent exact source"                                              | "must name an exact source"                               |
| governance test method                    | `itPublishesOnlyPermanentExactCompositionBindingsForDiInternals`                  | `itPublishesOnlyExactCompositionBindingsForDiInternals`   |
| `AGENTS.md`, `docs/ARCHITECTURE.md`       | "permanent exact composition bindings"                                            | "exact composition bindings"                              |
| `qmx.yaml` header comment                 | "temporary source-to-target grants"                                               | "exact source-to-target grants"                           |
| `docs/internal/MODULE_README_TEMPLATE.md` | four carriers, one of them a `P3` gate naming nothing in the tree                 | rewritten to the current rule                             |

Dropping the qualifier from `permanent_composition_bindings` exposed something the
qualifier had been hiding: the row counted the same array as
`exact_composition_bindings`, by construction rather than by coincidence — one
`count()` of one array under two names, with nothing asserting they agreed. The
rename was therefore not the end of it, and the row is gone rather than renamed.
A word that stops distinguishing things is worth removing on its own; a word that
was the only reason two identical numbers looked like different measurements is
worth removing twice.

Records keep theirs. [ADR 0022](0022-capability-oriented-modular-monolith.md) and
[ADR 0023](0023-p8-context-locality-and-composition-bindings.md) say "permanent"
throughout, and correctly: they describe the model as it stood when they were
accepted, and a record rewritten to match a later tree stops being a record. The
distinction between a document that asserts what is true now and one that asserts
what was true then is the whole reason the list above stops where it does.

Two uses of the word were **kept** in live code, and both would be wrong to rename:

- The production generator's `$temporary` in its atomic-write idiom (write to
  `.tmp.<pid>`, then `rename`). It is the ordinary meaning of the word and has
  nothing to do with consumer permissions.
- `assertArrayNotHasKey('temporary_internal_grants', $manifest)` in the
  governance test. That key is a **different retired thing, not this one**: it
  was a root section of the manifest at version 1, holding composition-root
  grants with a rationale string. The section and its schema property left at
  version 2; a dead `$defs` remnant naming it survived in the schema until
  version 3 removed it. The assertion guards against that section returning,
  which is a separate hypothetical from anything `closes_in` expressed.

A consumer is now owner-wide (`source_fqcn` is `null`) or exact (a string), and
neither is dated. Of the 2508 consumers the manifest carries, **all** are exact:
the owner-wide variant is declared shape with no instances, like the seam
variant before it. This record does not decide whether it stays.

## Consequences

**The format admits strictly less, and refuses uniformly.** Measured by planting
`closes_in: "P3"` on one consumer of each relation and validating against each
era's schema with the same validator the generator uses:

| Plant                 | Against version 3                                     | Against version 4                                                                                        |
| --------------------- | ----------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| plain import          | **valid**                                             | refused: "The property closes_in is not defined and the definition does not allow additional properties" |
| `composition_binding` | refused: "String value found, but a null is required" | refused: the same `additionalProperties` message                                                         |
| `contract_surface`    | refused: "String value found, but a null is required" | refused: the same `additionalProperties` message                                                         |

The plain-import row is the real change. Under version 3 that plant did not just
validate — the generator accepted it and **published a different number**: the
summary row `temporary_contract_consumer_entries` went from `0` to `1`, exit 0.
Under version 4 the same plant is refused at schema load, exit 1. What used to be
an expressible-but-unused statement is now not expressible.

**A published artifact changed without any site naming the key.** The consumer
entries reach `production-ownership.tsv` by whole-object copy, so the cell lost
`closes_in` with no edit at the copying site. A check that greps producers for a
field name cannot see this; a comparison of the published bytes can.

**No `CHANGELOG.md` entry, deliberately.** The manifest and its schema are
repository-governance files that do not ship (`export-ignore`), no CLI surface,
config key, report field or exit code moves, and the only consumers of either
field are in this repository. The precedent agrees: the version 2 → 3 bump in
PR #93 added no entry either. This sentence is the record of that decision, so
that the absence of an entry cannot be mistaken for an oversight.

**What replaces the dictionary is nothing.** Declaration provenance is answered
by history, and permission scope by the owner/source pair the manifest already
carries. [ADR 0023](0023-p8-context-locality-and-composition-bindings.md) is
still the definition of an exact composition binding, and it spells that
definition "permanent exact `composition_binding`" — read the qualifier there as
the historical spelling of what this record now calls simply exact. A future field expressing "this
permission expires" is not authorized by this record: it would be a new decision,
and it would need data that exercises it.
