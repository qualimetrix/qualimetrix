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
| read sites in the production generator                      | 6, of which 5 are edits and 1 publishes the value                  |
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
for the field's name:

- the generator refuses with `consumer … must be permanent owner-wide, permanent exact-source, or
  temporary exact-source` — after this stage the third option cannot exist, so the message offers a
  remedy that is no longer reachable;
- `composition_binding` and `contract_surface` refuse with `must permanently authorize …` and
  `must name a permanent exact source`, where "permanent" distinguishes from a temporary that is
  about to stop existing;
- `manifest-enforcement-summary.tsv` publishes `permanent_composition_bindings`, a name whose
  qualifier stops distinguishing anything.

A stage that removes the field and leaves these is not finished: it leaves the tree describing a
distinction it no longer makes. Whether to rename or to simplify each is P1's call, taken per site
and recorded; what is not permitted is leaving one untouched without saying why.

## What the field is worth today

Nothing to the logic. Every consumer is null; a PHP read of a missing key yields the same null, so
`permanentOwnerWide` / `permanentExact` / `temporary` classify identically either way.

`temporary_contract_consumer_entries` goes with it — its source is gone. The measurement behind that
is narrower than it sounds and is stated at its true width: flipping the counter's **value** 0 -> 1
and running Governance and Tooling left 972/972 green, which shows nothing reads the value. It does
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
every consumer, and the version. The generator: five read sites, the summary counter, and the
refusal messages named above — **not** the sixth site, which publishes the value and disappears with
the key rather than being edited. The governance test carries two separate things and both are P1's:
the version assertion, and a direct `assertNull($consumer['closes_in'])` that no sweep for the
generator's read sites would have found.

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

Each item names the state in which it would be green for the wrong reason, because the previous
revision's items did not.

1. **The key-level comparison, and its own proof.** `closes_in` lives inside the JSON cell of a
   surviving column, so a column diff cannot see it leave and stage 01's `oracle.py` is unusable.
   Parse the `consumers` cell of every row of `production-ownership.tsv` before and after and assert:
   the changed rows are exactly the declarations that have consumers, derived from the manifest in
   the same run; every changed cell loses exactly the member `closes_in` and nothing else; no row
   gains or loses a consumer; row count and column identity unchanged.
   **The comparator is a deliverable with an owner (P3) and it is proved before it is trusted:**
   plant a cell that also loses a second member, and one that gains a consumer, and quote both
   refusals. *Green for the wrong reason if:* it reports "0 rows differ" because it failed to parse
   and treated the empty set as agreement.
2. **The nineteen artifacts that must not move.** `docs/internal/generated/modular-architecture/`
   holds 20 files; this stage changes two. Assert the other eighteen and `qmx.yaml` are byte-identical
   across the regeneration. *Green for the wrong reason if:* the comparison enumerates only the files
   it expected to see.
3. **`temporary_contract_consumer_entries` leaves, and nothing notices its absence.** Not the value
   flip that was already run — remove the **row** on the current tree, run Governance and Tooling,
   and record what fails. *Green for the wrong reason if:* the run is taken before the removal.
4. **The two unplantable populations, in that order and with both states recorded.** On the tree
   **before** P1: a non-null plant on a `composition_binding` consumer and on a `contract_surface`
   consumer, each refused at schema load, message quoted. On the tree **after** P1: the same two
   plants, and state what they do then — the branches they hit no longer exist, so a plant that is
   still refused is refused by something else and that something must be named. *Green for the wrong
   reason if:* only one tree state is measured, since the refusal is identical in both for reasons
   that are not.
5. **The plain-import plant, prediction first.** Write the predicted diff before running, on the tree
   before P1; then state what the same plant does after P1. *Green for the wrong reason if:* the
   prediction is written after the run.
6. **The vocabulary sweep, which a name sweep is not.** No refusal message, summary-row name or
   docblock still distinguishes permanent from temporary among consumers, or each survivor is listed
   with the reason it still distinguishes something. *Green for the wrong reason if:* it greps for
   `closes_in`, which by then matches nothing anywhere.
7. **The branches nothing exercises are removed, not carried.** `enforcement_seams` is empty, so
   the `seam` definition's `closes_in` — required and non-nullable there, unlike every consumer
   variant — is declared strictness that no tracked data has ever tested. It goes with the rest, and
   the ADR says so rather than leaving a reader to wonder whether the seam vocabulary survived on
   purpose. *Green for the wrong reason if:* the check confirms the branch is unexercised, which it
   was before the change too, instead of confirming it is gone.
8. `! git grep -q closes_in` outside the ADR. P3's.
9. `composer check` green from a clean clone with copied `vendor`, `website/.venv` and
   `html-report/node_modules`. P3's.
10. This plan directory is deleted, `enumeration.tsv` with it, and its row leaves
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
