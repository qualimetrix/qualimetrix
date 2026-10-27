<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Terminal\ReportPublication;

use Qualimetrix\Reporting\Publication\FormattedReport;

/** A rendered Check answer awaiting profile and report publication. */
final readonly class RenderedCheckReport
{
    /** @param list<string> $warnings plain-text run warnings for the error stream, published before the report */
    public function __construct(
        public FormattedReport $formatted,
        public string $format,
        public int $exitCode,
        public array $warnings,
    ) {}
}
