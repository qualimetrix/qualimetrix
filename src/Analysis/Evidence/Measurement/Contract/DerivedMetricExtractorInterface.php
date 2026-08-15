<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use Qualimetrix\Core\Path\RelativePath;

interface DerivedMetricExtractorInterface
{
    /**
     * @param list<CallableWithMetrics> $callables
     * @param list<ClassWithMetrics> $classes
     */
    public function extract(
        MetricRepositoryInterface $repository,
        MetricBag $fileBag,
        array $callables,
        RelativePath $filePath,
        array $classes = [],
    ): void;
}
