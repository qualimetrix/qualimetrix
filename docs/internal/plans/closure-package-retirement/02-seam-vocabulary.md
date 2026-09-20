# Stage 02 — retiring `closes_in` and the vocabulary that named it

Removes `closes_in`: the P-dictionary in its second home, declared on every consumer entry and
meaning "the migration package in which this seam closes". Stage 01 removed the first home and took
the manifest to version 3.

**This document records decisions and measurements. It prescribes no instruments.** Three revisions
of it prescribed how to check things, and a reviewer measured each prescription wrong — most
recently a sweep that could not match `permanent_composition_bindings`, because `\b` does not fire
before an underscore. Every acceptance item below names a property and leaves the instrument to the
executor, who states it and proves it on a plant before trusting it. What an executor cannot
re-derive is here; what they can, is not.

## Measured on `423df74a`

| Fact                                             | Value                                                              |
| ------------------------------------------------ | ------------------------------------------------------------------ |
| manifest version                                 | 3                                                                  |
| declarations / consumers                         | 959 / 2508                                                         |
| by `relation`                                    | 2428 plain imports, 74 `composition_binding`, 6 `contract_surface` |
| consumers with a non-null `closes_in`            | 0                                                                  |
| `enforcement_seams`                              | empty                                                              |
| `closes_in` literals in the production generator | 6, all of them edits                                               |
| schema lines naming it                           | 14, across 6 consumer `oneOf` variants and the `seam` definition   |

Re-take these before executing. The previous revision's figures were stale in five places, and this
table will be too.

## Three things the previous revision assumed, refuted by experiment

**1. "One plant per population, three in all" cannot be executed.** The `composition_binding` and
`contract_surface` `oneOf` variants pin `closes_in` to type `null`, so a non-null plant is refused at
schema load. Only the plain-import population can carry a value at all. Those two branches are shown
inert by the schema, not by planting.

**2. The three relations are not reached by different code paths.** They are distinct `if` branches
of one function; the three relation-specific functions downstream never touch the key.

**3. Removing the key is not a subtraction.** `closes_in` is the only field distinguishing the
permanent-exact consumer variant from the temporary-exact one. Strip it from `required` and
`properties` and those two variants become byte-identical, so `oneOf`'s exactly-one rule refuses
every plain-import consumer whose `source_fqcn` is a string. **The schema edit is a branch collapse,
six consumer variants to five**, and nothing else in the tree records that those two ever differed by
one field.

## The vocabulary outlives the key

`closes_in` is the field. **Permanent versus temporary is the idea.** It survives in refusal
messages, in a published summary-row name, in a test method's name, and in prose — and a sweep for
the field's name finds none of them. Carriers are enumerated by P1 with an instrument P1 shows can
reach at least `permanent_composition_bindings` and a capitalized occurrence inside an identifier;
the two spellings that defeated the previous revision's attempt.

A stage that removes the field and leaves the vocabulary describes a distinction the tree no longer
makes. Each carrier gets a decision — rename, simplify, or keep with a reason — and the decisions are
P1's alone, so that no two packages have to agree at runtime.

## The version

To **4**. `version` is enforced by the schema's `const` and by one assertion in
`governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php`; nothing outside the
repository consumes it. Two bumps for one retirement is the honest history; one number naming two
shapes is what the previous revision warned against.

## Packages

**P0 — the before-measurements, taken by the orchestrator before anyone is dispatched.** Several
acceptance items compare a state after the removal against one before it, and no package that runs
after P1 can take the earlier half. P0 takes them, stamps the tree they were taken on, and forwards
them into P1's brief.

**P1 — the removal and the vocabulary.**

    docs/internal/modular-architecture-manifest.schema.json
    docs/internal/modular-architecture-manifest.json
    scripts/generate-modular-architecture-production-inventory.php
    governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php

The schema: the branch collapse, the `seam` variant, the version `const`. The manifest: the key on
every consumer, and the version. The generator: every `closes_in` literal, the summary counter, and
the vocabulary carriers it owns. The governance test: the version assertion, a direct
`assertNull($consumer['closes_in'])` that a sweep of the generator's reads would never find, and its
own method name if that name is a carrier.

The value also reaches the published artifact without being named, by a whole-consumer copy. That
site needs no edit and stops carrying the key when the key is gone — a thing to verify, not change.

**P2 — the records.** Runs after P1's vocabulary decisions are reported, and conforms to them.

    docs/adr/<next>-….md            (new)
    docs/adr/README.md
    docs/internal/MODULE_README_TEMPLATE.md
    CHANGELOG.md                    (only if P2's decision is to add an entry)

One ADR for the whole retirement, both stages, carrying the v2 -> v3 -> v4 history and the rationale
that lives only in this plan directory — the directory is deleted when this closes, so an
undocumented format bump would survive nowhere. It records the branch collapse and names the field
that used to distinguish the two merged variants, because after the collapse nothing else does.

**P3 — regeneration and acceptance.** The orchestrator's, including the deletion of this directory
with its `enumeration.tsv` and its index row.

## Definition of Done

Properties, not procedures. For each, the executor states the instrument, shows it refusing a plant,
and reports the numbers it derived.

1. **The key leaves the published cell and nothing else does.** `closes_in` lives inside the JSON
   cell of a surviving column, so a column diff cannot see it go and stage 01's `oracle.py` is
   unusable. Establish, per row: the rows that changed are exactly the declarations that have
   consumers; each changed cell lost that member and no other; no consumer was added, removed or
   reordered. The comparator is proved on plants of each of those three failures before its verdict
   is believed.
2. **Only the artifacts P1 predicted move.** P1 states which and why; everything else under
   `docs/internal/generated/`, and `qmx.yaml`, is byte-identical across the regeneration. The set of
   files compared comes from the filesystem, not from a list of the ones expected.
3. **The summary row leaves, and the only thing that notices is the freshness gate.** Removing it
   moves a published artifact, so that gate fires by design and proves nothing on its own. Separate
   the two and report both runs.
4. **The two unplantable populations, before and after.** Before P1, both refusals quoted. After P1,
   what the same plants do — their `oneOf` variants survive the stage and only the `null` pin leaves,
   so whatever refuses them then is a different mechanism and must be named.
5. **The plain-import plant, prediction written first**, before P1 and after.
6. **Every vocabulary carrier is enumerated and decided.** The instrument is shown to reach the two
   spellings named above before its list is accepted.
7. **The seam branch is gone from the schema**, not merely unexercised — which it was before.
8. **P2's `CHANGELOG.md` decision is recorded either way**, as an entry or as a sentence in the ADR.
9. `! git grep -q closes_in` outside the ADR.
10. `composer check` green from a clean clone with copied `vendor`, `website/.venv` and
    `html-report/node_modules`.
11. This directory is deleted and its row leaves `docs/internal/plans/README.md`.

## Hazards, measured

**"Every intermediate state is schema-invalid" is false, and the state it misses is the dangerous
one.** Stripping the key from the data alone, or the schema alone, is refused loudly at load. But a
tree where the schema is collapsed and the data stripped while the generator still reads the key
**validates**: a missing key reads as the `null` it always was, classification is unchanged, and the
summary artifact comes out byte-identical. The only outward sign is a warning per read. A package
that stops there looks finished.

**`enforcement_seams` is `{}`, and `json_decode(..., true)` renders that as `[]`.** An emptiness
check that distinguishes the two produced a false reading in two consecutive review rounds.

**A plant that adds only a `use` line proves nothing about an import ban.** Dependencies are
collected from references, not from import statements; this cost a false green during #102.

Before #102 landed, a schema-invalid intermediate state hung the Tooling suite instead of failing
it. Work on a tree that carries that fix.

With Xdebug active for CLI, each undefined-key warning dumps a stack that serializes the whole
manifest, and the check dies of OOM instead of naming a stale artifact. `XDEBUG_MODE=off` isolates
it; CI does not run Xdebug.
