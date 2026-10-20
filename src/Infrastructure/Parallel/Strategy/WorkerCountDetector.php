<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Strategy;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;

/** Detects processors available to the process, including inherited cgroup quotas. */
final class WorkerCountDetector
{
    private const FALLBACK_WORKERS = 4;

    /** @param array<string, string>|null $environment */
    public function __construct(
        private readonly string $systemRoot = '',
        private readonly string $osFamily = \PHP_OS_FAMILY,
        private readonly ?array $environment = null,
    ) {}

    public function detect(): int
    {
        $processors = $this->processorsFromEnvironment()
            ?? $this->processorsFromCpuinfo()
            ?? $this->processorsFromShell()
            ?? self::FALLBACK_WORKERS;
        $allowed = $this->allowedProcessors();
        $quota = (new CgroupCpuQuota($this->systemRoot))->detect();

        return max(1, min($processors, $allowed ?? $processors, $quota ?? $processors));
    }

    private function processorsFromEnvironment(): ?int
    {
        if ($this->osFamily !== 'Windows') {
            return null;
        }
        $value = $this->environment === null
            ? getenv('NUMBER_OF_PROCESSORS')
            : ($this->environment['NUMBER_OF_PROCESSORS'] ?? false);
        if ($value === false) {
            return null;
        }
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1 || (string) (int) $value !== $value) {
            throw ConfigurationRefusal::aboutInput(
                ConfigurationOrigin::of(ConfigurationSource::Environment, 'NUMBER_OF_PROCESSORS'),
                'NUMBER_OF_PROCESSORS must be a positive decimal integer; received ' . $value . '.',
            );
        }

        return (int) $value;
    }

    private function processorsFromCpuinfo(): ?int
    {
        $text = $this->read('/proc/cpuinfo');
        preg_match_all('/^processor\s*:/m', $text ?? '', $matches);
        $count = \count($matches[0]);

        return $count > 0 ? $count : null;
    }

    private function allowedProcessors(): ?int
    {
        if (preg_match('/^Cpus_allowed_list:\s*([^\n]+)$/m', $this->read('/proc/self/status') ?? '', $match) !== 1) {
            return null;
        }
        $ranges = [];
        foreach (explode(',', trim($match[1])) as $range) {
            if (preg_match('/^(\d+)(?:-(\d+))?$/D', $range, $parts) !== 1) {
                return null;
            }
            $first = (int) $parts[1];
            $last = (int) ($parts[2] ?? $parts[1]);
            if ($last < $first) {
                return null;
            }
            $ranges[] = [$first, $last];
        }
        sort($ranges);
        $count = 0;
        $end = -1;
        foreach ($ranges as [$first, $last]) {
            $count += max(0, $last - max($first, $end + 1) + 1);
            $end = max($end, $last);
        }

        return $count > 0 ? $count : null;
    }

    private function processorsFromShell(): ?int
    {
        if ($this->systemRoot !== '') {
            return null;
        }

        return $this->executeCommand('sysctl -n hw.ncpu 2>/dev/null')
            ?? $this->executeCommand('nproc 2>/dev/null');
    }

    private function read(string $path): ?string
    {
        $contents = @file_get_contents($this->systemRoot . $path);

        return $contents === false ? null : $contents;
    }

    private function executeCommand(string $command): ?int
    {
        if (!\function_exists('shell_exec') || \in_array('shell_exec', array_map('trim', explode(',', (string) \ini_get('disable_functions'))), true)) {
            return null;
        }
        $output = shell_exec($command);
        if (!\is_string($output)) {
            return null;
        }
        $value = (int) trim($output);

        return $value > 0 ? $value : null;
    }
}
