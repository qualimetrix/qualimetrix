<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Reporting\Formatter\FormattedReport;

/** A rendered Check answer awaiting profile and report publication. */
final readonly class RenderedCheckReport
{
    public function __construct(
        public FormattedReport $formatted,
        public string $format,
        public int $exitCode,
    ) {}
}
