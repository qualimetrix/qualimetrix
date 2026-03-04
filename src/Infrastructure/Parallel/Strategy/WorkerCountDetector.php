<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Parallel\Strategy;

/**
 * Detects the optimal number of worker processes.
 *
 * Auto-detects CPU core count on different platforms.
 */
final class WorkerCountDetector
{
    private const FALLBACK_WORKERS = 4;

    /**
     * Detects the number of CPU cores available.
     *
     * Supports Linux, macOS, and Windows platforms.
     * Returns fallback value if detection fails.
     */
    public function detect(): int
    {
        // Try Windows NUMBER_OF_PROCESSORS environment variable
        $windowsCores = getenv('NUMBER_OF_PROCESSORS');
        if ($windowsCores !== false && is_numeric($windowsCores) && (int) $windowsCores > 0) {
            return (int) $windowsCores;
        }

        // Try Linux /proc/cpuinfo
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                preg_match_all('/^processor/m', $cpuinfo, $matches);
                $count = \count($matches[0]);
                if ($count > 0) {
                    return $count;
                }
            }
        }

        // Try macOS sysctl
        $sysctl = $this->executeCommand('sysctl -n hw.ncpu 2>/dev/null');
        if ($sysctl !== null && $sysctl > 0) {
            return $sysctl;
        }

        // Try nproc (GNU/Linux)
        $nproc = $this->executeCommand('nproc 2>/dev/null');
        if ($nproc !== null && $nproc > 0) {
            return $nproc;
        }

        // Fallback
        return self::FALLBACK_WORKERS;
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
