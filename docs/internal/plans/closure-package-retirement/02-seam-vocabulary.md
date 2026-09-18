# Stage 02 — the seam vocabulary

Does not start with stage 01. It removes `closes_in`: the same P-dictionary in a
second home, declared in the schema with the identical pattern and meaning "the
migration package in which this seam closes".

## What it is, measured on `11dcc543`

- 2501 consumers across 955 declarations, in three populations by `relation`:
  **2422 plain imports, 73 `composition_binding`, 6 `contract_surface`**;
- `closes_in` non-null on **none** of them;
- `enforcement_seams` is an empty object and
  `temporary_contract_consumer_entries` reads `0`;
- the production generator reads it in eight places, branching on
  `closes_in === null` / `!== null`;
- **it is published**: the key appears in 364 rows of `production-ownership.tsv`,
  inside the JSON cell of the `consumers` column.

## Two things the previous revision of this stage got wrong

**It is not "behaviour, not data".** 364 published rows carry the key. Removing
it changes artifacts, so this stage needs an artifact comparison as much as
stage 01 does — and cannot borrow stage 01's, because `oracle.py` compares
columns and this key lives *inside* a surviving column's cell. A comparison that
can address a JSON key is part of this stage's work, not an assumption it makes.

**One planted value cannot prove what it was asked to prove.** The previous
revision said: set `closes_in` on one consumer, regenerate, and stop if anything
changes. Two defects in that, both fatal to it:

- Any plant changes `temporary_contract_consumer_entries` from `0` to `1` —
  a summary counter, not one of the five branches. So "something changed" fires
  regardless of whether the branches behave, and the criterion cannot
  distinguish a branch from an echo.
- A single plant touches one population. `composition_binding` (73) and
  `contract_surface` (6) are reached by different code paths than a plain
  import, so a plant on an import says nothing about them.

## What this stage must establish instead

Not "did anything change" but "did exactly the predicted things change".

1. **Census before touching anything.** For each of the eight read sites, how
   many consumers take each side today. With `closes_in` null everywhere, one
   side of each is expected to have zero members — and that is the honest
   statement: *this manifest does not exercise that branch*. It is different
   from "the branch is equivalent to its replacement", and the distinction is
   the whole reason this stage is separate.

2. **One plant per population**, three in all, each in its own copy: a plain
   import, a `composition_binding`, a `contract_surface`. For each, predict
   before running — the summary counter moving from `0` to `1`, and which cells
   of `production-ownership.tsv` gain the value. Then compare. A prediction that
   holds is evidence the branch is inert; a change outside the prediction stops
   the stage and is reported.

3. **Then the removal**, judged by a comparison that can name a JSON key rather
   than a column.

A stage that removes the field without having planted a non-null value into each
population has not tested the branches it deleted — it has only observed that a
corpus of nulls produces the same output either way.

## Scope

`scripts/generate-modular-architecture-production-inventory.php`, the schema's
`seam` definition and `closes_in` inside `consumer`, and the manifest's 2501
consumers.

**The version question this stage inherits.** Stage 01 moves the manifest to
version 3. This stage removes another `required`-adjacent key, which by the same
reasoning is version 4. Two bumps for one retirement may be the honest history or
may be noise; either answer is defensible, but it must be *chosen*, because
shipping stage 01 and stage 02 separately with one bump between them leaves the
same version number naming two different shapes. Decide it when this stage is
written, with stage 01 already landed and its version visible.

## Definition of Done

Stated when the stage is planned, and it must include the census, the three
plants with their predictions recorded in advance, and the key-level comparison.
