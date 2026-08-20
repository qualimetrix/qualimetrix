<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * Value object representing a class with its collected metrics.
 *
 * Used by collectors to provide class-level metrics for registration in repository.
 * This DTO bridges Symbol and Metric domains.
 *
 * The two integers are not interchangeable: `startFilePos` is the AST position
 * this declaration was collected at, matched against real node positions
 * downstream, while `line` is presentation only.
 */
final readonly class ClassWithMetrics
{
    public function __construct(
        public DeclarationPath $declarationPath,
        public int $startFilePos,
        public int $line,
        public MetricBag $metrics,
    ) {
        $this->subject = MetricSubject::declaration($declarationPath);
    }

    public MetricSubject $subject;
}
