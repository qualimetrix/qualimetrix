# 51. Refusal Is Not Routed by Command

**Date:** 2026-09-09
**Status:** Accepted

## Context

Before this round, the exit code, the output stream and the message shape a
failed run produced were a property of which command caught the failure, not
of what kind of failure it was. `baseline:*` wrote its refusal to standard
output, the same channel as its report, at normal verbosity; `rules
--group=<unknown>` and `graph:export --direction=<unknown>` picked their own
exit codes for bad input — 1 here, 2 there; `debug:layer-assignment` used 2
for invalid input and 1 for configuration-load errors; and a crash reading
configuration that escaped every command-level `catch` reached Symfony's
default handler and exited 255 with a raw PHP trace on both streams. Two of
those routes — `baseline:rename-channels` and `debug:layer-assignment` — are
now retired onto exit code 3 by this round; ADR 0050 gives the type that
made a single carrier possible across the four owners that used to throw
five unrelated exception classes.

A command that receives a `ConfigurationRefusal` — or an `InvalidArgumentException`
with no carrier behind it, still a named secondary signal — no longer decides
its own exit code, its own stream or its own message shape. `Analysis.Configuration`
owning a shared carrier type (ADR 0050) is a necessary condition for this, not
the decision itself: a shared type thrown into five still-separate `catch`
blocks would still let each command pick 1 here and 2 there. The decision this
ADR records is that the *presentation* of a refusal — exit code, stream,
verbosity, envelope shape — is now assigned once, by the kind of throwable
caught, never by which command's `catch` block caught it.

## Decision

**The round owns exactly two codes, and only the kind of throwable decides
between them: 3 for a refusal by user input, 1 for anything else.** `Application::doRun()`
(`src/Infrastructure/Console/Application.php`) is the outermost ladder: it
wraps the whole command dispatch, in clause order `ConfigurationRefusal` →
`ConsoleExceptionInterface` → bare `InvalidArgumentException` → `Throwable`.
The first three all resolve to exit 3 through `RefusalPresenter::refusal()`
or `RefusalPresenter::fallbackRefusal()`; the last resolves to exit 1 through
`RefusalPresenter::internalError()`. No command-level `catch` re-decides this
once a throwable reaches the ladder — the five commands that used to hold a
`catch (A|B|C|D)` clause and rebuild `ConfigurationFailure` from whichever
type they caught (P01-7) no longer hold one.

**Type is the primary signal, not the only one, and the secondary signals are
named rather than folded into the primary one.** `ConfigurationRefusal` is the
carrier every owner is migrating throw sites onto (ADR 0050), and it is the
only signal a reviewer should expect to grow. `ConsoleExceptionInterface`
(Symfony's own console-argument errors — unknown command, unknown option) and
a bare `InvalidArgumentException` with no carrier behind it are named
secondary signals for the same code, kept as separate `catch` clauses and
separate presenter methods precisely so each stays a call count rather than
an inference: `measurement/03-catch-clauses.md` counts 178 throw sites still
reaching exit 3 through the bare `InvalidArgumentException` clause rather than
through `ConfigurationRefusal`, as of this round's start. That number is
named debt, not tolerated design — a future round retires it by moving each
site onto the carrier, not by widening what the fallback clause catches.
**After this round: 147**, measured by the orchestrator on a tree that had
stopped changing, with the same command and the same population definition as
the 178. Thirty-one sites moved onto the carrier. The remainder is not zero by
design: `src/Infrastructure/Rule` and `src/Infrastructure/Git` were left on the
fallback deliberately, and they are counted rather than converted.

**We assign the internal-error exit code ourselves; we do not read
`getCode()`.** Symfony's own `Application::run()` takes its exit code from
the caught throwable's `getCode()` when nothing else decides it — 0 for a
`TypeError`, arbitrary for anything else that happens to set one. Reading
`getCode()` here would make the exit code an accident of whichever exception
class a bug happened to throw, not a decision this round makes. `Application::doRun()`
ignores it entirely and assigns 1 to every `Throwable` that is not one of the
three refusal signals above.

**`TypeError` stays code 1. It is not folded into refusal.** A `TypeError`
reaching a user is a defect in this project's own type declarations, not a
malformed input the configuration's author wrote — the user did nothing that
a correct program would have rejected differently. A package that routed
`TypeError` to exit 3 would not have closed the underlying crash, only
relabelled it as if the user had caused it; the throw sites this round
touches replace a `TypeError` with a thrown `ConfigurationRefusal` *before*
the type failure can occur (see `02-computed-metric-keys.md`,
`02-computed-metric-packages.md`), rather than catching the `TypeError`
afterward and recolouring it.

**Only an internal error prints a trace, and only above normal verbosity.**
`RefusalPresenter::internalError()` writes the trace at
`OutputInterface::VERBOSITY_VERBOSE` and above; `refusal()` and
`fallbackRefusal()` never do. A refusal is the user's problem to fix, named in
one sentence; an internal error is ours, and the trace is diagnostic detail
for someone filing a bug, not something a refused-input user needs to see by
default.

**`-q` suppresses payload and progress, never the sentence that ends the
run.** Both the refusal message and the internal-error message are written at
`OutputInterface::VERBOSITY_QUIET` (`RefusalPresenter::present()`,
`writeStderr()`, `writeEnvelope()`) — the same level Symfony's own
`Application::doRenderThrowable()` already uses for an uncaught throwable
(`vendor/symfony/console/Application.php:882,887,888,934,937,956,959`), so this
is not a house convention invented for this round. Three points back this,
each measured rather than assumed:

1. `-q` already suppresses everything else. On valid input, `check src
   --format=json -q` produces `ec=0`, zero bytes on stdout, zero bytes on
   stderr. "Quiet hides the document" is not violated by also writing the one
   sentence a failed run needs — there is no document under `-q` in success or
   failure to begin with.
2. Symmetry is preserved, not broken. Under `-q` the user now gets exactly one
   sentence where they previously got nothing on `baseline:*`'s failure path,
   or a code with nothing behind it elsewhere — on the same channel as
   without `-q`.
3. Under a machine-readable format the message is not text at all: it is the
   `{error, exit_code}` envelope on stdout (`RefusalPresenter::writeEnvelope()`),
   the same shape `DirectiveAuditPresenter::jsonError()` and
   `LayerAssignmentCommand::reportError()` already used. `-q` does not remove
   the envelope; a machine-readable consumer piping stdout still gets a
   parseable document on both the success and the failure path.

**Rejected alternative: "quiet means only the exit code."** This is Symfony's
own default — `VERBOSITY_QUIET` swallows everything a command writes,
including its own final diagnostic — and it is a legitimate design, not a
strawman. Before this round, position #51 in the verdict enumeration
(`measurement/verdicts-30-75.md:81`) already lived there by accident: `-q`
under a bad `--rule-opt` selector produced zero bytes on both streams at exit
**0**; after X13's earlier work it produces zero bytes at exit **3** — a
strict improvement on the exit-code axis alone, and the existing regression
test `tests/Infrastructure/Console/Unit/RuleOptionKeyDoorSymmetryTest.php:186-191`
(`itStillRefusesUnderQuiet`, renamed by P01-3 from asserting silence to
asserting the sentence survives) pins that this was a deliberate prior
decision, not an untested gap.

We reject it here for two reasons, both measured rather than asserted. First,
exit 3 alone says "the input was refused," never *which* key and *in which
file* — a user who set `-q` in CI for a quiet log gets a red build with no
lead, and has to re-run by hand to learn what to fix; `-q` would be buying
silence at the cost of a second run. Second, making `-q` the one flag that
changes not volume but the *set of diagnostics available* is the same class
of defect this round removes on the exit-code axis, moved to the stream axis:
a run nothing can explain does not become explainable because the caller
asked for less noise. A run's own presenter, not the verbosity flag, decides
what belongs to "payload" (suppressible) versus "the sentence that ends the
run" (not).

## Consequences

- `ConfigurationFailure` (`src/Infrastructure/Console/ConfigurationFailure.php`)
  is deleted along with the per-command `catch (A|B|C|D)` clauses it served —
  its only job was re-framing whichever of the five retired exception types
  (ADR 0050) had been caught into one sentence, which `RefusalPresenter` now
  does once, upstream of every command.
- A command that needs a domain-specific exit code outside {0, 1, 3} for its
  own analysis outcome — `directives`' 2 (inert directive) and 4 (run
  incomplete), `check`'s and `graph:export`'s 4 (incomplete analysis) — keeps
  deciding that itself; this ADR governs only the refusal/internal-error
  split, not analysis-outcome codes, which the round does not touch
  (`00-overview.md`).
- A CI wrapper that only checked `exit code != 0` sees no behavioural change.
  One that branches on the code should now read 3 as "fix the configuration
  or the input," 1 as "file a bug," and 2 or 4 as "read the command's own
  report."
- The 178-site debt this ADR names is tracked by count, not by name: a future
  package retiring throw sites onto `ConfigurationRefusal` re-runs
  `measurement/03-catch-clauses.md`'s method and compares the new count
  against 178, not against a list of which sites moved.
- Supersedes no prior ADR. Builds on ADR 0050 (the carrier type this
  decision routes) and ADR 0045 (the single `ErrorStream` owner this
  decision's stream and progress-frame handling depend on).
