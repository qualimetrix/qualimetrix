# E2 — every writer into the process error stream, by command

Subject: package 5 of X5. The measurement that opened the package named **four
seams** (the bar's frame owner, `DiagnosticOutput`, the logger factory, the
command's own warning write). That was knowledge gathered while reading for
another question, not an enumeration, so the package starts by counting.

Machine table: `enumeration-error-stream-writers.tsv` (24 rows: the tree before
the change and after it, one row per write site).

## How this was obtained, and what the method does not see

Every row is produced by a script, not by reading. The script parses each file
under `src/` with `nikic/php-parser`, resolves names, and records four shapes
that reach the process error stream:

- a `->getErrorOutput()` call,
- a hand-built `new ConsoleSectionOutput`,
- construction of a logger over an output (`new ConsoleLogger`, `new FileLogger`),
- `fwrite(\STDERR, …)`.

The per-command column is a second pass: for every `*Command` class it takes the
transitive closure over constructor parameter types, declared property types,
`new X` in the body and `X::` static calls, expanding an interface to its `src/`
implementors. The closure **over-approximates** on purpose — a writer that any
plausible wiring can reach must appear, and a missing row would be the
expensive error.

**What the method does not see.**

1. **A write through an injected `OutputInterface` that happens to be the error
   stream.** The script recognises the acts of *obtaining* the stream, not every
   `writeln()` afterwards. This is why the seam list is a list of owners, not of
   calls: `ResultPresenter` has six call sites and one seam.
2. **Anything in `vendor/`.** One framework writer was found by a separate,
   cruder scan of `vendor/symfony/console/Application.php` and is reported by the
   script as `FRAMEWORK`: `Application::run()` renders an uncaught throwable to
   `$output->getErrorOutput()` (line 175), and `--help` describing to the same
   place (line 334). Any other library that writes to stderr is invisible here.
3. **A different receiver with the same method name.** `Symfony\Component\
   Process::getErrorOutput()` returns a captured string; the only discriminator
   available without type inference is the receiver's variable name, so the
   script excludes `$process->getErrorOutput()` and *reports each exclusion*
   (`GitClient.php:118`, one row).
4. **Whether a writer fires while a frame is on screen.** That is not statically
   decidable and was measured separately (`stand-today-error-stream.md`).
5. **`error_log()`, PHP's own warning output, and a child process's stderr.**
   The first two are not in the source at all; the third is row S7 below and is
   out of this process's reach by construction.

## The seams

Grouped by owner, because a seam is an owner and not a call site. `during` says
whether the writer can fire while a progress frame is on screen.

| id  | owner                                         | mechanism (before)                                                                                     | fallback when there is no error channel | during                                      | commands                                                              |
| --- | --------------------------------------------- | ------------------------------------------------------------------------------------------------------ | --------------------------------------- | ------------------------------------------- | --------------------------------------------------------------------- |
| S1  | `RuntimeConfigurator`                         | held its own section list, built the frame's section                                                   | no frame                                | —                                           | check, directives, debug:layer-assignment, the four baseline commands |
| S2  | `LoggerFactory`                               | `getErrorOutput()`, then `new ConsoleLogger` over it                                                   | `NullOutput` — dropped                  | yes                                         | same seven                                                            |
| S3  | `DiagnosticOutput`                            | `getErrorOutput()`; 8 call sites in `ResultPresenter`, `ProfilePresenter`, `FindingFilterOrchestrator` | `NullOutput` — dropped                  | after the frame                             | check                                                                 |
| S4  | `CheckCommand::writeWarning`                  | `getErrorOutput()` directly                                                                            | no `else` at all — dropped              | before the frame                            | check                                                                 |
| S5  | `GraphExportCommand::writeIncompleteAnalysis` | `getErrorOutput()` directly                                                                            | **the payload output itself**           | no frame on this command                    | graph:export                                                          |
| S6  | `Symfony\…\Application::renderThrowable`      | the framework resolves `getErrorOutput()` itself                                                       | the output itself                       | yes — nothing calls `finish()` on this path | every command                                                         |
| S7  | `WorkerBootstrap`                             | `fwrite(\STDERR, …)`                                                                                   | none                                    | in a **child process**                      | check, directives, baseline, debug:layer-assignment                   |

### Three corrections to "four seams"

1. **There are five in this process, not four**, and the fifth (S5) is on a
   command the measurement never ran: `graph:export` writes its incomplete-analysis
   report straight to the error stream, and it is the one seam whose fallback
   folds diagnostics **into the payload** rather than dropping them.
2. **A sixth is the framework's** (S6), reachable from every command, and it is
   the only writer guaranteed to fire with a frame still on screen — an uncaught
   throwable skips the reporter's `finish()`.
3. **The fallbacks were four shapes, not three**: lose it (S2, S3), drop it with
   no branch at all (S4), and write it into the payload (S5).

S7 is not a writer into this process's error stream. `ContextWorkerPool` builds
its contexts through `ProcessContextFactory`, whose child stderr is a pipe amphp
owns; the warning never reaches the terminal, which is a separate defect and not
this package's. It stays in the table so the next reader does not have to
rediscover why it is out of scope.

## What the change did to each row

| id  | after                                                                                                                                                                 |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| S1  | the section list moved to `ErrorStream`; the four gates moved out of `RuntimeConfigurator` into `Progress/ProgressConfigurator`, which asks the owner for the section |
| S2  | `LoggerFactory` no longer chooses a stream — `RuntimeLoggerConfigurator` hands it the owner's writer                                                                  |
| S3  | `DiagnosticOutput` is **removed**; its two methods are the owner's `writer()` / `write()`                                                                             |
| S4  | routed through `ResultPresenter::writeDiagnostic()`, the channel this command already uses, and so through the owner                                                  |
| S5  | asks the owner for the writer; its payload fallback is gone                                                                                                           |
| S6  | `Application::renderThrowable()` is overridden: it clears the frame, then writes through the writer the owner is already bound to                                     |
| S7  | unchanged, and declared out of reach                                                                                                                                  |

After the change the script finds **one** `getErrorOutput()` in `src/`, inside
`ErrorStream`, and **one** hand-built section, in the same file. A guard test
(`tests/Infrastructure/Console/Integration/ErrorStreamSoleOwnerTest.php`) keeps
it that way with an allowance row per exception.
