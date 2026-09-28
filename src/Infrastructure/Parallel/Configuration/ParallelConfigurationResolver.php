<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfiguration;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationResolverInterface;

final class ParallelConfigurationResolver implements ParallelConfigurationResolverInterface
{
    public function resolve(ConfigurationDocument $document): ParallelConfiguration
    {
        $value = $document->resolved()->get(ConfigurationRoot::Parallel->value, 'workers');
        if ($value === null) {
            return new ParallelConfiguration();
        }

        $workers = $value->plain();
        if ($workers !== null && (!\is_int($workers) || $workers < 0)) {
            $value->refuse('parallel.workers must be a non-negative integer.');
        }

        return new ParallelConfiguration($workers);
    }
}
