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

**Worker count.** `WorkerCountDetector` caps the host's processor count by the
CPU quota of the control group the process runs in (cgroup v2 `cpu.max`, cgroup
v1 `cpu.cfs_quota_us`/`cpu.cfs_period_us`). Absence of those files means "no
limit", never an error. The cap matters because the product's documented main
setting is a container in CI, where the host count can be an order of magnitude
above what the process may use, and each worker is a process with its own parser
and its own cache. The constructor takes a filesystem root so a test can plant a
control group rather than describe one.

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
that service. Processor reuse includes its serialized class and state in the
cache identity, alongside the existing project and collector configuration.

**Memory limit.** A worker is a separate process that starts under `php.ini`,
and the coordinator's `ini_set()` does not cross the process boundary.
`FileProcessingTaskFactory` puts the coordinator's `memory_limit` on every
task and `FileProcessingTask` applies it before processing. A worker that dies
with a file in hand reaches the coordinator only as a context that stopped
responding — its PHP fatal error goes to stderr past this code — so
`WorkerPool`, which `AmphpParallelStrategy` runs collection on, names the
workers' limit in the failed file's message, re-submits a task the dead worker
refused before starting it, and routes the pool's own crash notices to the log
instead of stdout.

**Interruption.** `Amp\CancelledException` bypasses per-file error recovery.
The pool kills pending workers on cancellation instead of awaiting graceful
shutdown, so an interrupted coordinator does not wait for another file result.

**Collector marker.** A registered collector that does not implement
`ParallelSafeCollectorInterface` is refused, like a collector class that does not
exist. Skipping it instead made `--workers=N` measure less than `--workers=0`
with nothing said anywhere a caller looks: the metrics were absent, the rules
reading them reported nothing, coverage stayed complete and the exit code stayed
normal.
