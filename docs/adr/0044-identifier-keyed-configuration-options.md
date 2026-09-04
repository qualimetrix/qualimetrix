# 0044. Identifier-Keyed Configuration Options Keep the Spelling the User Wrote

**Date:** 2026-09-04
**Status:** Accepted

## Context

ADR 0009 made normalization a declared property of each section: a
`SectionNormalizationPolicy` says how far down a section's keys are camelCased.
The `rules` section is `PRESERVE_IMMEDIATE_CHILDREN` — level 1 is a rule slug
and survives, everything below it is normalized.

The keys of `exclude_namespace_channels` are channel names, and they sit at
level 3. So `code-smell.boolean-argument` reached the validator as
`codeSmell.booleanArgument`, and the run ended with exit code 3 printing the
correct name in the sentence that refused the written one.

Measured scope: 39 of the 52 static channels contain a hyphen, but the option
only ever fires on findings reported at `namespace` level, and of those exactly
one static channel is hyphenated. The real population is the open vocabulary it
was written for: the name validator for `health.*` / `computed.*` **requires**
lower-case kebab, and computed metrics report at `namespace` and `project` by
default — so the option was unreachable for every computed metric a user can
define. The group form `X.*` and the `channel:namespace` pair form of ADR 0025
broke the same way.

## Decision

**Which keys are the user's own words is a property of the schema, not of the
place that reads them.**

`ConfigSchema` declares the identifier-keyed options; the loader reads that
list; the section policy answers what a sub-array under such a key is walked
with. The list is not read from inside the normalization enum: the schema
already names the enum, and the reverse edge is a dependency cycle the
project's own analysis refuses.

Alternatives considered and rejected:

- **Accept both spellings where the key is read.** `FindingExclusionLedger` and
  `RuleNamespaceExclusionProvider` already do this for the neighbouring option
  *names*, so the shape was available. Rejected because the reverse mapping is
  ambiguous: `codeSmell.booleanArgument` has more than one kebab pre-image, and
  a reader that guesses would accept a key naming a channel nobody has.
- **A third `SectionNormalizationPolicy` case for this one option.** Rejected as
  an exception keyed by an option's name inside a model whose unit is a section.
- **Leave it and document the restriction.** Rejected: the option is documented
  with a hyphenated example, and the refusal it produces names a configuration
  error rather than the spelling, so the author cannot tell what went wrong.

Nothing else about the option moves. A key must still name a channel its rule
produces — production, not applicability, per ADR 0025.

## Consequences

- Every channel name spelled as the product spells it is now a usable key,
  including the whole computed-metric vocabulary, the `X.*` group form and the
  `channel:namespace` pair form.
- A key naming a channel that never reports a namespace aggregate is now
  *accepted* and excludes nothing —
  `annotation.unused-directive` under `annotation.directive` is the measured
  example. This is the same silence as before wearing a different message, and
  changing it means changing the validator's contract from production to
  applicability: a separate decision, recorded as an open follow-up.
- The project's own configuration cannot witness the fix — its single excluded
  channel has no hyphen — so the evidence is a fixture run end to end. A tree
  that proves a change only through its own configuration proves it for the
  configuration it happens to have.
