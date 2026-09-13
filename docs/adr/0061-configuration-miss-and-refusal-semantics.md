# 0061. Configuration Miss and Refusal Semantics

**Date:** 2026-09-13
**Status:** Accepted

## Context

A syntactically valid configuration value can still bind to nothing or have a
different effect from the one its door promises. Earlier work separately chose
key recognition, value-shape declarations, a shared refusal carrier, console
routing, and miss classification. The final user-visible contract needs one
current statement.

## Decision

A closed configuration door refuses any supplied key, selector, name, or value
shape that it cannot bind to its declared target set. Refusal happens before the
input can be silently coerced, ignored, or replaced by a default. Open,
user-named families validate their documented shape instead of pretending to be
closed enumerations.

The owner of a configuration key declares its accepted shape beside the key.
Rule options do this through `RuleOptionKeySet`; root configuration and
subject-owned documents do it at their own schema boundary. Recognition covers
both a rule's root and every declared level slot. A key deliberately answered by
its options class reaches that class so it can refuse in subject-specific words.

`Analysis\Configuration` owns `ConfigurationRefusal`, the one typed carrier for
bad user input. Its three forms distinguish a positioned key, a whole document,
and an input without a document position. Subject capabilities may throw the
carrier, but they do not choose the process code, stream, verbosity, or envelope.
The outer Console ladder presents refusals uniformly as exit code 3; internal
defects remain exit code 1. Formats designated for JSON error transport receive
one structured envelope; every other report format receives the terminating
diagnostic on the error stream. Quiet modes may suppress report payload and
progress, not the sentence that explains why the run ended.

A referential value that binds to no target is classified by observable effect:

- if the miss would produce less analysis or reporting than the author asked
  for, the input is refused;
- if it can only add a debt diagnostic while coverage is sufficient, it is an
  ordinary warning finding;
- if the observable result is identical to a hit, the miss is silent because
  there is no lost promise to report;
- if the accepted set is closed before analysis, the miss is refused at that
  boundary.

Configuration layers compose by written values, not by key-spelling heuristics.
Each layer unfolds supported shorthand before merging. A higher layer changing
one member of a value group preserves members written by lower layers; `null`
does not fabricate a reset where the contract defines it as absence.

This ADR consolidates the current miss and refusal semantics. ADRs 0049–0051,
0055, and 0058 retain the detailed rationale for key recognition, carrier
ownership, presentation, shape, and layer composition.

## Consequences

- A user can distinguish rejected input from an internal defect by exit code
  and stable framing rather than command-specific exception handling.
- Adding a configuration door requires a declared key set or open-set shape and
  an explicit miss policy.
- Configuration validation cannot silently restore defaults after accepting an
  unknown or wrongly shaped value.
