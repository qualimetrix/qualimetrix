# Retiring the closure package: a migration's leftover, not a governance field

## The problem, in one sentence

`closure_package` is a fossil of the ADR 0022 migration — the P-labels named the
packages that carried declarations to their capability owners, the migration
landed, and what still reads the label is the schema that validates its shape.

## The slot carries three vocabularies, not one

This is the finding that shapes the work, and the one a sweep by label misses.
The domain, derived from the artifacts rather than guessed from a shape, is 18
values, and four of them are not P-labels at all:

| Where                         | What the slot holds                          |
| ----------------------------- | -------------------------------------------- |
| four production artifacts     | P-labels only                                |
| `documentation-ownership.tsv` | P-labels, plus `shared` (62 of 160 rows)     |
| `test-ownership.tsv`          | **`permanent` (622 of 933)**, P-labels (311) |

`permanent` is the majority value in the test artifact. A closing check written
as "no P-label remains" would pass over all 622 of them.

Behind the three vocabularies are two independent producers. The production
generator takes the label from the manifest. The test generator computes its own
from the test file's path in `classifyOwner()` — it reads the manifest's `owners`
section, but never a declaration's `closure_package`, because the manifest covers
`src/**/*.php` and no test lives there. Same spelling, same slot, different
source. This is what stage 04 measured as "inconsistency" and could not name.

## What was measured, on `11dcc543`

The enumeration is `enumeration.tsv` — 189 places. The three that decide the
shape of the work:

- **The semantic consumer is the schema.** `closure_package` sits in `required`
  under `$defs.declaration` with `pattern` `^P[1-8](?:-[A-E])?$`, validated when
  the manifest loads. Nothing branches, filters, sorts or counts on the value.
  No reader of the seven generated artifacts exists outside the two generators.
- **The one candidate for business meaning is dead code.**
  `validateP4Target()` (`generate-modular-architecture-production-inventory.php:1317`)
  is never called and would fatal if it were: it reads `$manifest['p4_target']`,
  a key the manifest does not carry. Its schema counterparts are dead with it —
  walking `$ref` from the root leaves `internalGrant`, `p4Target` and
  `p4TargetDeclaration` unreachable. Counting references does not show this:
  `p4Target` and `p4TargetDeclaration` reference each other, so both look used.
- **The concept is published as prose, not only as a column.** 93 rows of
  `documentation-ownership.tsv` end in a sentence naming a migration package, and
  30 rows of `test-ownership.tsv` name a closure package. Removing the column
  without these leaves the artifacts asserting a concept the tree no longer has.

## The decision: retire the concept, not just the column

Removing the published column while leaving the label behind would leave 146
literals computing a value nothing prints — the promise-without-effect shape this
repository keeps being bitten by, in the form a name sweep does not find. The
owner/package pairs collapse to the owner alone.

**Rejected: removing only the column.** It is the cheaper edit and it is what a
sweep by name scopes. This variant was measured, not argued: a probe carried it
out on a copy and `composer check` came back green. That proves the narrow claim
— the column detaches without consequence — and nothing about the residue, which
is the 146 `value:P` rows of `enumeration.tsv` plus the 123 rows of published
prose above.

## The manifest format changes, so its version does

`version` is `2` in the manifest and `{"const": 2}` in the schema. Dropping a
`required` property is a format change: the manifest goes to `3` and the schema's
`const` with it.

**Three consumers read `version`, and two of them are controls that pin it:**

| Consumer                                                                          | What it does                                           |
| --------------------------------------------------------------------------------- | ------------------------------------------------------ |
| `governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php:69` | `assertSame(2, …)` — pins the number                   |
| `governance/RepositoryEntrypoints/MemoryCeilingManifestTest.php:44,61`            | asserts non-empty, and equality with a package version |
| the schema's `const`                                                              | refuses any other value                                |

The pinning control is why the bump is not a free edit, and why it belongs in the
same package as the manifest: a tree with the version bumped and the control
untouched is red, and the failure surfaces at acceptance rather than at the edit.

## Deliberately out of scope: the seam vocabulary

The same P-dictionary has a second home. `closes_in` on a consumer is declared
with the identical pattern and read by the production generator in five places.
Measured: **2501 consumers, `closes_in` non-null on none of them**, and
`enforcement_seams` is an empty object.

It is the same fossil, but removing it changes control flow rather than published
data — the generator branches on `closes_in === null` to tell a permanent grant
from a temporary one, and with the field gone every grant becomes permanent by
construction. That is a behaviour change needing its own proof, and bundling it
here would hide it inside a data-only removal. [Stage 02](02-seam-vocabulary.md)
carries it, and does not start with stage 01.

Naming it here is the point: without this section, the work would end with the
concept half-retired and no record of which half.

## How the enumeration was obtained, and what it cannot see

Snapped by script at `11dcc543` on these axes: the name, the plural, the **value
as a literal — every value of the domain above, not a shape it was assumed to
have**, the schema's field, patterns and version const, the three `version`
consumers, the artifact columns, and the published prose.

The value axis exists because the name axis is blind: the label travels in a
variable called `$closure` in one place and is written bare in 146 others.
The domain axis exists because the *first* version of this enumeration searched
for `'P[0-8]'` and thereby missed `permanent`, `shared`, `Run documentation` and
`Finding documentation` — a quarter of the slot's occurrences.

**Not covered:** a value built by concatenation, or held in a constant or config
rather than a literal. Nothing of that shape is known to exist. Stage 01's
closing check is what would expose it — and, unlike the first draft's, that check
is written against the domain, so it cannot be blind in the same place the
enumeration was.

## Stages

| Stage                       | Subject                                                            |
| --------------------------- | ------------------------------------------------------------------ |
| [01](01-removal.md)         | The field: manifest, schema, both generators, prose, re-derivation |
| [02](02-seam-vocabulary.md) | The seam vocabulary — behaviour, not data; does not start with 01  |

## Acceptance

Stage 01 is correct when the regenerated artifacts are what the current artifacts
are **minus that column** — a stronger claim than "the diff looks right", and the
only one proving the removal moved nothing else. `oracle.py` is that comparison;
it works on bytes, covers every file in the directory, and was proven to bite on
seven planted breakages before being trusted. Stage 01 states how it is run, and
— because the generators overwrite the very directory it compares against — when
the "before" side has to be captured.
