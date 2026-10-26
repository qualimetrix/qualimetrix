# 0133. References in Attribute Arguments Are Their Own Edge Kind

**Date:** 2026-10-26
**Status:** Accepted

## Context

`#[ORM\Entity(repositoryClass: UserRepository::class)]` records a
`UserRepository` edge of kind `class_const_fetch`, and `new Foo()` in an
attribute argument records `new`. Both are indistinguishable from the same
expressions in code. A mapping reference then closes `architecture.circular-dependency`
cycles with the repository that uses the entity, and a layer policy cannot allow
mapping references without allowing runtime ones. ADR 0071 rejected re-typing an
edge because `relations:` policies would narrow silently; this record answers
that objection for this case.

## Decision

**Edge kind.** Everything recorded while the traversal is inside an attribute's
arguments — `Foo::class`, `Foo::BAR`, enum cases, `new Foo()`, and the
signature and body of a closure or arrow function there (PHP 8.5) — has the
kind `attribute_argument` (description "uses in attribute argument"). The
attribute's own name keeps `attribute`, an anonymous class's `extends`,
`implements` and `trait_use` keep theirs, and type edges inside arguments carry
no `shape`. `DependencyVisitor` keeps a depth counter of attributes entered,
and `DependencyRecorder` reads it when it records, so no handler knows the kind.

**Vocabulary.** `attribute_argument` is a standalone `relations:` token and
joins no alias. The aliases and token parsing moved to
`DependencyRelationTokens`, a DependencyModel contract, because DependencyModel
owns the kinds and both consumers (layer policy and cycle filter) already import
it. `AllowAliasExpander` keeps the `relations` refusal text and delegates.
Rejected: making the Policy class the contract (an Evidence-to-Policy edge
between independent leaves) and placing it in Core (Core cannot import
`DependencyType`).

**Cycle filter.** `architecture.circular-dependency` gains `ignore-relations`
(YAML `ignore_relations`): kinds or aliases, the `relations:` vocabulary, whose
records do not form cycles. The default `[]` is no filter. The filter is
exclusion, not inclusion, because listing thirteen kinds to drop one is
unusable. A class pair stays a cycle edge while **any** of its records is
admitted, so an entity that uses the repository in an attribute and in code
keeps its cycle. The filter decides which cycles exist and not the identity of
those that remain: the baseline key still depends on members only. The shown
path reads admitted records only; `max-cycle-size` and `direct-as-error` judge
the filtered cycles, so a three-class component can become a direct cycle and
be reported as an error. The cycle preparation implementation reads its rule's
resolved options itself, so the Run preparation contract
(`prepare(DependencyGraphInterface)`) does not change.

## Answer to ADR 0071

ADR 0071 rejected re-typing the anonymous-class edge to `new` for four reasons;
none holds here.

- No `extends` edge is retyped, and the builtin-class filter treats every
  non-`extends` kind alike, so no edge is lost.
- "Uses in attribute argument" is true of every retyped edge, unlike
  "instantiates" for an `implements` target.
- None of the retyped kinds was strong coupling, and the new one is not.
- Decisively, narrowing here is **loud**. `attribute_argument` is in no alias,
  so a `relations:` list that admitted such an edge now rejects it with a
  published `architecture.layer-violation`. Measured: `relations: [static_access, attribute]`
  yields `Layer "domain" must not depend on layer "infra" (App\Domain\E → App\Infra\Repo, uses in attribute argument)`,
  and adding `attribute_argument` clears it. No previously reported finding can
  vanish; retyped baseline entries change `edge.type` and surface as one stale
  and one new entry.

## Consequences

- Breaking for `relations:` policies and baselines as above; a migration note is
  in the changelog, the Architecture rule page and the Baseline page.
- Coupling values (CBO, Ce/Ca, instability, ClassRank, namespace coupling) do not
  move: they count distinct targets, not edges. The new kind still counts toward
  coupling; excluding it is a separate product decision.
- Graph JSON `types` can list both `attribute_argument` and an ordinary kind on
  one edge when a class is named in both places.

## Rejected alternatives

- **An additional edge beside the old one.** It double counts in the occurrence
  count and in the CBO finding's top-dependencies text.
- **Re-typing only `class_const_fetch`.** `new Foo()` and enum cases in
  arguments would still read as code, which is inconsistent.
