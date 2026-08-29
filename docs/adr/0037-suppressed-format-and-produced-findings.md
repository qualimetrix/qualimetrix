# 0037. A Separate `suppressed` Format, and Rule Execution Returns What It Produced

**Date:** 2026-08-29
**Status:** Accepted

## Context

Before this step, what a run suppressed was observable only as prose: a line
of text plus a count, printed by `FindingFilterOrchestrator` when
`--show-suppressed` was passed, and readable only on the text surface. No
format published *which* findings were held back, by *what*, and the ledger
that decides per-rule `exclude_namespaces`/`exclude_namespace_channels`/
`exclude_paths` did not even keep the finding objects it removed — only a
count per rule (`RuleExclusionStats`). The consequence was measured directly:
Ш5e2b removed one point threshold inside a namespace excluded for one rule,
and the suppression count moved 55 → 56 with no test, no format and no exit
code noticing (`docs/internal/plans/rule-vocabulary/AUDIT.md`, "Found while
moving the level vocabulary").

Two design questions had to be settled before that gap could be closed:

1. Where does the composition of what was suppressed get published — as a new
   section of an existing format, or as a format of its own?
2. What does rule execution need to hand downstream so that composition can
   be built, given that today it returns only the findings that survive
   filtering?

## Decision

### The composition is a new format (`suppressed`), not a section of `json`

`bin/qmx check --format=suppressed` is a second, independent analysis format.
The `check` payload for every existing format — `json` included — does not
move by one byte for a run that never asked to see what it suppressed.

This is not the more convenient shape. A section inside `json` would mean
every `json` consumer's parser sees a new key whether or not it asked for the
composition, and the flag that used to gate this exact kind of output has
already broken every machine-readable format once: `AUDIT.md` item 4 recorded
that `--show-suppressed`, when it printed suppressed-finding prose to stdout
ahead of the formatter's own output, corrupted `--format=json` outright — a
186-byte text preamble in front of the JSON document, so it failed to parse.
That defect closed incidentally in a later, unrelated commit (routing
diagnostics to stderr), but the shape of the risk is the same one a `json`
section would reintroduce deliberately: a feature nobody asked for changing
the bytes of a payload everybody parses. A separate format makes that
impossible by construction — selecting `suppressed` is itself the request,
and every other format's output is untouched whether or not the capture ran.

No new command is introduced. Requesting a different format is already a
second, independent analysis run (`bin/qmx check src --format=json` and
`bin/qmx check src --format=sarif` are two separate invocations today); adding
`suppressed` to that set costs nothing a new command would not also cost, and
a command would additionally need its own option surface, redundant with
`check`'s.

### Capture is armed by the flag OR the format, not by one alone

`--show-suppressed` predates this format and still selects the prose rendering
on the text surface. The per-rule ledger capture the `suppressed` format needs
is expensive enough (it retains every ledger-excluded finding, not just a
count) that it must not run unconditionally on every `check`. Gating it on the
CLI flag alone would silently produce an empty composition for
`--format=suppressed` invoked without `--show-suppressed` — and `format:` is
also a legal `qmx.yaml` key (`ConfigSchema::FORMAT`), so a config file setting
`format: suppressed` has no CLI flag to gate on at all.
`RuntimeConfigurator` therefore arms the ledger capture on the disjunction:
the flag is present and true, **or** the resolved format is `suppressed`. The
two routes to the same capture cannot disagree with each other, and neither
route depends on the other existing.

### Rule execution returns what it produced, as a value

Auditing whether a directive changed anything — the second half of the gap
this step closes, and the substrate `annotation.unused-directive`'s
`@qmx-threshold` audit will need — requires comparing findings **before** the
per-rule ledger and per-finding channel selection against findings **after**.
Nothing held that "before" state: `RuleExecutionInterface::execute()` returned
only the published subset, and `exclusionStats()` was a separate stateful
accessor on the same service, reset by `begin()` on every call — a second
prepared-then-published run through the same object would have overwritten it
mid-comparison.

`RuleExecutionResult` (`src/Analysis/Finding/Contract/RuleExecutionResult.php`)
replaces that pair with one immutable value carrying three readonly
properties: `$produced` (every finding rules and their configuration
validators produced, before the ledger and channel selection ran), `$published`
(the subset `execute()` used to return), and `$exclusions`
(`RuleExclusionStats`, unchanged in shape). A caller comparing two runs — the
real one and a directive-stripped counterfactual — reads `$produced` from
both; `bin/qmx check`'s own reporting path keeps reading `$published`, so no
existing surface changes.

The contract exposes three public properties rather than three getter
methods. An early revision used single-field getters and immediately dropped
this project's own `health.cohesion` self-check below its threshold — a
value object whose only behaviour is returning its own fields has, by
construction, no cohesion for TCC to measure. `RuleExclusionStats`, the
sibling type this result composes, already uses public readonly properties
for the same reason; matching that idiom rather than introducing a second one
for one new type was the response to the metric, not a threshold override.

## Alternatives considered

**Derive the "before the ledger" set from `getEffectiveOptions()`/
`getEffectiveSeverity()`'s decision points instead of adding a value.** This
would have removed the need for `$produced` and its pairing key entirely, but
it requires every rule (26 classes) to expose that decision point and turns
each rule into a holder of per-run comparison state — directly against the
"stateless rules, stateful-per-file collectors" invariant this project holds
rules to. Rejected for that reason, not for its cost.

**Add a `--show-suppressed`-only view instead of registering `suppressed` as
an ordinary format.** Rejected because it reintroduces exactly the coupling
between an option flag and a payload's shape that caused the historical `json`
corruption: the safer contract is "selecting the format is the only way to
ask for it," with no flag able to mutate a different format's output as a
side effect.

## Consequences

- `bin/qmx check --format=suppressed` is documented and versioned like any
  other format; adding an eighth suppression mechanism in the future is a
  change to `SuppressionMechanism`, not to a `json` schema consumers already
  parse.
- `RuleExecutionInterface::execute()` and the six test stubs implementing it
  moved to return `RuleExecutionResult` instead of `list<Finding>`, and every
  consumer of the old `exclusionStats()` accessor now reads
  `$result->exclusions` from the same value instead of a second call. This is
  a one-time internal contract change with no external surface effect —
  `bin/qmx check`'s payloads are unchanged for every format that existed
  before this step.
- A counterfactual audit pass (Ш8) can now compare `$produced` between two
  `RuleExecutionResult`s without needing its own copy of "what would this rule
  have found," because the real run already retains it.
- The composition itself is a multiset over mechanism × finding, and every
  consumer of `suppressed` output (the format, the versioned snapshot under
  `docs/internal/generated/suppression/`) has to say so rather than let
  per-mechanism counts imply a total that does not exist — a cost this
  decision accepts explicitly rather than hides.
