<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Strategy;

/**
 * Detects the optimal number of worker processes.
 *
 * Auto-detects CPU core count on different platforms, then caps it by the CPU
 * quota of the control group the process runs in. The processor count is a
 * fact about the host; in the product's documented main setting — a container
 * in CI — it can be an order of magnitude more than the process may use, and
 * every worker is a separate process with its own parser and its own cache.
 */
final class WorkerCountDetector
{
    private const FALLBACK_WORKERS = 4;

    /**
     * @param string $systemRoot Prefix for the kernel's own files, so a test can
     *                           plant a control group instead of describing one
     */
    public function __construct(private readonly string $systemRoot = '') {}

    /**
     * Detects the number of CPU cores available to this process.
     *
     * Supports Linux, macOS, and Windows platforms.
     * Returns fallback value if detection fails.
     */
    public function detect(): int
    {
        $processors = $this->detectProcessors();
        $quota = $this->cgroupCpuQuota();

        if ($quota === null) {
            return $processors;
        }

        return max(1, min($processors, $quota));
    }

    /**
     * Cheapest probe first, and each one answers null rather than a guess —
     * so the fallback stands for "nothing on this host could say", not for
     * "the host says zero".
     */
    private function detectProcessors(): int
    {
        return $this->processorsFromEnvironment()
            ?? $this->processorsFromCpuinfo()
            ?? $this->processorsFromShell()
            ?? self::FALLBACK_WORKERS;
    }

    /** Windows states the count in the environment. */
    private function processorsFromEnvironment(): ?int
    {
        $windowsCores = getenv('NUMBER_OF_PROCESSORS');

        if ($windowsCores === false || !is_numeric($windowsCores)) {
            return null;
        }

        return (int) $windowsCores > 0 ? (int) $windowsCores : null;
    }

    /** Linux lists one `processor` stanza per logical CPU. */
    private function processorsFromCpuinfo(): ?int
    {
        $cpuinfoPath = $this->systemRoot . '/proc/cpuinfo';

        if (!is_readable($cpuinfoPath)) {
            return null;
        }

        $cpuinfo = file_get_contents($cpuinfoPath);

        if ($cpuinfo === false) {
            return null;
        }

        preg_match_all('/^processor/m', $cpuinfo, $matches);
        $count = \count($matches[0]);

        return $count > 0 ? $count : null;
    }

    /**
     * Asked only of the real host: a planted system root describes files, not
     * a machine, and a command would answer about the machine running the test.
     */
    private function processorsFromShell(): ?int
    {
        if ($this->systemRoot !== '') {
            return null;
        }

        return $this->executeCommand('sysctl -n hw.ncpu 2>/dev/null')
            ?? $this->executeCommand('nproc 2>/dev/null');
    }

    /**
     * Whole CPUs this process's control group may use, or null when there is
     * no group, no quota, or nothing readable to say so.
     *
     * Absence is "no limit", never an error: outside Linux none of these files
     * exists, and a quota below one CPU still permits one worker.
     */
    private function cgroupCpuQuota(): ?int
    {
        $cpuMax = $this->readFirstLine($this->systemRoot . '/sys/fs/cgroup/cpu.max');

        // Not a fallback chain: once cpu.max is readable it is the whole
        // answer, including when what it says is "no limit" or nothing this
        // can parse. The v1 files are never consulted behind it.
        if ($cpuMax !== null) {
            return $this->quotaFromCpuMax($cpuMax);
        }

        return $this->quotaFromCfsPair();
    }

    /** cgroup v2: "<quota> <period>", or "max <period>" when unlimited. */
    private function quotaFromCpuMax(string $line): ?int
    {
        $parts = preg_split('/\s+/', $line);
        $parts = $parts === false ? [] : $parts;
        $quota = $parts[0] ?? '';

        if ($quota === 'max' || !is_numeric($quota)) {
            return null;
        }

        return self::wholeCpus((float) $quota, (int) ($parts[1] ?? '0'));
    }

    /** cgroup v1: quota of -1 (or 0) means unlimited. */
    private function quotaFromCfsPair(): ?int
    {
        $quotaLine = $this->readFirstLine($this->systemRoot . '/sys/fs/cgroup/cpu/cpu.cfs_quota_us');
        $periodLine = $this->readFirstLine($this->systemRoot . '/sys/fs/cgroup/cpu/cpu.cfs_period_us');

        if ($quotaLine === null || $periodLine === null) {
            return null;
        }

        return self::wholeCpus((float) $quotaLine, (int) $periodLine);
    }

    /** Rounded up, because a fraction of a CPU is still a CPU a worker runs on. */
    private static function wholeCpus(float $quota, int $period): ?int
    {
        if ($quota <= 0 || $period <= 0) {
            return null;
        }

        return (int) ceil($quota / $period);
    }

    private function readFirstLine(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $token = strtok($contents, "\n");
        $line = trim($token === false ? '' : $token);

        return $line === '' ? null : $line;
    }

    /**
     * Executes a shell command and returns integer result.
     */
    private function executeCommand(string $command): ?int
    {
        $output = shell_exec($command);

        if ($output === null || $output === false) {
            return null;
        }

        $value = (int) trim($output);

        return $value > 0 ? $value : null;
    }
}
