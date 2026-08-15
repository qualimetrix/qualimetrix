<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Configuration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfiguration;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationResolverInterface;

final class ParallelConfigurationResolver implements ParallelConfigurationResolverInterface
{
    public function resolve(ConfigurationDocument $document): ParallelConfiguration
    {
        $workers = null;
        foreach ($document->contributions(ConfigSchema::PARALLEL_WORKERS) as $candidate) {
            $workers = $candidate;
        }
        if ($workers !== null && (!\is_int($workers) || $workers < 0)) {
            throw new InvalidArgumentException('parallel.workers must be a non-negative integer.');
        }

        return new ParallelConfiguration($workers);
    }
}
