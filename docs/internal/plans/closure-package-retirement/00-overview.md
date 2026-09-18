# Retiring the closure package: a migration's leftover, not a governance field

## The problem, in one sentence

`closure_package` is a fossil of the ADR 0022 migration — the P-labels were the
identifiers of the packages that carried declarations to their capability
owners, the migration landed, and nothing reads the label any more.

## What was measured, on `11dcc543`

The enumeration is `enumeration.tsv` — 173 places on five axes. Counts belong
there, not in prose; the three that decide the shape of the work:

- **One semantic consumer, and it is the schema.** `closure_package` sits in
  `required` under `$defs.declaration` with `pattern` `^P[1-8](?:-[A-E])?$`,
  validated by `JsonSchema\Validator` when the manifest loads. Nothing else
  branches, filters, sorts or counts on the value. No reader of the seven
  generated artifacts exists outside the two generators that write them.
- **The only candidate for business meaning is dead code.** `validateP4Target()`
  (`generate-modular-architecture-production-inventory.php:1317`) is never
  called, and would fatal if it were: its first statement reads
  `$manifest['p4_target']`, a key the manifest does not carry. Established
  independently from the plan document that first claimed it.
- **Two independent producers share one vocabulary.** The production generator
  takes the label from the manifest. The test generator does not read the
  manifest at all — `classifyOwner()` derives a label from the test file's path,
  because the manifest covers `src/**/*.php` and no test lives there. Same
  spelling, same `P1`…`P8` dictionary, different source. This is what stage 04
  measured as "inconsistency" and could not name.

## The decision: retire the concept, not just the column

Removing the published column while leaving the label behind would leave 146
literals computing a value nothing prints — the promise-without-effect shape
this repository keeps being bitten by, and in the form a name sweep does not
find. So the owner/package pairs collapse to the owner alone.

**Rejected: removing only the column.** It is the cheaper edit and it is what a
sweep by name would scope. Its residue is the 146 `literal` rows of
`enumeration.tsv`: `documentationDisposition()` would keep assigning a package
to 80 documents, `TOOLING_TEST_ROOT_OWNERS` would keep pairing every tooling
root with one, and `classifyOwner()` would keep deriving one per test file —
all feeding an output that no longer exists. A later reader would have to
rediscover that this is dead, by the same measurement that produced this plan.

**Consequence the cheaper variant hides:** the concept also lives inside
prose that survives either way. `fixtureDirectoryRows()` emits the disposition
strings `P8: retain until consumer proof; delete only if confirmed orphan.` and
`Split by file owner and closure package.`. Stage 01 decides their fate
explicitly rather than leaving it to whoever notices.

## The manifest format changes, so its version does

`version` is `2` in the manifest and `{"const": 2}` in the schema. Dropping a
`required` property is a format change: the manifest goes to `3` and the schema's
`const` with it, in the same commit as the field removal. Leaving it at `2`
would make two incompatible shapes share a version number, which is the one
thing a version is for. No PHP reads `version`; the schema is its only consumer,
so this costs two edits and buys the format's history back.

## How the enumeration was obtained, and what it cannot see

Snapped by script from the tree at `11dcc543`, on five axes: the name
(`closure_package`), the plural (`closure_packages`), the **value** written as a
literal (`'P0'`…`'P8'`, with the `-A`…`-E` suffixes), the schema, and the headers
of the generated artifacts.

The value axis exists because the name axis is blind: in
`documentationDispositionRows()` the label travels in a variable called
`$closure`, and a sweep for `closure_package` does not see it. Three places
assign the label by tuple position — `documentationDisposition()`,
`TOOLING_TEST_ROOT_OWNERS`, `classifyOwner()` — and those are found by the value,
not the name.

**Not covered:** a label built by concatenation, or held in a non-literal
(a constant, a config value, a computed string). Nothing of that shape is known
to exist, and the stage's closing check is what would expose it — after the edit,
`grep -cE "'P[0-8](-[A-E])?'"` over both generators must return `0`, which no
surviving producer of a label can pass.

## Stages

| Stage               | Subject                                                              |
| ------------------- | -------------------------------------------------------------------- |
| [01](01-removal.md) | The removal itself: manifest, schema, both generators, re-derivation |

One stage, three packages; the packages and their Definition of Done are in
that file.

## Acceptance

The column is removed correctly when the regenerated artifacts are what the
current artifacts are **minus that column, byte for byte** — which is a stronger
claim than "the diff looks right", and the only one that proves the removal
moved nothing else. `oracle.py` in this directory is that comparison; stage 01
states how it is run and what its refusals mean.
