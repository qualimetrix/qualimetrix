# 0088. Atomic Section Declarations and a Shared Format Vocabulary

**Date:** 2026-09-29
**Status:** Accepted

## Context

[ADR 0086](0086-one-configuration-document-merged-by-declared-policy.md)
gives each owner a port for declaring its document root. The port exposed the
root key and its schema as two independent operations. Those two values form
one declaration: the engine cannot interpret a schema without its canonical
key, and independently asking for them gives a provider two opportunities to
disagree.

Reporting's format resolver also declared that root while resolving the final
runtime value. Its registry-backed dictionary must judge each authored layer
and the winning value, including documents composed without the Reporting
section. Separating these responsibilities must keep one dictionary and both
refusal boundaries.

The namespace cohesion evidence exposed the split responsibilities and
disconnected metadata operations. The academic metrics were correct; excluding
the quality channel would conceal the evidence instead of improving the
subject.

## Decision

**1. One operation returns one declaration.**
Configuration owns the immutable `SectionDeclaration`, with readonly
`key` and `schema` properties.
`DocumentSectionSchemaInterface::declaration(): SectionDeclaration` replaces
`key()` and `schema()`. The engine reads a provider's declaration once in its
fold. Root inventory and documentation generation read the same pair.
There are no compatibility methods.

**2. The format dictionary is a Reporting subject.**
`OutputFormatVocabulary` asks `FormatterRegistryInterface` for accepted names.
`OutputFormatSection` declares the format string scalar and its per-layer
judgement through that vocabulary. Its canonical root key lives on the
vocabulary; `ConfigSchema::FORMAT` remains the transitional ingress address,
not a runtime dependency of Reporting. `OutputFormatResolver` resolves the
winning runtime `OutputFormat` through the same vocabulary and keeps the
unwritten default. Shape, membership refusal wording and provenance remain
the same. Infrastructure registers the section, vocabulary and resolver
separately.

**3. Project scope is one measurement.**
`ProjectScopeCoverage::measure()` returns the state, uncovered targets and
pruned targets together. Callers read `state()->coversProjectScope()` and
`uncoveredRoots` from that existing result rather than ask convenience
operations for separate answers. `reachableTargets()` and its partitioning
are pure static operations. The denominator, autoload-dev policy and
covered/narrowed/unknown semantics remain unchanged.

## Rejected alternatives

- Namespace quality exclusions, changed thresholds or changed academic
  algorithms: all would hide the measured design problem.
- Moving the declarations to another namespace or adding artificial fields:
  neither changes the subject's responsibilities.
- Splitting the format section while retaining separate key/schema methods:
  the declaration still has disconnected operations.
- Removing winner dictionary judgement or copying it into the section: one
  loses a refusal boundary; the other creates two dictionaries.

## Consequences

Programmatic section providers implement `declaration()` and return
`new SectionDeclaration($canonicalKey, $nodeSchema)`. A removed
`ConfigurationRoot::Format` case is replaced by a registry-backed
`OutputFormatSection` using `OutputFormatVocabulary`. The compiled container
supplies it automatically. Scope callers replace
`pathsCoverProjectScope()` and `uncoveredAutoloadRoots()` with reads from one
`measure()` result.

The two exact Run and Reporting Configuration namespace health exclusions are
removed. The unchanged namespace cohesion calculation now gives 85.4 for
both subjects: no class contributes multi-operation instance TCC, and the
mean LCOM is 1 (four Run classes, three Reporting classes). Baseline values,
thresholds and metric implementations are unchanged.
Existing owner-level tests protect both format dictionary boundaries and
scope semantics. No new repository control or gate form is introduced.
