# 0045. One Owner for the Error Stream

**Date:** 2026-09-04
**Status:** Accepted

## Context

The progress bar draws into a console section over the error stream. Every
diagnostic — the logger, the analysis warnings, the `graph:export`
incomplete-analysis report, Symfony's own throwable rendering — wrote to the
same stream directly, past any section.

A frame erases upwards by the height of its own section, so anything that
arrived between two frames was counted as the frame's own lines and destroyed.
Measured under a pseudo-terminal, replayed through a terminal that applies the
erases: at `-vv` and `-vvv` two log lines are gone and the bar itself is
stranded at `0/20 … 0%` for the rest of the run. The user loses the log and the
progress at once. The threshold is `-vv`, not `-vvv` as an earlier reading had
it, and the mechanism is not about verbosity at all — verbosity only supplies
writers.

An enumeration of the writers found six, not the four an earlier reading named,
and four fallback shapes, not three; one fallback folded diagnostics into the
report payload, which promises to parse.

## Decision

**`ErrorStream` owns the error stream: it holds the section list, creates the
diagnostic section before the progress section, and hands out both.** A
diagnostic erases the frame, is written permanently, and the frame is redrawn
beneath it. `DiagnosticOutput` is gone, `LoggerFactory` no longer picks a
stream, and `ProgressConfigurator` takes the progress gates out of
`RuntimeConfigurator`. There is one fallback: where an output has no error
channel, diagnostics are dropped — never folded into a payload.

**The bar is not silenced above normal verbosity.** That is the cheap cure, and
it is the wrong one: it would make the README's promise true and leave the
mechanism broken for the next warning emitted during a run at default
verbosity, where no verbosity flag is involved.

Alternatives considered and rejected:

- **Silence the bar above `NORMAL`** — three lines, makes a documentation
  sentence true, fixes the symptom for one class of writer.
- **Route diagnostics into the payload stream instead** — reintroduces the
  defect ADR-worthy work removed earlier: a machine-readable format must parse.

## Consequences

- Any writer that reaches the error stream during a run must go through
  `ErrorStream`. A new direct write to `getErrorOutput()` is a regression, and
  it is the kind a passing test suite will not catch — the byte streams of an
  intact run and a destroyed one differ either way, so the oracle has to replay
  the erases and compare the final screen.
- `ResultPresenter` takes `ErrorStream` as a parameter. That raised its
  constructor-injection threshold from 9 to 10, and the docblock says why: the
  ninth collaborator is not new — the class always had one for its six stderr
  messages and built it privately, which is exactly what gave the error stream
  two owners. Hiding it again restores the defect.
- One writer stays outside this ownership by construction:
  `WorkerBootstrap` warns with `fwrite(\STDERR, …)` inside a child process,
  whose stream amphp takes. Whether the warning reaches anyone in parallel mode
  is not measured, and is recorded as an open hypothesis rather than a claim.
- stdout is unaffected and byte-identical in all four verbosity modes, before
  and after; the equivalence gate runs the product through a pipe, where the bar
  is off by construction, so it witnesses "nothing else moved" and not this.
