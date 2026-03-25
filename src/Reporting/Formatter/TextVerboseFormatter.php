<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;

/**
 * @deprecated Use --format=text --detail instead.
 *
 * Delegates to TextFormatter with detail mode enabled.
 * Kept for backward compatibility — will be removed in a future major version.
 */
final class TextVerboseFormatter implements FormatterInterface
{
    public function __construct(
        private readonly TextFormatter $textFormatter,
    ) {}

    public function format(Report $report, FormatterContext $context): string
    {
        return $this->textFormatter->format($report, $context->withDetail(true));
    }

    public function getName(): string
    {
        return 'text-verbose';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::File;
    }
}
