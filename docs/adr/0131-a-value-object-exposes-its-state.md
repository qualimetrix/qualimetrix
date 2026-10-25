# 0131. A Value Object Exposes Its State

**Date:** 2026-10-25
**Status:** Accepted

## Context

`code-smell.long-parameter-list` judges value-object constructors with higher
thresholds (8/12 instead of 4/6). A constructor counted as a VO constructor
when its class is `readonly`, every parameter is promoted and the body is
empty. That shape is also the idiomatic modern service:
`final readonly class Handler { public function __construct(private Repo $a, private Client $b, ...) {} }`.
An adoption report showed readonly services coordinating Redis, ClickHouse and
ports taking the VO thresholds while the same class without `readonly` did not.

The reverse defect sat in `code-smell.constructor-overinjection`: it had no VO
branch, so a nine-field data record was told to split "responsibilities".
This repository carried paired `@qmx-threshold` ceilings on seventeen such
records whose reasons said the fields "are not injected services".

Measured over every class with that constructor shape — the installed
benchmark corpus, this repository's `src/`, and the sources of ten private
production applications (1682 VO constructors):

- In the applications 672 VO classes expose none of their promoted
  properties. Of those, five carry a data-like name and none of them has four
  or more parameters; a read of twenty random ones with four or more
  parameters found only handlers, clients, gateways, runners and
  transformers.
- Requiring public parameters fails: Doctrine DBAL's metadata rows are
  `private` with getters (up to eight fields).
- Counting any property read inside a returned expression fails the other way:
  it re-admits 57 application classes, and the sampled ones are services
  returning `new Result($this->dependency)`.
- Requiring every promoted property to be exposed rejects records with one
  internal field (`SymbolPath`, `AnalysisResult`, `Dependency`).

The libraries in the benchmark corpus hold few readonly classes (27, all but
one in Doctrine DBAL); the decision rests on the application sources.

## Decision

A VO constructor also requires that its class exposes at least one promoted
property: the parameter is publicly readable (read visibility `public` or
absent, so `readonly $x` and `private(set) $x` count), or a method of the
same class — not a closure or arrow function inside it, not a nested
class-like — contains exactly `return $this->name;`. The visitor decides when
the class closes. Also counting returns inside closures and arrow functions
changed one classification across all three corpora: an application
resolver, a service, became a VO.

`code-smell.constructor-overinjection` declares a `value-object-constructor`
population gate on `code-smell.is-vo-constructor`. A VO constructor is judged
by `code-smell.long-parameter-list` alone and appears in `abstentions`.

The metric key keeps its name: its subject is unchanged, its definition is
sharpened.

## Consequences

- A VO reachable only through derived getters, `toArray()` or getters of a
  used trait gets the standard thresholds: more findings, never fewer. A trait
  is a separate declaration the single per-file pass cannot attribute to its
  users. Measured at four or more parameters,
  that population is empty in all three corpora.
- A readonly service that also returns one dependency from a getter stays a
  VO. Measured at four or more parameters, the service-named population of
  exposed classes held two classes, both public-field command messages.
- Rejected: a behaviour heuristic (method calls on promoted properties) —
  value objects delegate to child values; an explicit classification
  directive — a new authored surface where `@qmx-threshold` already gives a
  finite local boundary.
