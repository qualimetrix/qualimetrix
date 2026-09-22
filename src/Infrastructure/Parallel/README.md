# Infrastructure Parallel

Parallel execution adapters own worker configuration and runtime transport.
Their public contracts are limited to named external consumers.

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

**Collector marker.** A registered collector that does not implement
`ParallelSafeCollectorInterface` is refused, like a collector class that does not
exist. Skipping it instead made `--workers=N` measure less than `--workers=0`
with nothing said anywhere a caller looks: the metrics were absent, the rules
reading them reported nothing, coverage stayed complete and the exit code stayed
normal.
