# Stage 02 — the seam vocabulary

Does not start with stage 01, and is not a continuation of it: this one changes
control flow, where stage 01 changed published data.

## The subject

`closes_in` on a consumer is the same P-dictionary in a second home — declared
in the schema with the identical `^P[1-8](?:-[A-E])?$` pattern, meaning "the
migration package in which this seam closes".

Measured on `11dcc543`:

- 2501 consumers across 955 declarations;
- `closes_in` non-null on **none** of them;
- `enforcement_seams` is an empty object;
- the production generator branches on it in five places, all of the shape
  `closes_in === null` / `!== null`.

So the field is as dead as `closure_package` was, but it is *read*, and what
reads it decides something:

```
// generate-modular-architecture-production-inventory.php, around :1255
$permanentOwnerWide = $consumer['source_fqcn'] === null && $consumer['closes_in'] === null;
$permanentExact     = is_string($consumer['source_fqcn']) && $consumer['closes_in'] === null;
// ... implementation details: three further branches filter or classify on the same test
```

With the field gone, every grant is permanent by construction. Today every grant
*is* permanent, because the field is null everywhere — so the behaviour is
expected to be identical. "Expected" is the word this stage has to replace with
a measurement.

## Why it is not part of stage 01

Bundling a behaviour change into a data-only removal hides it. Stage 01's
acceptance is "the artifacts are the old artifacts minus a column" — an oracle
that says nothing about which branch the generator took to produce them. A
behaviour change that happens to leave the output identical would pass that
oracle silently, and the one case where it did not would arrive as a surprise
with two candidate causes.

## What this stage must prove, beyond stage 01's acceptance

The oracle is necessary and not sufficient here. Identical artifacts are
consistent both with "the branch was equivalent" and with "the branch was never
taken in this corpus". The stage answers which:

- **Before removing anything**, establish what the branches decide today: count
  how many consumers take each side of each of the five tests. If some branch
  has zero members on this manifest, say so — that is the honest statement
  ("this corpus does not exercise it"), and it is different from "the branch is
  equivalent to its replacement".
- **Plant the counterfactual.** Set `closes_in` to a P-label on one consumer in
  a copy, regenerate, and record what changes in the artifacts. If nothing
  changes, the branch was already inert and its removal is a simplification. If
  something changes, the field is live in a way the null-everywhere measurement
  did not reveal, and this stage stops and reports rather than proceeding.

That second step is the whole stage. Without it, removal rests on "all values are
null today", which is a statement about the current manifest, not about the code.

## Scope

`scripts/generate-modular-architecture-production-inventory.php` and
`docs/internal/modular-architecture-manifest.schema.json` (the `seam` definition
and `closes_in` inside `consumer`). The manifest's 2501 consumers lose the key;
whether that is another version bump is decided when the stage is written, not
assumed here — stage 01 will have moved the version once already, and two bumps
for one retirement may or may not be what the format's history should show.

## Definition of Done

Stated when the stage is planned. It must include the counterfactual probe above
and its recorded outcome; a stage that removes the field without having planted
a non-null value has not tested the branch it deleted.
