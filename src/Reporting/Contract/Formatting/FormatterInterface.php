<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Contract\Formatting;

use Qualimetrix\Reporting\Contract\Report\Report;
use Qualimetrix\Reporting\Publication\FormattedReport;

use Qualimetrix\Reporting\Publication\PublicationKind;

/**
 * @qmx-threshold coupling.cbo 22 -- Formatters and publication composition share one provider-owned rendering promise; moving it distributes the same signature dependencies.
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
