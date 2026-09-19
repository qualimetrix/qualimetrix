# Retiring the closure package: a migration's leftover, not a governance field

## The problem, in one sentence

`closure_package` is a fossil of the ADR 0022 migration — the P-labels named the
packages that carried declarations to their capability owners, the migration
landed, and what still reads the label is the schema that validates its shape.

## The concept has four homes, and only one of them is the column

Two review rounds found this one surface at a time, each time by a different
blindness. The enumeration is `enumeration.tsv` — 216 places. What matters is
not the count but the kinds, because each kind needs a different check:

| Home                                | Where it is                                              |
| ----------------------------------- | -------------------------------------------------------- |
| the column                          | one slot per declaration, published in seven artifacts   |
| the **seam field**                  | `closes_in`, 2501 consumers, and it is **published too** |
| **prose inside a surviving column** | 128 rows across two artifacts                            |
| **prose in `qmx.yaml`**             | two comments naming `P4`                                 |

The last two are the reason this document is on its third revision. A check that
watches the column passes while the concept stays in the artifact next to it.

## The slot carries three vocabularies, not one

The domain, derived from the artifacts rather than guessed from a shape, is 18
values, and four are not P-labels:

| Where                         | What the slot holds                          |
| ----------------------------- | -------------------------------------------- |
| four production artifacts     | P-labels only                                |
| `documentation-ownership.tsv` | P-labels, plus `shared` (62 of 160 rows)     |
| `test-ownership.tsv`          | **`permanent` (622 of 932)**, P-labels (310) |

`permanent` is the majority value in the test artifact. A closing check phrased
"no P-label remains" passes over all 622 of them — the first draft's check was
phrased exactly that way.

Behind the three vocabularies are two producers. The production generator takes
the label from the manifest. The test generator computes its own from the test
file's path in `classifyOwner()`; it reads the manifest's `owners` section, but
never a declaration's `closure_package`. Same spelling, same slot, different
source. This is what stage 04 measured as "inconsistency" and could not name.

## What was measured, on `11dcc543`

- **The semantic consumer is the schema.** `closure_package` sits in `required`
  under `$defs.declaration` with `pattern` `^P[1-8](?:-[A-E])?$`, validated when
  the manifest loads. Nothing branches, filters, sorts or counts on the value.
- **The one candidate for business meaning is dead code.** `validateP4Target()`
  is never called and would fatal if it were: it reads `$manifest['p4_target']`,
  a key the manifest does not carry. Its schema counterparts are dead with it —
  walking `$ref` from the root leaves `internalGrant`, `p4Target` and
  `p4TargetDeclaration` unreachable. Counting references hides this, because
  `p4Target` and `p4TargetDeclaration` reference each other.
- **`closes_in` is null on all 2501 consumers**, `enforcement_seams` is empty,
  and `temporary_contract_consumer_entries` is `0` — yet the key is printed in
  364 rows of `production-ownership.tsv` and read in eight places.

## The decision: retire the concept, not just the column

Removing the published column while leaving the label behind would leave 146
literals computing a value nothing prints — the promise-without-effect shape
this repository keeps being bitten by, in the form a name sweep does not find.

**Rejected: removing only the column.** This variant was measured, not argued:
a probe carried it out on a copy and `composer check` came back green. That
proves the narrow claim — the column detaches without consequence — and nothing
about the residue.

## The manifest format changes, so its version does

`version` is `2` in the manifest and `{"const": 2}` in the schema. Dropping a
`required` property is a format change, so the manifest goes to `3` and the
schema's `const` with it.

**Exactly one PHP consumer reads it:**
`governance/ModularOwnership/ModularArchitectureGovernanceIntegrationTest.php:69`
pins the literal with `assertSame(2, …)`, so it moves in the same package as the
manifest — otherwise the tree is red and the failure surfaces at acceptance.

Revision 2 of this plan listed a second consumer,
`governance/RepositoryEntrypoints/MemoryCeilingManifestTest.php`. It reads a
different manifest entirely — a benchmark's memory-ceiling fixture whose
`version` is a `laravel/framework` release string. It was found by grepping
`$manifest['version']` without asking *which* manifest: the same blindness as
sweeping by P-shape, on a different axis.

## Stages

| Stage                       | Subject                                                                 |
| --------------------------- | ----------------------------------------------------------------------- |
| [01](01-removal.md)         | The field, the prose, and the config comments                           |
| [02](02-seam-vocabulary.md) | `closes_in` — published data plus five branches; does not start with 01 |

**Why they are separate, restated.** Revision 2 said "01 is data, 02 is
behaviour". That was wrong: `closes_in` is published in 364 artifact rows, so
stage 02 changes data too. The real distinction is narrower and still holds —
stage 01 removes a field nothing branches on, while stage 02 removes one that
five branches test, so 02 needs a counterfactual probe that 01 does not. They
are also proved differently: stage 01's oracle compares columns, and it
**cannot** see a key removed from inside a JSON cell, which is exactly the shape
of stage 02's change.

## How the enumeration was obtained, and what it cannot see

Snapped by script at `11dcc543`, on axes for each home above: the name, the
plural, the value as a literal (**every value of the domain, not a shape it was
assumed to have**), the schema's field, patterns, seam and version const, the
version consumer, the artifact columns, the published prose, the seam key inside
the consumers cell, and the `qmx.yaml` comments.

Each axis exists because an earlier one was blind. The name axis misses the
label travelling in `$closure`. The P-shape axis missed `permanent`, `shared`
and two documentation values — a quarter of the slot. The column axis missed 128
rows of prose and two config comments.

**Not covered, and stage 01 measured that the exclusion is not empty.** The
enumeration cannot see a label spelled into an identifier or into prose that is
not a slot value, and this plan said nothing of that shape was known to exist.
Executing stage 01 found otherwise: ten constants in the test generator carry a
P-label in their name (`P3_TEST_PATHS`, `P4_IGNORED_FIXTURE_PATHS`,
`P6_A_FINDING_TEST_PATHS` and seven more), and both generators keep P-labels in
refusal messages and in retired-path records. **None of it reaches a published
artifact** — every rendered file holds zero P-tokens after stage 01 — so it is
naming inside two development scripts, not a concept the product still
publishes. It is a separate subject from the slot and is deliberately left to
its own work; it is recorded here so the next reader does not mistake the
enumeration's silence for absence.

The honest statement about this enumeration is not that it is complete, but that
each of its three revisions was found incomplete by a different check than the
one that produced it — so stage 01's closing checks are written per home, not as
one grep.

## Acceptance

Stage 01 is correct when the regenerated artifacts are the current ones **minus
that column**, where "current" is captured without touching the working tree.
`oracle.py` is that comparison; it works on bytes and covers every file it is
pointed at, including `qmx.yaml`, which the generator also renders and revision
2 left out of the snapshot. Its stated limit: it compares columns, so the prose
rows and the seam key need their own checks, named in stage 01.

Capturing "current" takes **two flags, not one**: `--output-directory=` for the
artifacts and `--qmx-output=` for the config, which otherwise defaults to the
live `qmx.yaml` and is written into the repository root. Stage 01 states the
full recipe and why a one-flag snapshot is both short a file and destructive.
