<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter;

use AiMessDetector\Reporting\Report;

interface FormatterInterface
{
    /**
     * Formats the report to a string for output.
     */
    public function format(Report $report): string;

    /**
     * Returns unique formatter name (used in --format=NAME).
     */
    public function getName(): string;
}
