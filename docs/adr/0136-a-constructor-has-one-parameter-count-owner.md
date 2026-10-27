# 0136. A Constructor Has One Parameter-Count Owner

**Date:** 2026-10-26
**Status:** Accepted

Supplements [ADR 0131](0131-a-value-object-exposes-its-state.md), which gave a
value-object constructor to `code-smell.long-parameter-list` alone and left every
other constructor judged by both rules.

## Context

Issue #170: `code-smell.long-parameter-list` and
`code-smell.constructor-overinjection` both read `code-smell.parameter-count`
and both judge constructors. A service constructor with eight injected
parameters was reported twice, as an error by `long-parameter-list` (standard
thresholds 4/6) and as a warning by `constructor-overinjection` (8/12), with two
technical-debt estimates added up for one change. Seven or fewer parameters
were still reported by `long-parameter-list` at the standard 4 although the
injection rule had judged the same constructor acceptable. ADR 0131 removed the
overlap for value-object constructors only.

## Decision

A constructor is judged by exactly one of the two rules:

- a value-object constructor belongs to `long-parameter-list` (VO thresholds
  8/12);
- any other constructor belongs to `constructor-overinjection` (8/12);
- methods and functions stay with `long-parameter-list` (4/6).

1. **A shared evidence flag, not a neighbour's runtime.** The parameter-count
   collector publishes `code-smell.is-injection-constructor` (value 1, absent
   otherwise) on exactly the constructors `constructor-overinjection` admits
   through its `constructor-name`, `class-context` and
   `value-object-constructor` gates. `long-parameter-list` abstains through a
   new `injection-constructor` population gate (reason: owned by
   `code-smell.constructor-overinjection`). A rule reading whether another rule
   is enabled, or its verdict, would couple rule execution order and
   configuration; reading published evidence does not. This follows the flag
   that ADR 0131 introduced for the value-object direction.
2. **The gate predicate set stays closed.** The new gate uses the existing
   flag-excludes predicate. A conjunction predicate ("constructor and not a
   value object" expressed in the rule) was rejected: the visitor already holds
   both facts, and a new predicate form would need its own registration,
   documentation and tests for one consumer.
3. **One definition, two evaluation points, pinned by a test.** The population
   is defined once in prose and decided twice: by the collector flag and by the
   overinjection gates. `ConstructorOwnershipEquivalenceTest` runs both rules
   over every constructor kind and fails when "admitted by overinjection" and
   "abstained by long-parameter-list" diverge.
4. **Overinjection keeps its gates.** Replacing them with a `KeyPresent` gate
   on the new flag would make every method and function of every file an
   abstention of `constructor-overinjection` and flood `abstentions` with
   subjects that were never in its population. The gates stay unchanged.
5. **Ownership by kind.** Constructors of abstract classes, interfaces, traits
   and anonymous classes go to `constructor-overinjection` too, with the name
   matched case-insensitively. They are declarations with a class context, so
   the injection rule already judged them; excluding them from the flag would
   have left them reported by both rules.
6. **A global function named `__construct` stays with `long-parameter-list`.**
   It has no class context, so `constructor-overinjection` never admitted it,
   and it is a function, not a constructor.
7. **The owner of "not a value object" is the injection rule, and that
   population is wide.** The value-object predicate (ADR 0131) is narrow, so
   every mutable DTO, entity, and record that validates in its constructor body
   or keeps private state behind derived getters is now judged at 8/12 with
   the advice to split responsibilities. A five-parameter validating record
   used to warn under `long-parameter-list` and is now not reported. The flag
   name says "injection" for a population that is defined negatively. Rejected:
   counting only collaborator dependencies (issue #170's second option: keep
   both rules, with `constructor-overinjection` counting object-typed
   parameters). It needs a second metric and a type inference of what a
   "service" parameter is, which the collector cannot decide reliably (an
   interface-typed value, a closure, a DateTimeInterface); one owner per
   constructor from evidence the collector already holds is cheaper and
   decidable. The cost is accepted and recorded here rather than hidden in the
   flag name.
8. **No option.** A switch to restore the double report (such as an
   `exclude_constructors` option on `long-parameter-list`) was rejected as a
   surface that preserves the defect; `@qmx-threshold` already gives a finite
   local boundary.

## Consequences

- Disabling `constructor-overinjection`, or excluding it with `--only-rule`,
  leaves the constructors it owns unjudged. `long-parameter-list` does not take
  them over. This mirrors the value-object direction, where disabling
  `long-parameter-list` leaves VO constructors unjudged by the injection rule.
- A non-value-object constructor with four to seven parameters is no longer
  reported; it was the noisiest part of the double report. A constructor with
  eight or more is reported once, by the injection rule.
- A `@qmx-threshold code-smell.long-parameter-list` on a constructor owned by
  `constructor-overinjection` is inert.
- Breaking for consumers: findings and baseline entries of
  `code-smell.long-parameter-list` on non-value-object constructors disappear;
  the equivalent findings come from `code-smell.constructor-overinjection`.
- Self-analysis: 15 `@qmx-threshold code-smell.long-parameter-list` directives
  on non-value-object constructors became inert and were removed. Their
  reasons said the parameters were not injected services,
  which is the population of decision 7: for these the
  removal widened the accepted size from 4/6 to 8/12 rather than proving the
  constructors services; 17 others sit
  on value-object constructors and stay. Two ratchet ceilings, on the
  constructors of `CheckCommand` and `BaselineRun`, were removed.
