<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Strategy;

/** Whole-CPU limit inherited from the process's mounted control-group hierarchy. */
final readonly class CgroupCpuQuota
{
    public function __construct(private string $systemRoot) {}

    public function detect(): ?int
    {
        $location = new CgroupLocation($this->systemRoot);
        [$readable, $quotas] = $this->v2Quotas($location->ancestors(''));

        // A readable v2 answer remains authoritative when unlimited or malformed.
        if (!$readable) {
            $quotas = $this->v1Quotas($location->ancestors('cpu'));
        }

        return $quotas === [] ? null : min($quotas);
    }

    /**
     * @param list<string> $ancestors
     *
     * @return array{bool, list<int>}
     */
    private function v2Quotas(array $ancestors): array
    {
        $readable = false;
        $quotas = [];
        foreach ($ancestors as $directory) {
            $line = $this->read($directory . '/cpu.max');
            if ($line === null) {
                continue;
            }
            $readable = true;
            $parts = preg_split('/\s+/', trim($line));
            $quota = self::wholeCpus($parts[0] ?? '', $parts[1] ?? '');
            if ($quota !== null) {
                $quotas[] = $quota;
            }
        }

        return [$readable, $quotas];
    }

    /** @param list<string> $ancestors
     * @return list<int>
     */
    private function v1Quotas(array $ancestors): array
    {
        $quotas = [];
        foreach ($ancestors as $directory) {
            $quota = self::wholeCpus(
                trim($this->read($directory . '/cpu.cfs_quota_us') ?? ''),
                trim($this->read($directory . '/cpu.cfs_period_us') ?? ''),
            );
            if ($quota !== null) {
                $quotas[] = $quota;
            }
        }

        return $quotas;
    }

    private static function wholeCpus(string $quota, string $period): ?int
    {
        if (!is_numeric($quota) || !is_numeric($period) || (float) $quota <= 0 || (float) $period <= 0) {
            return null;
        }

        return max(1, (int) ceil((float) $quota / (float) $period));
    }

    private function read(string $path): ?string
    {
        $contents = @file_get_contents($this->systemRoot . $path);

        return $contents === false ? null : $contents;
    }
}
