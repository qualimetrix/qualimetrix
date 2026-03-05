<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter;

use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Report;

interface FormatterInterface
{
    /**
     * Formats the report to a string for output.
     */
    public function format(Report $report, FormatterContext $context): string;

    /**
     * Returns unique formatter name (used in --format=NAME).
     */
    public function getName(): string;

    /**
     * Returns the default grouping mode for this formatter.
     *
     * Used when --group-by is not explicitly specified.
     */
    public function getDefaultGroupBy(): GroupBy;
}
