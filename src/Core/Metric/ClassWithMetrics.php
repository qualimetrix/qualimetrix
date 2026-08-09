<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Metric;

use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * Value object representing a class with its collected metrics.
 *
 * Used by collectors to provide class-level metrics for registration in repository.
 * This DTO bridges Symbol and Metric domains.
 */
final readonly class ClassWithMetrics
{
    public function __construct(
        public DeclarationPath $declarationPath,
        public int $line,
        public MetricBag $metrics,
    ) {
        $this->subject = MetricSubject::declaration($declarationPath);
    }

    public MetricSubject $subject;
}
