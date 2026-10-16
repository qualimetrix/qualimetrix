<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckReport;

use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\FormatterContext;

final readonly class BoundCheckReportFormat
{
    public function __construct(public FormatterInterface $formatter, public FormatterContext $context) {}
}
