# Infrastructure Parallel

Parallel execution adapters own worker configuration and runtime transport.
Their public contracts are limited to named external consumers.

`ParallelConfigurationResolver` declares the `parallel.workers` section and
reads its resolved scalar. Every layer that writes it must supply an integer
of at least zero, even when another layer overrides it. The section and the
resolved reader share the same range validator. The worker memory limit
remains a Console runtime concern, not Parallel configuration.
`ParallelConfiguration::workerCountRefusal()` owns that integer grammar;
its constructor rejects a negative programmatic count with
`InvalidArgumentException`. The schema keeps authored provenance when
refusing the same count through a pure `IntegerJudgement` declaration.

**Worker count.** `WorkerCountDetector` reads `NUMBER_OF_PROCESSORS` only on
Windows, accepting a positive decimal integer within PHP's integer range and
refusing another authored value. On Linux it takes the smaller of the CPU
stanza count and `Cpus_allowed_list`. It then caps that count by the smallest
cgroup quota from the process's own group to its mount root, for both v2
`cpu.max` and v1 `cpu.cfs_quota_us`/`cpu.cfs_period_us`. A readable v2 file is
authoritative, including an unlimited or malformed value; it never falls back
to v1 behind that answer. An unavailable namespace path uses the mount root.
`CgroupLocation` owns the mounted group ancestry, and `CgroupCpuQuota` owns the
inherited whole-CPU limit; both are private Parallel implementation types.
No quota means no cap. The detector accepts a filesystem root, OS family and
environment values so tests do not mutate process-global environment. Native
shell probes run only without a planted root; unavailable `shell_exec` skips
them, leaving the documented fallback of four processors when no probe answers.

**The worker's parser.** `WorkerBootstrap` asks
[`WorkerParserFactory`](../Ast/WorkerParserFactory.php) for it rather than
assembling it. The assembly is the same subject as the container's
`FileParserFactory`, and keeping it there is what keeps the cache vocabulary out
of this namespace.

**What a task carries.** A worker process has no container, so
`FileProcessingTask` carries by name what the container registered —
`WorkerComposition`: collectors, derived collectors, the traversal participant
and the rules — beside the per-file path, the cache directory, the Cohesion
configuration current at task creation, and the memory limit. It also carries
the registered `RuleOptionDocumentFormsInterface` service instance through PHP
serialization. Main DI and worker inline validators therefore use the same
Finding interpretation; workers import only its public interface. The task
factory, task constructor and `WorkerBootstrap::getFileProcessor()` require
that service. Bootstrap takes the already serialized `WorkerComposition` as
its metadata argument, rather than separate collector, derived-collector,
traversal and rule class arguments. Processor reuse includes its serialized class and state in the
cache identity, alongside the existing project and collector configuration.
`WorkerComposition` validates its own collector and traversal class names
before startup; bootstrap applies the same checks when rebuilding a processor.

**Worker startup.** `WorkerRequest` keeps the accepted count and whether it was
explicitly requested. Before collecting files, `WorkerPool` runs a
`WorkerProbeTask` through the same serialized configuration as real tasks.
Only process workers are supported; `ext-parallel` threads and forks are not
alternative transports.
`WorkerRequest` owns the shared ten-second startup and diagnostic-drain bound.
`WorkerContextFactory` gives process startup that deadline and
returns a `WorkerStartFailure` with the request and native cause when it cannot
connect. `WorkerPhpFailure` carries a worker's private PHP fatal diagnostic back
through IPC so the failed file retains its actual cause.

**Memory limit.** A worker is a separate process that starts under `php.ini`,
and the coordinator's `ini_set()` does not cross the process boundary.
`FileProcessingTaskFactory` puts the coordinator's `memory_limit` on every
task and `FileProcessingTask` applies it before processing. `WorkerContextFactory`
starts only PHP processes, keeps a private PHP error log per process, and
captures stdout and stderr through `WorkerNotes`. `WorkerContext` reads that
log when IPC fails: PHP records a fatal before its shutdown callback runs, and
on Linux that callback may never run before the context is closed. The failed
file retains the PHP diagnostic and worker limit; a missing diagnostic falls
back to the bounded shutdown-note drain and then a generic cause. `WorkerPool`
re-submits a task the dead worker refused before starting it and routes pool
crash notices to the log instead of stdout. The private log is removed when
the pool closes.
`WorkerNotes` sends child diagnostics through the Parallel-owned
`WorkerDiagnosticsInterface`; Console's run-bound `ErrorStream` implements this
one-method port. Its separate stdout and stderr readers decode nonce-bearing
PHP notes only on stderr; other lines remain visible as worker diagnostics.
`WorkerFatalNotes` owns the worker's nonce, active-file state,
error handler and shutdown note. Console retains only the coordinator's memory
refusal, keeping dependencies in the Console-to-Parallel direction.

An unavailable local IPC socket or startup-log directory is a worker startup
failure. An explicit worker count is refused with its native cause; automatic
selection warns once and collects in-process. A partially opened IPC hub is
closed when its startup-log directory cannot be created.

**Interruption.** `Amp\CancelledException` bypasses per-file error recovery.
The pool kills pending workers on cancellation instead of awaiting graceful
shutdown, so an interrupted coordinator does not wait for another file result.

**Collector marker.** A registered collector that does not implement
`ParallelSafeCollectorInterface` is refused, like a collector class that does not
exist. Skipping it instead made `--workers=N` measure less than `--workers=0`
with nothing said anywhere a caller looks: the metrics were absent, the rules
reading them reported nothing, coverage stayed complete and the exit code stayed
normal.

The selector obtains the accepted cache directory through the Cache-owned
`CacheOpener`, including sequential selection. Workers use the supplied directory
directly and do not open or sweep the coordinator cache.
