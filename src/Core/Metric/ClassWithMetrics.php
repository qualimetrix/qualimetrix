<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Metric;

use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Value object representing a class with its collected metrics.
 *
 * Used by collectors to provide class-level metrics for registration in repository.
 * This DTO bridges Symbol and Metric domains.
 */
final readonly class ClassWithMetrics
{
    public function __construct(
        public ?string $namespace,
        public string $class,
        public int $line,
        public MetricBag $metrics,
    ) {}

    /**
     * Creates SymbolPath for this class.
     */
    public function getSymbolPath(): SymbolPath
    {
        return SymbolPath::forClass($this->namespace ?? '', $this->class);
    }
}
