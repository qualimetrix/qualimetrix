# 0086. One Configuration Document, Merged by Declared Policy

**Date:** 2026-09-25
**Status:** Accepted
**Related:** [0009 — YAML Loader Normalization](0009-yaml-loader-normalization-model.md),
[0022 — Capability-Oriented Modular Monolith](0022-capability-oriented-modular-monolith.md),
[0058 — Layered Value Survival](0058-a-layers-value-survives-the-layers-above-it.md)

## Context

Configuration arrives in layers — built-in defaults, `composer.json` discovery,
presets in the order given, the configuration file, the command line — and each
owner of a section combined them its own way. Measured over one hundred runs:

- `architecture.allow` merged by source layer name and replaced one layer's
  target list; `computed_metrics` let a layer replace a whole metric, so a file
  that wrote only `warning` lost the preset's `formula` (a refusal) or its
  `error` (a silent return to the default), and a sibling metric the file never
  named disappeared;
- exclusion sets accumulated, `paths` and `only_rules` were replaced, and `{}`
  meant "change nothing" under one key and "reset to defaults" under another;
- a bad value in a lower layer was refused by some owners and silently shadowed
  by others;
- seven key recognisers — five in `architecture`, two in `computed_metrics` —
  accepted a misspelt key whose value was `~`, because `null` was erased before
  they looked;
- a contribution reached its owner without its source or the key as the author
  spelled it, so a refusal could not name the layer to fix;
- the documentation described the merge wrongly in six places.

Each of these could be cured where it stood. The cures would have been seven
recognisers and some two hundred refusal sites, each re-deciding what `~`, `{}`
and a list mean, which is how the divergence arose.

## Decision

**1. A node of the document declares its schema.** Each node declares its key
dictionary, the form of its value, its merge policy and its shorthands. The
dictionary is literal or drawn from data: from a sibling node (`allow` is keyed
by the names `layers` declares) or from a grammar (`computed_metrics` names).
Configuration declares the roots it owns; a capability declares its own root
through a port Configuration offers (`DocumentSectionSchemaInterface`),
registered in the container — the port belongs to its consumer (ADR 0022), and
the engine never branches on an owner.

**2. One engine, in a fixed order of phases.** (1) Each layer alone: every key
with a literal dictionary is recognised, every written value's form judged —
below a node the engine carries unread (an `architecture` criterion or allow
target, written in more than one shape), by a judgement its owner declares on
the node — and shorthands expanded into their full keys — before any merge, so a lower layer's
mistake is refused even when a higher layer overrides it, and a misspelt key is
refused whatever its value, `~` included. (2) The layers merge by each node's
policy. (3) Names whose dictionary is another node are judged once that node is
merged, so a file may allow a layer its preset declares. (4) The resolved
document keeps, for every leaf, the layer that won it and, for every merged
node, the layers that contributed. Form is judged in every layer; meaning, on
the merged value.

**3. Five policies, and what they mean to an author.** A scalar goes to the last
layer that writes it. A map merges key by key, and a written empty map changes
nothing. A list is either replaced whole (`paths`, `only_rules`, `layers`, one
layer's `allow` targets, `levels`) or accumulated (`exclude`, `suppress_*`,
`exclude_health`, `disabled_rules`) — declared per node, never inferred from the
value. A map keyed by names merges entry by entry. A shorthand is expanded in
the layer that wrote it, so a file's `warning` over a preset's `threshold` keeps
the preset's `error`; writing the shorthand beside one of its keys in the same
layer is refused. `~` at any depth means "not written". `only_rules: []` over a
lower filter is legal and says so as a warning. The per-key list is not
recorded here: it is generated from the declarations (decision 6).

**4. A refusal names its source.** A form or key refused in one layer names that
layer; a refused final value names the layer that won it; a constraint between
keys names every contributor. The refusal carries the path in the author's
spelling, and the JSON refusal envelope publishes the sources as `source`.
Joint refusals sort their writers by the layer index assigned during this
document's composition, rather than by key collection order or source kind;
two presets still have distinct precedence. The default position is the last
writer's, including a positionless command-line source. An explicitly supplied
null position stays null. Formula authorship uses the same selected stored
level as evaluation, including project-to-namespace inheritance and direct
built-in defaults, so an unrelated description never becomes a formula author.

**5. Warnings travel with the document.** A diagnostic about accepted
configuration carries its sources; every command that reads the configuration
prints it on stderr, and the JSON report publishes it under
`configurationDiagnostics`.

**6. The author's table is generated.** The table of every node's policy, what
`~` and an empty value mean there — inside a list item too — and its shorthands
is generated from the
declarations into the configuration page, both languages, and a freshness check
in `check:artifacts` fails when a declaration changes and the page does not.

**7. One spelling rule for every key.** A key is accepted in the snake_case,
kebab-case or camelCase of its words; the same words in any other style are
refused with the accepted spelling, at the root and in every declared section
alike.

## Document layout and measured policy exceptions

The document contract publishes only the reading forms that named consumers
need: `ResolvedMapInterface`, `ResolvedListInterface`,
`ResolvedOpaqueInterface`, and `ResolvedBareNameInterface`. Their concrete
forms, and `ResolvedScalar` which has no external reader, belong to the
engine's `Configuration\Document\Resolved` subject. The contract no longer
publishes construction or mutation of a resolved value.

`ResolvedValueInterface::refusal(string): ConfigurationRefusal` is replaced by
`refuse(string): never`. A resolved value now throws the refusal through
`Provenance::refusalOf()` when the caller is stopping execution. A caller that
must carry or inspect the exception object creates it with that provenance
factory directly. This removes an exception factory from the broadly read value
model and makes a forgotten `throw` impossible at the call site.

The following narrowly scoped metric policies are retained because their
signals describe the shape of a declared language or adapter rather than an
actionable design fault. The numbers below are historical measurements of the
accepted design across 1,078 classes; they are not a claim about a later live
run.

- `ConfigurationSource` keeps an inline ClassRank warning threshold of `0.025`.
  This enum receives rank through its `ConfigurationOrigin` carrier and the
  refusal carrier; removing the latter edge transfers the signal to
  `RefusedPosition` instead of removing it.
- `ComputedMetricFormulaValidator` keeps an inline Instability warning threshold
  of `0.81`. Its `Ca=2`, `Ce=8` shape reaches the generic `0.80` boundary; the
  measured alternative moves the signal to the section (`0.83`) rather than
  reducing instability.
- `ComputedMetricAuthorship` keeps an inline Instability warning threshold of
  `0.82`. Its `Ca=2`, `Ce=9` shape is the necessary document-to-refusal adapter
  for formula authorship.
- `Contract\Document\Schema` has an exact Distance exclusion. Moving its two
  helper types into the engine still measures `0.689`, above the warning
  threshold of `0.5`, and reverses the dependency from the declaration contract
  to the engine. The declaration language has different consumers from the
  document reading model, so the exclusion records that intentional boundary.

The existing inline ClassRank threshold of `0.03` on
`ConfigurationRefusal` remains unchanged. The historical measurement placed
`Contract\Document` at Distance `0.486` against its `0.5` warning threshold and
`RefusedPosition` at ClassRank `0.00545` against `0.0061`; neither has room for
unrelated concrete types or new exception-factory edges.

## Alternatives rejected

- **Curing each recogniser and refusal site where it stood** — it leaves the
  meaning of `~`, `{}` and a list to every owner again.
- **Judging only the winning layer's form** — a preset's broken value would
  surface the day the file stops overriding it.
- **`{}` as a reset** — one symbol with two meanings was the defect; a reset
  hidden in an empty map is what made `{}` unsafe to write.
- **Replacing a computed metric whole** — it lost the `formula` and `error` the
  file never mentioned. **Groups of alternatives**, where a higher member
  removes the lower ones, were rejected for the same loss one level down: a
  file's `warning` would silently drop a preset's `error`, and `formula` with
  `formulas.<level>` are a default and its refinement, not alternatives.
- **`relations: ~` as a refusal**, the one explicit null the architecture
  section refused: `~` is "not written", and an allow target without
  `relations` is documented as "any relation". The run leaves no trace of it —
  an unwritten key taking its documented default is not a silent loss. Merging
  allow targets element by element by `target`, and a warning on every target
  without `relations`, were rejected as noise on a lawful configuration.
- **`~` as deletion**, and a separate tombstone spelling — `~` would stop
  meaning "the layer below decides", and no measured case needed deletion.
- **Keeping concrete resolved forms in `Contract`** — it would continue to
  publish engine construction and mutation to consumers that only read values.
- **A mirror interface over `NodeSchema` or a compatibility shim for old
  resolved-form classes** — neither changes the declaration language's
  responsibility, while a compatibility shim would preserve the construction
  API the layout removes.
- **Moving Schema helpers into the engine or merging Schema into Document** —
  the measured helper move remains above the warning threshold and reverses a
  dependency; merging joins two surfaces whose consumers are different only to
  remove a metric row.
- **Moving formula validation into authorship** — it raises the validator to
  `0.857` outside the measured population boundary and makes the section `0.833`;
  it also assigns an absent-level refusal to the wrong subject.

## What becomes unexpressible

- removing a key a lower layer wrote: a preset's `allow` entry for a layer, or
  its computed metric (a metric is switched off with `enabled: false`; an allow
  list can only be replaced, which is not removal);
- replacing a preset's whole set of computed metrics at once;
- returning to a default without writing it, since `{}` no longer resets.

Each is revisited when a user asks for it; the likely answer is a spelling of
its own, not a second meaning for `~` or `{}`.

## Extension points

- **Importing a configuration file** is a layer like any other: an
  `AuthoredLayer` whose `ConfigurationOrigin` is `importedThrough()` the
  importing source, so a refusal names both files. No new source kind and no
  new merge rule are needed.
- **A new format** is a loader that hands the engine an `AuthoredNode` tree with
  the keys as written, and lines where the format has them. The engine never
  learns the format. An empty collection is read by the node's declaration, so
  a format that cannot tell `{}` from `[]` — YAML parsed into PHP cannot — loses
  nothing.

## Transitional state

At acceptance two roots, `rules` and `coupling`, are not yet declared by their
owners: the engine checks their spelling and carries each layer's value to the
owner, which merges it — a sixth, transitional policy. `rules` keeps the
layering of ADR 0058 until it is declared.

The per-layer contributions the owners folded before the engine also remain.
Several owners — cache, parallel workers, `format`, `fail_on`, `memory_limit`,
the finding suppressions, coupling, Run's discovery lists and the rule selection
— still read their values from them, after the engine has judged the keys and
forms of every declared root. Their refusals name the merged configuration
(`source` kind `resolved`) rather than a layer. The contributions are removed
owner by owner as each reads the resolved document, the rule selection last,
together with the `rules` subtree; the stand-in that carries an undeclared root
goes with the last undeclared root.

## Consequences

- Breaking for authors, each in the changelog: a computed metric merges per
  key; `{}` and `name: ~` no longer reset; a misspelt key written with `~`, a
  key in an unaccepted spelling and a non-string list item are refused; the
  JSON refusal envelope gains `source`, and the JSON report gains
  `configurationDiagnostics`.
- A new root or key is documented by declaring it: the page cannot drift from
  the declaration without failing `check:artifacts`.
- An owner that needs a merge the five policies cannot express has to add a
  policy to the engine, visibly, rather than fold its layers privately.
