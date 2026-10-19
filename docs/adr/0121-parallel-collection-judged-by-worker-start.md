# 0121. Parallel Collection Is Judged by Worker Start

**Date:** 2026-10-15
**Status:** Accepted.

## Context

Creating a worker pool is not proof that the first worker can start. A silent sequential fallback for an explicit worker count can make a requested parallel run look successful while ignoring the user's choice.

## Decision

Parallel collection uses worker processes. The supported transport is process-based; `ext-parallel` threads and forking are not alternatives. Before a pool starts, the runtime checks process availability (`proc_open` and, outside Windows, `ext-posix`). The first submitted probe must start and answer within ten seconds, and each worker context has the same bounded start deadline. A startup exception or deadline becomes a typed worker-start failure with bounded startup-log evidence, not an ordinary cancellation.

For an eligible run with an explicit count greater than one, unavailable transport or a failed first probe refuses as an environment error (exit 3). Automatic selection falls back to sequential processing with a warning. A run without working process transport can therefore pay up to ten seconds before the refusal or fallback. Once processing has started, a failed file or worker death marks analysis incomplete (exit 4), with a file-specific reason. Coordinator checks for class existence, collector markers and the dependency-traversal interface fail once as an internal error (exit 5), before opening the pool. Constructor arity is not prevalidated; failures during worker construction or its first probe follow the startup refusal or fallback described above. Worker stdout/stderr are captured, while PHP warnings are presented consistently with sequential collection. Workers receive parser state including `short_open_tag`. Parallel owns `WorkerFatalNotes` for worker reserve, protocol and active-file state. Its consumer-owned `WorkerDiagnosticsInterface::diagnostic(string)` receives scalar messages; Console adapts its existing run-bound `ErrorStream` through DI. Parallel does not import Console, and no generic Core or Run diagnostic port is introduced.

## Consequences

An explicit count promises attempted process parallelism for a run large enough to cross the threshold, not a silent fallback. Small runs and explicit sequential modes retain sequential execution. Consumers should distinguish startup refusal, incomplete work and internal composition errors by exit code.

The Docker image installs `ext-pcntl` for coordinator SIGINT/SIGTERM cleanup,
including stdout-only runs and PID 1 under `docker stop`. Process-worker
transport itself continues to require only `proc_open` and `ext-posix`. DI passes
Console's exhaustion registrar as a closure to the parallel strategy; startup
logs are therefore released on coordinator OOM without importing Console into
Parallel. A context drains stdout/stderr before reporting its death; EOF with no
shutdown note answers immediately instead of adding a second startup deadline.
