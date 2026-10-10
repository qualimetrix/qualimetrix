<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;

/**
 * @qmx-threshold coupling.cbo 21 -- Formatters and publication composition share one provider-owned rendering promise; moving it distributes the same signature dependencies.
 */
interface FormatterInterface
{
    /**
     * Formats the report body and its publication diagnostics.
     */
    public function format(Report $report, FormatterContext $context): FormattedReport;

    public function publicationKind(): PublicationKind;

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
