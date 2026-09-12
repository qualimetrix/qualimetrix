# What the website says today about shorthand-beside-nested-block

Obtained by: `grep` across `website/docs/` for "threshold shorthand" / "bare
threshold" / "discard" / "wins over" / "silently", then reading each hit in
context. Does not cover every page — only the three pages the hits landed on
(`getting-started/configuration.md`, `rules/complexity.md`, `rules/coupling.md`).
A page that says nothing was not read exhaustively looking for an implicit
promise; it was checked by grep for the vocabulary above plus "enabled".

## `website/docs/getting-started/configuration.md:185-210` — documents the M2/M3 mechanism explicitly and accurately

> **A bare `threshold` at a hierarchical rule's own top level replaces the
> level blocks, it does not add to them.** Writing one selects a shorthand
> form, and whatever `callable:` / `class:` / `namespace:` blocks stand
> beside it are not read — silently, with no word about them. Which levels
> the shorthand then covers differs between the two families:

followed by the exact `complexity.ccn: {threshold: 5, class: {max_warning: 2}}`
example (never read) for the Complexity family, and the CBO/Instability
uniform-both-levels behaviour for the Coupling family. This is a **direct,
correct, current description of M2 and M3** — the product's behaviour is not a
documentation gap for these two mechanisms. It even names the failure mode with
the same word the mechanism table uses ("silently").

## `website/docs/rules/coupling.md:154-159, 425-430` — per-rule restatement plus an explicit callout box

```
!!! warning "Flat threshold wins over a nested class:/namespace: config"
    If a `threshold` at the rule's top level and a nested `class:`/`namespace:`
    section both end up configured for `coupling.cbo` at once, the flat
    `threshold` takes full precedence -- the nested section is ignored, not
    merged with it. Configure one form or the other for a given rule, not
    both.
```
(same box repeated for `coupling.instability` at line ~440). Both are accurate
and match the measured runtime behaviour in `user-documents.md` (doc6, doc8).

## `website/docs/rules/complexity.md` — silent on the *rule's own top-level* `threshold` shorthand
The Configuration section for `complexity.ccn` (and `.cognitive`, `.npath`)
only ever shows `threshold:` **nested inside `callable:`** (e.g. line 126:
`callable: { threshold: 15 }`). It never shows or warns about the *bare
top-level* `threshold:` that `ComplexityOptions::fromArray()` also accepts and
that discards `class:` (the thing `getting-started/configuration.md` documents
generically). So the per-rule page for this family is silent about its own
rule's shorthand-discards-block behaviour; a reader who only opens
`complexity.md` (not `getting-started/configuration.md`) would not learn this.
This is a **documentation coverage gap for M2 specifically on the per-rule
page**, even though the cross-cutting configuration guide covers it.

## `enabled: false` discarding a sibling (M1) — not documented anywhere found
Grepped `website/docs/rules/complexity.md`, `rules/coupling.md`, and
`getting-started/configuration.md` for `enabled.*false` together with
"disable"/"silent"/"discard". The only `enabled: false` examples shown are the
bare, no-sibling case ("Disable a rule entirely: `code-smell.boolean-argument:
{enabled: false}`", `configuration.md:130-135`) and the level-slot boolean
rejection note (`callable: {enabled: false}` vs `callable: false`,
`configuration.md:239`) — neither says what happens to a `class:`/`callable:`/
`namespace:` block or a `threshold`/`warning`/`error` value written *beside*
`enabled: false` on the same rule. **No page found documents that these are
discarded.** This is a genuine, undocumented silent gap, unlike M2/M3.

## Chosen method and its blind spot
Grep-then-read over three files that a targeted vocabulary search surfaced.
Does not prove the negative for the *entire* website (a page using different
words — "overrides", "takes precedence", "loses" — for the M1 behaviour would
not have been found by this grep). Confidence for the M2/M3 findings is high
(the exact scenario is quoted verbatim in the docs). Confidence for the M1
"undocumented" claim is medium — it is an absence-of-evidence claim from a
finite grep vocabulary, not an exhaustive read of every doc page.
