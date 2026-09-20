# Stage 02 — retiring `closes_in` and the vocabulary that named it

Removes `closes_in`: the P-dictionary in its second home, declared on every consumer entry and
meaning "the migration package in which this seam closes". Stage 01 removed the first home and took
the manifest to version 3.

## Measured on `423df74a`, not carried forward

| Fact                                                        | Value                                                              |
| ----------------------------------------------------------- | ------------------------------------------------------------------ |
| manifest version                                            | 3                                                                  |
| declarations / consumers                                    | 959 / 2508                                                         |
| by `relation`                                               | 2428 plain imports, 74 `composition_binding`, 6 `contract_surface` |
| consumers with a non-null `closes_in`                       | **0**                                                              |
| `enforcement_seams`                                         | empty                                                              |
| rows of `production-ownership.tsv` carrying the key         | one per declaration that has consumers                             |
| `closes_in` literals in the production generator            | 6, all of them edits                                               |
| schema lines naming it                                      | 14, across 6 consumer `oneOf` variants and the `seam` definition   |
| files under `docs/internal/generated/modular-architecture/` | 20                                                                 |

Every number here is a carried-forward number the moment it is read on a later tree. Re-take them
before executing; the previous revision's figures were stale in five places.

**Counts are not pinned in the Definition of Done below, deliberately.** Each acceptance item
derives its expected value from the manifest in the same run that checks it, so the item survives a
tree where the population has moved. An item that hardcodes today's count is an item that fails on a
correct future change and passes on a wrong one that happens to preserve the total.

## Three things the previous revision assumed, refuted by experiment

**1. "One plant per population, three in all" cannot be executed.** The `composition_binding` and
`contract_surface` `oneOf` branches pin `closes_in` to type `null`, so a non-null plant is refused at
schema load — `String value found, but a null is required`, exit 1. Only the plain-import population
can carry a value at all. The stage therefore cannot demonstrate those two branches are inert by
planting; it argues from the schema that they are unreachable by construction and quotes the refusal
as the evidence.

**2. The three relations are not reached by different code paths.** They are distinct `if` branches
of one function; the three relation-specific functions downstream never touch the key.

**3. Removing the key is not a subtraction.** `closes_in` is the **only** field distinguishing the
permanent-exact consumer variant from the temporary-exact one. Strip it from `required` and
`properties` and those two variants become byte-identical, so `oneOf`'s exactly-one rule refuses
every plain-import consumer whose `source_fqcn` is a string. **The schema edit is a branch collapse,
six consumer variants to five**, and nothing else in the tree records that those two variants ever
differed by one field.

## The vocabulary outlives the key, and that is why this stage is not a delete

`closes_in` is the field. **Permanent versus temporary is the idea**, and it survives every sweep
for the field's name. The carriers are not listed here — a partial list read as complete is how the
previous revision of this section went wrong. P1 enumerates them with a sweep that can see them,
and the enumeration is part of what P1 hands back:

    git grep -nP '\b(permanent|temporary)\b' -- scripts governance docs/internal

filtered to the sites that speak about consumers rather than about temporary files, which that
pattern also matches. At least these four kinds exist and each needs a decision: a generator refusal
that offers a third option the stage removes; refusal messages whose "permanent" stops
distinguishing anything; a published summary-row name carrying the same qualifier; and prose in
`docs/internal/MODULE_README_TEMPLATE.md`, which is P2's file rather than P1's — so the two packages
share this subject and must agree on it before either edits.

A stage that removes the field and leaves these is not finished: it leaves the tree describing a
distinction it no longer makes. Whether to rename or to simplify each is P1's call, taken per site
and recorded; what is not permitted is leaving one untouched without saying why.

## What the field is worth today

Nothing to the logic. Every consumer is null; a PHP read of a missing key yields the same null, so
`permanentOwnerWide` / `permanentExact` / `temporary` classify identically either way.

`temporary_contract_consumer_entries` goes with it — its source is gone. The measurement behind that
is narrower than it sounds: flipping the counter's **value** left Governance and Tooling green,
which shows nothing reads the value. It does
not show nothing reads the **row's existence**, and the stage removes the row. P3 closes that gap by
measurement, not by inference.

## The version

To **4**. `version` is enforced by the schema's `const` and by one assertion in
`governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php`; nothing outside the
repository consumes it. Two bumps for one retirement is the honest history, and the alternative —
one number naming two shapes because stage 01 already shipped 3 — is the thing the previous revision
warned against.

## Packages

P1 and P2 have disjoint file sets and can run in parallel. P3 is the orchestrator's and runs after
both. Nothing in P1 is green until all of it lands: the schema and the manifest must move together.

### P1 — the removal and the vocabulary

**Files.**

    docs/internal/modular-architecture-manifest.schema.json
    docs/internal/modular-architecture-manifest.json
    scripts/generate-modular-architecture-production-inventory.php
    governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php

The schema: the branch collapse, the `seam` variant, the version `const`. The manifest: the key on
every consumer, and the version. The generator: every `closes_in` literal it contains — all of them
are edits — plus the summary counter and the vocabulary decided above. The value also reaches the
published artifact without being named, by a whole-consumer copy; that site needs no edit and stops
carrying the key when the key is gone, which is a thing to verify rather than a thing to change.
The governance test carries two separate things and both are P1's: the version assertion, and a
direct `assertNull($consumer['closes_in'])` that a sweep of the generator's reads would never have
found.

**Definition of Done.** Stated in P3; P1 owes the prediction each item is compared against.

### P2 — the records

**Files.**

    docs/adr/<next>-….md            (new)
    docs/adr/README.md
    docs/internal/MODULE_README_TEMPLATE.md
    CHANGELOG.md

One ADR for the whole retirement, both stages, carrying the v2 -> v3 -> v4 history and the rationale
that currently lives only in this plan directory — the directory is deleted when this closes, so an
undocumented format bump would survive nowhere. It records the branch collapse and names the field
that used to distinguish the two merged variants, because after the collapse nothing else does.

`MODULE_README_TEMPLATE.md` is the one operational document outside this plan directory that names
the key. **`CHANGELOG.md`: the decision is P2's to take and to record either way** — the manifest is
internal and has no external consumer, which argues no entry; P2 states which it chose and why, and
P3 does not accept "not applicable" without that sentence.

### P3 — regeneration and acceptance

The orchestrator's: regeneration, the acceptance below, the tree-wide sweep, and the deletion of
this plan directory with its `enumeration.tsv` and its index row.

## Test plan

No new product tests. Two things are added and both are guards rather than assertions about today's
data: the comparator of DoD 1, which needs its own mutation proof, and the row-existence measurement
of DoD 3.

## Definition of Done

Two artifacts change: `production-ownership.tsv` and `manifest-enforcement-summary.tsv`. Every other
generated file, and `qmx.yaml`, must come out byte-identical. Each item below states a property and
derives its own numbers in the run that checks it; none is written here, because the revision that
wrote them down is the revision that got them wrong.

1. **The key-level comparison, and its own proof.** `closes_in` lives inside the JSON cell of a
   surviving column, so a column diff cannot see it leave and stage 01's `oracle.py` is unusable.
   Parse the `consumers` cell of every row of `production-ownership.tsv` before and after and
   establish: the changed rows are exactly the declarations that have consumers, derived from the
   manifest in the same run rather than from a number in this document; every changed cell loses
   exactly the member `closes_in` and nothing else; no row gains or loses a consumer; row count and
   column identity unchanged. **The comparator lives with its campaign, not in the product tree, and
   is proved before it is trusted:** plant a cell that loses a second member, one that gains a
   consumer, and one that changes a consumer's *order* without changing its members — the last
   because the cure that added the "nothing else" clause did not add a plant for it. Quote each
   refusal. *Green for the wrong reason if:* it reports no differing rows because parsing failed and
   the empty set read as agreement.
2. **Everything else is byte-identical.** Enumerate the generated directory from the filesystem in
   the same run, subtract the two files named above, and compare the remainder plus `qmx.yaml` across
   the regeneration. *Green for the wrong reason if:* the comparison walks a list of files it
   expected rather than the directory, so a file that stopped being generated reads as unchanged.
3. **The summary row leaves, and the only thing that notices is the gate that must.** Removing
   `temporary_contract_consumer_entries` moves a published artifact, so the freshness gate fires by
   design; a bare "Governance goes red" proves nothing. Separate the two: run the suites against the
   removal **with the artifact regenerated**, so freshness is satisfied and anything still failing is
   something that reads the row's existence. Report both runs. *Green for the wrong reason if:* the
   freshness failure is read as the answer, or the run is taken before the removal.
4. **The two unplantable populations, on both trees.** Before P1: a non-null plant on a
   `composition_binding` consumer and on a `contract_surface` consumer, each refused at schema load,
   each message quoted. After P1: the same two plants, with what they do then — the branch that
   refused them is gone, so anything still refusing must be named, and the messages are not the same
   ones. *Green for the wrong reason if:* only one tree state is measured.
5. **The plain-import plant, prediction first.** Write the predicted diff before running, on the tree
   before P1; then state what the same plant does after. *Green for the wrong reason if:* the
   prediction is written after the run.
6. **The vocabulary, enumerated and decided site by site.** Run P1's sweep, list every site it finds
   that speaks about consumers, and for each say what was done or why nothing was. One of them is the
   published summary-row name, and renaming it moves `manifest-enforcement-summary.tsv` — which is
   one of the two files item 2 already excludes, so the two items agree; say so rather than leaving a
   reader to discover it. *Green for the wrong reason if:* the sweep is for `closes_in`, which by
   then matches nothing.
7. **The seam branch goes rather than stays unexercised.** `enforcement_seams` is empty, so the
   `seam` definition's `closes_in` is declared strictness no tracked data has tested. Establish that
   it is gone from the schema, not merely that nothing exercises it — which was true before the
   change too.
8. **P2's `CHANGELOG.md` decision is recorded, either way.** An entry, or a sentence in the ADR
   saying why an internal manifest format needs none. *Green for the wrong reason if:* the absence of
   an entry is read as the decision.
9. `! git grep -q closes_in` outside the ADR. P3's.
10. `composer check` green from a clean clone with copied `vendor`, `website/.venv` and
    `html-report/node_modules`. P3's.
11. This plan directory is deleted, `enumeration.tsv` with it, and its row leaves
    `docs/internal/plans/README.md`. P3's.

## A hazard for the executor, not for the plan

**"Every intermediate state is schema-invalid" is false, and the state it misses is the dangerous
one.** Removing the key from the data alone, or from the schema alone, is refused loudly at load.
But a tree where the schema is collapsed and the data stripped while the generator still reads the
key **validates**: the reads resolve to `null` on a missing key exactly as they did on an explicit
one, the classification is unchanged, and `manifest-enforcement-summary.tsv` comes out byte-identical.
The only outward sign is an undefined-key warning per read. A P1 that stops there looks finished and
is not.

Before #102 landed, a schema-invalid intermediate state hung the Tooling suite indefinitely rather
than failing it. Work on a tree that carries that fix.

With Xdebug active for CLI, each undefined-key warning dumps a stack that serializes the whole
manifest, and the check dies of OOM instead of naming a stale artifact. `XDEBUG_MODE=off` isolates
it; CI does not run Xdebug.
