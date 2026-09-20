# Stage 02 — retiring `closes_in`

Removes `closes_in`: the P-dictionary in its second home, declared on every consumer entry and
meaning "the migration package in which this seam closes". Stage 01 removed the first home and took
the manifest to version 3.

## Measured on `423df74a`, not carried forward

| Fact                                                 | Value                                                              |
| ---------------------------------------------------- | ------------------------------------------------------------------ |
| manifest version                                     | 3                                                                  |
| declarations / consumers                             | 959 / 2508                                                         |
| by `relation`                                        | 2428 plain imports, 74 `composition_binding`, 6 `contract_surface` |
| consumers with a non-null `closes_in`                | **0**                                                              |
| `enforcement_seams`                                  | empty                                                              |
| lines of `production-ownership.tsv` carrying the key | **367** — one per declaration that has consumers                   |
| read sites in the production generator               | 6, plus one pass-through that publishes the value                  |
| schema lines naming it                               | 14, across 6 consumer `oneOf` variants and the `seam` definition   |
| `temporary_contract_consumer_entries`                | 0                                                                  |

The previous revision's figures (955 / 2501 / 2422 / 73 / 364, "eight read sites") are all stale.
Re-take these before executing: this table is itself a carried-forward number the moment it is read
on a later tree.

## Three things the previous revision assumed, refuted by experiment

**1. "One plant per population, three in all" cannot be executed.** The `composition_binding` and
`contract_surface` `oneOf` branches pin `closes_in` to type `null`, so a non-null plant is refused at
schema load — `String value found, but a null is required`, exit 1. Only the plain-import population
can carry a value at all. The stage therefore cannot demonstrate those two branches are inert by
planting; it must argue from the schema that they are unreachable by construction, and quote the
refusal as the evidence. The plain-import plant *was* run, and its prediction held exactly: one row
in `manifest-enforcement-summary.tsv`, one row in `production-ownership.tsv`, `qmx.yaml` untouched.

**2. The three relations are not reached by different code paths.** They are distinct `if` branches
of one function; the three relation-specific functions downstream never touch the key.

**3. Removing the key is not a subtraction.** `closes_in` is the **only** field distinguishing the
permanent-exact consumer variant from the temporary-exact one. Strip it from `required` and
`properties` and those two variants become byte-identical, so `oneOf`'s exactly-one rule refuses all
2428 plain-import consumers whose `source_fqcn` is a string. **The schema edit is a branch collapse,
six consumer variants to five**, and nothing else in the tree records that those two variants ever
differed by one field. A stage that edits the schema field-by-field produces a manifest nothing can
load.

## What the field is worth today

Nothing to the logic. Every consumer is null; a PHP read of a missing key yields the same null, so
`permanentOwnerWide` / `permanentExact` / `temporary` classify identically either way and
`manifest-enforcement-summary.tsv` comes out byte-identical. The field's only live effects are the
schema's `required` gate, the `oneOf` uniqueness it provides, and the literal JSON text in one
published column.

`temporary_contract_consumer_entries` goes with it: its source is gone, and **nothing reads it** —
measured by flipping it 0 -> 1 for real and running the whole Governance and Tooling suite against
that state, 970/970 green.

## The version

To **4**. `version` is enforced by the schema's `const` and by one assertion in
`governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php`; nothing outside the
repository consumes it. Two bumps for one retirement is the honest history, and the alternative —
one number naming two shapes because stage 01 already shipped 3 — is the thing the previous revision
warned against. Cost: one `const`, one `assertSame`.

## Packages

**P1 — the removal.** `docs/internal/modular-architecture-manifest.schema.json` (the branch collapse
and the `seam` variant), `docs/internal/modular-architecture-manifest.json` (2508 entries),
`scripts/generate-modular-architecture-production-inventory.php` (6 read sites, the pass-through, and
the summary counter), the version `const` and its assertion.

**P2 — the records.** One ADR for the whole retirement, both stages, carrying the v2 -> v3 -> v4
history and the rationale that currently lives only in the plan directory — the plan is deleted when
this closes, so an undocumented format bump would survive nowhere. `docs/internal/MODULE_README_TEMPLATE.md`
is the one operational document outside the plan directory that names the key. `CHANGELOG.md` only if
a consumer can observe it; the manifest is internal, so the answer is probably no — decide, do not
default.

**P3 — regeneration and acceptance.** The orchestrator's.

## Test plan

No new product tests. The stage's evidence is the key-level comparison below plus the schema
refusals quoted for the two populations that cannot be planted.

## Definition of Done

1. **The key-level comparison, which a column diff cannot make.** `closes_in` lives inside the JSON
   cell of a surviving column, so `oracle.py` from stage 01 is unusable here. Parse the `consumers`
   cell of every row of `production-ownership.tsv` before and after, and assert: exactly **367** rows
   change; every changed cell loses exactly the member `closes_in` and nothing else; no row gains or
   loses a consumer; the row count and column identity are unchanged.
2. `manifest-enforcement-summary.tsv` loses exactly the `temporary_contract_consumer_entries` row and
   no other row moves.
3. The schema's consumer variants number five, and the collapse is recorded in the ADR with the field
   that used to distinguish the two merged ones.
4. Quoted refusals, as the evidence for the two populations that cannot be planted: a non-null plant
   on a `composition_binding` consumer and on a `contract_surface` consumer, each rejected at schema
   load, with the message.
5. The plain-import plant, before and after, with its prediction written first.
6. `composer check` green from a clean clone with copied `vendor`, `website/.venv` and
   `html-report/node_modules`.
7. `! git grep -q closes_in` outside the deleted plan directory and the ADR.
8. The plan directory is deleted and its `enumeration.tsv` with it; the index row goes.

## A hazard for the executor, not for the plan

Every intermediate state of P1 is schema-invalid. Before #102 landed, that hung the Tooling suite
indefinitely rather than failing it, because the refusal test read one pipe to EOF before the other
and the generator's schema error is megabytes wide. Work on a tree that carries that fix.

With Xdebug active for CLI, each undefined-key warning dumps a stack that serializes the whole
manifest — 1.3 MB of warnings becomes 194 MB and the check dies of OOM instead of naming a stale
artifact. `XDEBUG_MODE=off` isolates it. CI does not run Xdebug.
