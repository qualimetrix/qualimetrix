# 0128. Subject Boundaries with One-Way Operations

**Date:** 2026-10-19
**Status:** Accepted

## Context

A directory split can preserve class dependencies while creating a namespace
cycle: a child reads the parent's facts while the parent invokes the child.
The resulting boundary divides one operation rather than independent subjects.
Stopping a split when a class-count warning disappears also leaves equivalent
algorithms or configuration adapters under different placement rules.

## Decision

Apply one placement rule to each named family. Cyclomatic, cognitive, NPath and
weighted-method policy have explicit algorithm subjects. Method measurement
keeps its method and property facts together; property-count policy is separate.
Instability keeps all its bands and judgement together. Architecture and Baseline
composition and their CLI actions use direct subject directories.

A child subject must not read the internal operation that consumes it. Keep
shared input facts and resources below both operations, or reunite an indivisible
operation. Specifically:

- File-target identity, failures, native calls and temporary siblings are shared
  resources. Resolution consumes private-group ownership; publication consumes
  resolution. A process-owner probe consumes a temporary resource rather than
  depending on report publication.
- Written nodes and positioned reading context belong to authored-document facts,
  consumed independently by document composition and name binding.
- Architecture's shape reader and document validators form one reading operation.
  Configuration retains the authored position while judging list shape; the
  selector language, relation-token semantics, policy values and self-glob
  judgement live in the shared `Architecture\Allow` leaf below Configuration
  and Layer and depend on neither operation. Criterion
  values belong to Matching; whole-layer matching reads declaration context
  beside the layer operation.
- Duplication source-to-token behavior lives in `Duplication\Normalization`.
  The immutable stream and retokenized-file batch live in `Duplication\Token`,
  a common fact subject below Normalization, detection, Index and Matching.
  Neither subject calls those consuming operations.
- Baseline scope owns portable normalization; stored entries and current coverage
  consume it. A group's capture judgement owns its refusal vocabulary.
- Worker-roster composition consumes collector and rule tags without introducing
  a reverse dependency between their compiler-pass subjects.
- Hook owns its binary-location port. Configuration input owns preflight and
  diagnostic publication. Generic raw terminal output and a completed report's
  publication have separate subjects.

PHP trait method composition is a neutral language subject, distinct from symbol
identity. Its operation lives under `Core\Symbol\TraitComposition`. Immutable facts,
method sources, result and typed failure are its exact `Contract` vocabulary;
the executable composer, recursive scope and candidate pool stay outside that
contract. Values do not import the algorithm.
Coupling and Cohesion consume the same entry point and source identities; no
capability metric state enters Core.

Directive-name advice orders candidates by edit distance, then bytewise name.
Registration order and filenames therefore cannot break ties in published advice.
Remove filename sorting from capability registration and use the existing fresh
registration prototypes. A unit regression with reversed candidate order and a
real-channel suggestion test replace the channel-order inventory: DI order no
longer carries the publication promise that inventory was written to protect.

Compiled rule producers execute and expose metadata in lexical order of their
declared `NAME`, retaining the same producer catalog. Programmatically registered
families without a compiled rule class keep their append order. Baseline
explanation orders channels by code and preserves occurrence order for equal
codes. Subject identities, source paths and DI insertion order do not define
this sorting policy.

This refines the placement and channel-order decisions of ADR 0125. It does not
assert that all existing namespace cycles are removed. In particular, the
established Layer/ClassContext and Baseline Contract/Entry cycles remain outside
this placement refinement.

## Migration

Existing code importing these manifest-declared contracts replaces each old
fully qualified name with the new one. Names below are relative to
`Qualimetrix\`; method signatures and returned values stay the same. No aliases
are provided.

| Old name                                        | New name                                                       |
| ----------------------------------------------- | -------------------------------------------------------------- |
| `Core\FileTarget\ResolvedTarget`                | `Core\FileTarget\Resolution\ResolvedTarget`                    |
| `Core\FileTarget\TargetPath`                    | `Core\FileTarget\Resolution\TargetPath`                        |
| `Analysis\Policy\Baseline\RunCoverage\RunScope` | `Analysis\Policy\Baseline\Contract\RunScope`                   |
| `Core\Symbol\TraitComposition`                  | `Core\Symbol\TraitComposition\TraitComposition`                |
| `Core\Symbol\TraitCompositionResult`            | `Core\Symbol\TraitComposition\Contract\TraitCompositionResult` |
| `Core\Symbol\TraitDefinitionFacts`              | `Core\Symbol\TraitComposition\Contract\TraitDefinitionFacts`   |
| `Core\Symbol\TraitMethodSource`                 | `Core\Symbol\TraitComposition\Contract\TraitMethodSource`      |
| `Core\Symbol\TraitUseFacts`                     | `Core\Symbol\TraitComposition\Contract\TraitUseFacts`          |

Additional one-way fact moves are:

| Old name                                                               | New name                                                            |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------- |
| `Analysis\Evidence\Duplication\DataDeclarationTagger`                  | `Analysis\Evidence\Duplication\Normalization\DataDeclarationTagger` |
| `Analysis\Evidence\Duplication\RetokenizedFiles`                       | `Analysis\Evidence\Duplication\Token\RetokenizedFiles`              |
| `Analysis\Evidence\Duplication\TokenNormalizer`                        | `Analysis\Evidence\Duplication\Normalization\TokenNormalizer`       |
| `Analysis\Evidence\Duplication\TokenStream`                            | `Analysis\Evidence\Duplication\Token\TokenStream`                   |
| `Analysis\Policy\Architecture\Configuration\AllowAliasExpander`        | `Analysis\Policy\Architecture\Allow\AllowAliasExpander`             |
| `Analysis\Policy\Architecture\Configuration\AllowListEntry`            | `Analysis\Policy\Architecture\Allow\AllowListEntry`                 |
| `Analysis\Policy\Architecture\Configuration\AllowTarget`               | `Analysis\Policy\Architecture\Allow\AllowTarget`                    |
| `Analysis\Policy\Architecture\Configuration\CaptureBinding`            | `Analysis\Policy\Architecture\Allow\CaptureBinding`                 |
| `Analysis\Policy\Architecture\Configuration\InvalidSelectorException`  | `Analysis\Policy\Architecture\Allow\InvalidSelectorException`       |
| `Analysis\Policy\Architecture\Configuration\LayerSelector`             | `Analysis\Policy\Architecture\Allow\LayerSelector`                  |
| `Analysis\Policy\Architecture\Configuration\LayerSelectorParser`       | `Analysis\Policy\Architecture\Allow\LayerSelectorParser`            |
| `Analysis\Policy\Architecture\Configuration\ParseCapturedState`        | `Analysis\Policy\Architecture\Allow\ParseCapturedState`             |
| `Analysis\Policy\Architecture\Configuration\SelectorKind`              | `Analysis\Policy\Architecture\Allow\SelectorKind`                   |
| `Analysis\Policy\Architecture\Configuration\SelectorSegment`           | `Analysis\Policy\Architecture\Allow\SelectorSegment`                |
| `Analysis\Policy\Architecture\Configuration\WildcardSelfAllowDetector` | `Analysis\Policy\Architecture\Allow\WildcardSelfAllowDetector`      |

Portable scope normalization also moves from
`Analysis\Policy\Baseline\Entry\Baseline::normalizeScope()` to
`Analysis\Policy\Baseline\Contract\RunScope::normalize()`. Call the latter
when normalizing a stored portable scope; its normalization rules are unchanged.

## Consequences

Internal implementation addresses change with their subject; exact manifest
ownership, composition bindings, tests and tooling consumers follow each move.
No compatibility aliases or new capability contracts are introduced. Named leaf
owners and outward CLI spellings, metric keys, rule and channel identities stay
the same. Equal-distance suggestion order intentionally changes to name order.

Class-count and namespace-CBO signals are measured after the complete move.
A namespace's smaller population alone is not evidence of lower coupling, and
reuniting a real operation may retain a bounded namespace warning. Existing
behavior tests remain the evidence for algorithms and delivery; this decision
adds no architecture inventory or permanent check of another check.

The common-fact moves remove the Duplication/Index/Matching cycle and the
Architecture Configuration/Layer cycle. Architecture Layer/ClassContext and
Baseline Contract/Entry remain the two known namespace-cycle residues; neither
is widened by these leaves.
