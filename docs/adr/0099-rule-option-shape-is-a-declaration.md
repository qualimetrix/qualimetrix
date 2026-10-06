# 0099. Rule Option Shape Is a Declaration

**Date:** 2026-10-06
**Status:** Accepted

## Context

Capabilities declare accepted option values through `RuleOptionShape`. The
published shape also delegated matching, wording and document-schema projection
to private Finding classes. Compound forms recursively referred back to the
shape, so the authoring contract imported its own implementation and formed a
dependency cycle. Moving those private classes into `Contract` would preserve
the cycle and publish machinery without an external consumer.

CLI options, configuration documents and inline thresholds need the same
document forms. Their value judgement belongs to the existing configuration
schema; each reader should receive that interpretation explicitly.

## Decision

`RuleOptionShape` contains immutable recursive declaration data. Its closed
kind vocabulary describes plain values, words, lists, maps and unions. The
existing authoring factories, nullable and minimum modifiers, per-layer
judgement and public word-set declaration remain available. Factories enforce
the active form and valid numeric constraints.

Private Finding interpreters own matching, wording and conversion to
`NodeSchema`. The public shape imports none of those interpreters.
`RuleOptionSurface` owns addresses, accepted keys and level declarations; its
`shapeAt()` operation returns the declared shape, including framework keys.

`RuleOptionDocumentFormsInterface` publishes `schema(surface)` and
`schemaAt(surface, address)` to the named CLI and inline consumers. Its private
Finding implementation derives both forms from the same declaration facts.
Finding registers that implementation and its interface alias. Main-process
inline compilation records a container factory that receives that service. The
parallel task factory carries the service through PHP serialization to worker
bootstrap under the public interface. Processor cache identity includes its
serialized class and state; workers import no private Finding implementation.

No input lexer or alternate YAML/JSON grammar is introduced. The existing
`NodeSchema` interpretation continues to judge authored values.

## Migration

PHP consumers must replace `RuleOptionSurface::schema()` and `schemaAt()` with
the corresponding injected `RuleOptionDocumentFormsInterface` operations.
`RuleOptionShape::matches()`, `asNodeSchema()`, `describe()` and
`describeWritten()` are removed; external document consumers request forms
through that interface. Finding's own implementation and repository tooling
use the private interpreters where they need direct matching or wording.

Code constructing CLI input adapters, validators or parsers, or building an
inline validator map, must provide the document-forms contract. Direct users of
`FileProcessingTaskFactory`, `FileProcessingTask` and
`WorkerBootstrap::getFileProcessor()` must supply that required service too. Option-authoring
factories and declared keys do not change. User-facing accepted values and
refusal wording retain their existing semantics.

## Consequences

The dependency direction becomes declaration to neutral value vocabulary and
private interpretation to declaration. CLI and inline adapters share one named
document-form contract. The recursive document schema remains a recursive
subject; this decision removes the cycle between a published option declaration
and private compound interpretation.
