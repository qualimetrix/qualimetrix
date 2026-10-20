<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckReport\FormatBinding;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Infrastructure\Console\Terminal\Diagnostics\ErrorStream;
use Qualimetrix\Reporting\Contract\Formatting\FormatterRegistryInterface;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\Contract\Report\Report;
use Qualimetrix\Reporting\Formatter\Prose\ProseText;
use Qualimetrix\Reporting\Publication\FormattedReport;
use Qualimetrix\Reporting\Publication\PublicationKind;
use Qualimetrix\Reporting\Publication\PublishedUtf8;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @qmx-threshold coupling.instability 0.875001 -- This subject implements an owner-declared outward protocol with intentionally few incoming readers; the finite boundary preserves visibility of additional dependencies.
 * @qmx-threshold health.cohesion 40.0 -- Independent immutable observations or stateless declaration operations intentionally have no shared instance-field graph; this finite class boundary keeps a further cohesion loss visible.
 */
final readonly class ReportFormatter
{
    public function __construct(private FormatterRegistryInterface $formatterRegistry, private FormatterContextFactory $formatterContextFactory, private ErrorStream $errorStream) {}

    public function context(InputInterface $input, OutputInterface $output, AbsolutePath $root, OutputFormat $format, bool $scoped, ?NamespacePattern $namespace): BoundCheckReportFormat
    {
        $formatter = $this->formatterRegistry->get($format->value);
        return new BoundCheckReportFormat($formatter, $this->formatterContextFactory->create($input, $output, $formatter, $root, $scoped, $namespace));
    }

    public function format(Report $report, BoundCheckReportFormat $bound, OutputInterface $output): FormattedReport
    {
        $formatter = $bound->formatter;
        $formatted = $formatter->format($report, $bound->context);
        if ($formatter->publicationKind() === PublicationKind::Prose) {
            $published = ProseText::publish($formatted->body, $this->errorStream->glyphMode());
            $formatted = new FormattedReport($published->body, $formatted->escapedStrings + $published->escapedStrings);
        }
        if ($formatted->escapedStrings > 0) {
            $this->errorStream->write($output, PublishedUtf8::describe($formatted->escapedStrings));
        }
        return $formatted;
    }
}
