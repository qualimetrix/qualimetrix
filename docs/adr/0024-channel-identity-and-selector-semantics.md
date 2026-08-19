# 0024. Channel Identity and Selector Semantics

**Date:** 2026-08-19
**Status:** Accepted
**Related:** [ADR 0013](0013-threshold-override-validators.md) (threshold override validators), [ADR 0017](0017-baseline-ceiling.md) (baseline ceiling and channel declarations), [ADR 0006](0006-architecture-rules-declaration-order.md) (the layer-policy diagnostics reclassified here)

## Context

A finding is identified by a pair of dotted strings, `(ruleName, violationCode)`.
Four subsystems independently answered the question "does this user-written
string select this finding" — inline suppression, threshold overrides, rule
selection (`only_rules` / `--disable-rule`), and path/namespace exclusions —
and every one of them called the same matcher with the semantics
`exact ∨ startsWith(pattern . '.')`. They did not, however, read the same half
of the pair: suppression matched the `violationCode`, a threshold override
matched the `ruleName`, selection matched either, and exclusions consulted the
rule's *category*.

Four defects followed from that arrangement.

**A channel named as a dotted descendant of an existing one is silently
swallowed by every selector aimed at the ancestor.** `architecture.coverage`
matched `architecture.coverage.source`. No such pair existed among the declared
channels, so the defect was latent — but the natural name for a source-variant
of `x.y` is `x.y.source`, and that name was unsafe to introduce.

**Two directives accepted different vocabularies, and the diagnostic pointed
away from the cause.** Reports print `coupling.cbo.class`; pasted into
`@qmx-threshold`, it matched no rule (the rule is `coupling.cbo`). The tool
answered "rule does not support `@qmx-threshold` overrides", which blamed the
wrong thing: the string named no rule at all, and `coupling.cbo` does support
overrides. The message was also a `Warning`, so under the default
`fail_on: error` it did not gate.

**Whether a rule supports a threshold override was not observable from
outside.** It was inferred from whether the options class implemented an
interface and whether the rule called `getEffectiveOptions()`. There was no
reverse map from a channel code back to an addressable unit, so no "did you
mean" hint could be built.

**"Can this finding be accepted into a baseline" was encoded as the presence of
a declaration object.** Two consequences. Directive diagnostics were emitted
directly from the pipeline, owned by no rule, with nowhere to declare a shape or
a configurable severity. And, in the other direction, the four layer-policy
diagnostics — `architecture.coverage`, `architecture.unreachable-layer`,
`architecture.potential-shadow`, `architecture.empty-template` — did have
declarations and could therefore be ratcheted into a baseline as ordinary code
debt, which [ADR 0017](0017-baseline-ceiling.md) explicitly forbids.

Three constraints from the existing code bounded any answer. A channel's code
must equal its rule name or start with `ruleName.` — a tested invariant, not a
convention. 38 of the then-51 declared channels had `violationCode === ruleName`,
so a name cannot be resolved to a single level. And the identity string is an
external contract in five places: `ruleId` in SARIF, `check_name` in GitLab,
`source` in Checkstyle, and the `code` and `channel` fields in JSON.

## Decision

### 1. Matching is equality; grouping is an explicit star

`X` matches exactly `X`. `X.*` matches **strictly the descendants** of `X` —
`X` itself is not included, and if both are wanted, two directives are written.
A bare prefix is an **error**, not a guess at intent. An `X.*` with no
descendants is an error too, with a hint naming the exact form.

The star is part of the *selector syntax*, not a mode of the matcher. The
alternative — making the shared matcher glob-capable — was rejected because it
would have changed the meaning of every selector at once with no migration
signal, which is precisely the failure mode being removed.

Why equality rather than a safer prefix rule: any prefix rule has to decide
what a dot means, and every such decision reintroduces the ancestor/descendant
ambiguity somewhere. Equality has no edge cases, and the group case is real but
rare enough to deserve two characters of explicit syntax. The cost is named
rather than hidden: "every channel of the layer-policy rule" is now
inexpressible, because those four channels carry rule names of their own,
`architecture.layer-violation.*` has no descendants, and `architecture.*` would
capture the unrelated `architecture.circular-dependency`.

Two alternatives that look attractive were rejected on evidence. **Resolving a
name to exactly one level** is unimplementable: with 38 channels whose code
equals their rule name, it would require an undeclared level priority.
**Duplicating the code as `rule.violation`** (`coupling.cbo.cbo`) would give
every name a unique level, but it changes the published `violationCode` of 38
channels and so breaks the external contract in all five formats, for no gain.

### 2. The level comes from the directive, not from the number of segments

`@qmx-ignore X` always addresses a **channel**. `@qmx-threshold X` always
addresses a **rule**, and accepts no wildcard at all. Selection
(`only_rules`, `disabled_rules`, and the CLI equivalents) addresses either, with
`ruleName#violationCode` available when both halves must be pinned. Keys of the
`rules:` section and the owner in `--rule-opt RULE:option=value` address a rule,
exactly.

The asymmetry is not arbitrary: a threshold physically belongs to the rule,
which has one options object, while a suppression belongs to the channel. This
is what removes the ambiguity created by 38 names that denote both a rule and a
channel, and it does so without renaming a single channel.

`@qmx-threshold` refuses the wildcard because resetting thresholds across a
group is the original footgun. Its single-`*` form is removed for the same
reason.

### 3. The single `*` survives where it was never a selector

The `*` token carried three meanings through one special case. Two of them —
`@qmx-ignore *` on a symbol or line, and a bare `@qmx-ignore-file` — are not
selectors over the rule namespace at all; they mean "no rule filter here". That
meaning is now modelled explicitly for all three suppression forms and survives.
Only the third meaning, `@qmx-threshold * <number>`, is removed.

Without that separation, every file carrying a bare `@qmx-ignore-file` would
have lost its suppression *and* failed the build on the unresolvable-selector
rule.

### 4. Category loses all behaviour and keeps only display

`RuleCategory` was doing two jobs: grouping for display, and deciding whether
`exclude_paths` / `exclude_namespaces` may silence a finding. The second job was
never grouping — it is a property of the finding: a project-level finding is not
attached to a file, so a path exclusion is inapplicable to it. Reading that
property off the spelling of the rule name meant a future rule called
`architecture.anything` would inherit immunity it never asked for.

The property is now declared per channel. The category keeps only
`qmx rules --group` and participates in no matching whatsoever.

The category is *not* deleted. `getCategory()` is declared by 33 rules, sits in
`RuleInterface` and `RuleMetadata`, and is referenced by 46 test files; removal
would touch roughly 85 files and would still have required the separate declared
property. Stripping the behaviour fixes the defect at a cost of about six files.
A pleasant consequence: the correlation between a category and the first segment
of a rule name becomes harmless, so `computed.health → Maintainability` stops
being an anomaly to explain.

### 5. Properties are declared, not inferred

Three facts move from inference to declaration: whether a rule supports a
threshold override, whether a channel's findings are file-scoped (and therefore
subject to path and namespace exclusions), and whether a channel's findings are
acceptable as debt.

The reverse map from a channel code to its producing rule now exists, so
"did you mean" is answered by querying the registry rather than by stripping a
suffix — which matters, because two rules have codes not derived from their
names at all.

### 6. Acceptability: configuration errors are not debt, and do not consult `fail_on`

A channel declares itself either `AcceptableAsDebt` or `ConfigurationError`.
A `ConfigurationError` can be accepted by no baseline on any path, cannot be
suppressed by an inline directive, and — the load-bearing part — **does not take
part in the `fail_on` comparison at all**. It ends the run with a non-zero code
unconditionally, including under `fail_on: none`.

An earlier draft set a severity floor of `Warning` instead. That does not work:
the default `fail_on` is `error`, so a `Warning` never reaches the gate, and the
result would have been a diagnostic that can be neither ratcheted nor made to
fail — exactly the signal people learn to ignore. A configuration error is not a
judgement about code quality that a user is entitled to threshold away; it is
the tool saying it cannot do what was asked.

Seven channels carry it. Four are the layer-policy diagnostics.
`architecture.coverage` is a genuine hybrid — it means both "the layer
declaration has a hole" and "a new unclassified module appeared" — and is
classified as a configuration error because only the author of the configuration
can tell those apart, both are fixed by editing `layers:`, and ratcheting one
would freeze a divergence between configuration and code as normal. The fifth
channel of the same rule, `architecture.layer-violation`, is real code debt and
is deliberately untouched. The remaining three are the inline-directive errors
introduced below.

Because severity no longer governs behaviour for these channels, the three rule
options `unreachable_layer_severity`, `potential_shadow_severity` and
`empty_template_severity` are **removed** rather than silently clamped. A key
that looks like a behaviour switch and changes nothing but a word in the report
is the same lie as a directive that does nothing. `architecture.coverage` never
had such a key; it is still governed by `coverage: ignore|warn|error`, and
`ignore` remains the legitimate way to decline the diagnostic.

Narrowing the claim precisely: a configuration error cannot be accepted by the
*ratchet*. It can still be accepted by an explicit declaration of intent in the
configuration — today that means `coverage: ignore`, and nothing else.

### 7. Loud failure, in three states

| State                                                                 | Answer                                                                                                              |
| --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| the name does not address a unit this directive is allowed to address | **Error**: a configuration error. Not baselined, not suppressible, gates. The message lists what *can* be addressed |
| the name is valid but the directive did not fire this run             | a separate channel below `Error`, gated by choice. This is what ordinary debt cleanup looks like                    |
| the name is valid and it fired                                        | silence                                                                                                             |

The first state is framed by **addressability, not existence**: "the rule
exists but does not support an override" belongs here even though the name
resolves, because a directive that can never fire is as much a lie as a typo.
The second must not be folded into the first, or every project that fixed a
violation and left the annotation behind would start failing.

The failure *mechanism* differs by surface, and that is deliberate.
Configuration and CLI selectors are validated before analysis, so their failure
is an exception that ends the command with exit 3; "baselined" and "suppressed"
do not apply to it. Inline directives are discovered during analysis, so their
failure is a finding on a channel, to which acceptability and suppression do
apply. What the two surfaces share is that no silence remains.

This is owned by a new rule in the inline-directive policy — the same shape as
the layer-policy rule: one rule emitting several channels that carry rule names
of their own. Three channels are configuration errors
(`annotation.unresolved-directive`, `annotation.unsupported-threshold`,
`annotation.invalid-threshold`); one, `annotation.unused-directive`, is ordinary
debt with a configurable `unused_directive_severity` defaulting to `info`.
The open suffix family `annotation.invalid-threshold.<code>` is retired: the
error code is finding data, not part of an identity.

### 8. Why an open vocabulary still does not get an Error

Computed metrics contribute names of arbitrary depth, resolved from the run's
configuration rather than declared statically. It would be tempting to treat
"unknown name" as a hard error only for the static half and to stay quiet for
the open half. That is not what happens, and the reason is that the two
questions are about different subjects.

A stored artefact — a baseline entry whose channel has disappeared — must not
explode: blowing up an old baseline is unacceptable, so the entry goes inert.
An *authored directive* whose name resolves to nothing means the directive is
lying about what it does. Whether the cause is a typo or a metric removed from
`computed_metrics:` makes no difference; both are dangling references the author
must fix. Same absence, different lifecycles, different correct answers — and no
third state is needed to express that.

### 9. Validation happens after configuration resolves

There is one universe of names, assembled after configuration resolution. Static
rule declarations and configured computed-metric definitions are two contributors
to it, not two namespaces: a name's origin is stored nowhere and affects nothing.
This is what makes `@qmx-ignore health.cohesion` valid — that channel exists only
because the user defined the metric, and a check written against the static
declarations alone would call every such annotation a mistake.

**Enabledness is not part of identity.** The universe holds every declared
channel of the configuration regardless of whether its rule is switched on;
disabling is an execution filter, not a fact about whether a name exists.
Therefore `@qmx-threshold` on a disabled rule is valid and silent, and the old
diagnostic for that case disappears. The price is accepted deliberately: the
alternative would make an annotation invalid precisely because a rule is off,
and needed again the moment it is switched back on.

## Consequences

**Breaking.** Nine changes of public semantics, each with a `Breaking` entry and
a consumer-facing migration note in `CHANGELOG.md`. In summary: selectors stop
swallowing dotted descendants; `@qmx-threshold` refuses prefixes and `*`;
`@qmx-ignore` requires full qualification; group selectors require the star;
`rules:` keys and `--rule-opt` owners must be exact rule names; an unresolvable
selector is an error rather than an ignored warning; `@qmx-threshold` on a
disabled rule stops being diagnosed; removing a computed metric invalidates the
annotations that referenced it; and the three layer-diagnostic severity keys are
removed.

**A latent defect is fixed and named as latent.** No channel pair in the current
vocabulary was affected by the ancestor-swallowing bug. What is fixed is the
naming scheme's safety, not a live incident — the work is justified by the other
three defects, two of which were reproducible from a user's console.

**One suppression option is genuinely withdrawn.** The four layer-policy
diagnostics can no longer be silenced with `@qmx-ignore` or parked in a baseline.
What remains is the `exclude:` block inside the architecture configuration and,
for coverage, `coverage: ignore`. Two of them — `unreachable-layer` and
`potential-shadow` — have no failure mode of their own, and the legitimate
"this layer is intentionally empty" case wants an explicit way to say so; that
is a separate proposal, and this release does not ship pretending it exists.

**A silent no-op becomes an error.** `rules: { complexity: {...} }` passed both
validations and configured nothing, because options are applied by exact key.
Any project that has been carrying such a key has been carrying dead
configuration, possibly for a long time, and will now be told.

**Positional ratchet identity is untouched and not claimed fixed.** Baseline
entry keys still carry a declaration's byte offset, so an edit higher in a file
rewrites the key. That is a different identity vocabulary and a separate problem.
