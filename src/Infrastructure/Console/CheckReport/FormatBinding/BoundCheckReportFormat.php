<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckReport\FormatBinding;

use Qualimetrix\Reporting\Contract\Formatting\FormatterContext;
use Qualimetrix\Reporting\Contract\Formatting\FormatterInterface;

final readonly class BoundCheckReportFormat
{
    public function __construct(public FormatterInterface $formatter, public FormatterContext $context) {}
}
