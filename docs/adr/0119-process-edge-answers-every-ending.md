# 0119. The Process Edge Answers Every Ending

**Date:** 2026-10-15
**Status:** Accepted.

## Context

Command-local catches, Symfony's default renderer, PHP shutdown and native process failures used to disagree about exit code, stream and error shape. An answer-producing command must still answer under quiet output, while an action command can remain quiet. Missing dependencies and coordinator memory exhaustion can happen outside the body of a command.

## Decision

The Console process edge classifies an input or environment refusal as exit 3, incomplete analysis or coordinator exhaustion as exit 4, and an internal defect as exit 5. It owns stderr prose and JSON error envelopes; findings policy retains 0/1/2. Unknown command names, empty option values and unavailable native operations enter this ladder before command-specific work. Console binds one diagnostics owner to the application and built container. A container build defect exits 5; a marked refusal exits 3. The CLI entrypoint reports PHP below 8.4 and a missing autoloader as exit 3.

Answer-producing commands publish their answer at quiet verbosity, including `-q`, `--silent` and `SHELL_VERBOSITY=-1|-2`; `list` and `help` follow their Symfony output behavior. Action commands suppress status output under quiet. A caller wanting only the exit code redirects stdout.

The reserved shutdown handler renders an out-of-memory hint with the configured limit and its source only after diagnostics have been bound and reserve memory allocated. An earlier foreign shutdown callback can overwrite PHP's last error before this handler observes it. Unpublished named report/profile targets are released on this path; already published targets are not rolled back. Worker startup and diagnostic draining use deadlines; captured output and warnings share the same process-facing routing. Captured worker stdout/stderr has no fixed byte or line-count limit. Console's `OutOfMemoryHint` covers the coordinator only; Parallel owns worker fatal state and provides a scalar diagnostic port that Console's run-bound `ErrorStream` implements.

`Core\Environment\EnvironmentFailureInterface` is a marker for exact storage/delivery producers. Core's `ProcessOwner` and `NativeCall` remain neutral helpers with their documented public contracts; this decision does not make all Core implementation types public.

## Consequences

Consumers use the exact exit status and stderr/JSON envelope instead of a command-local exception code or silence under quiet. The OOM promise begins at successful binding and reserve allocation. Publication is per target; a profile published before a later report OOM remains available.

This decision supersedes point 1 of ADR 0051's quiet-output section. Its other historical decisions remain as recorded.
