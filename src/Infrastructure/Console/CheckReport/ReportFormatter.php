<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckReport;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Infrastructure\Console\Terminal\ErrorStream;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\Formatter\FormattedReport;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\Formatter\Prose\ProseText;
use Qualimetrix\Reporting\Formatter\PublicationKind;
use Qualimetrix\Reporting\Formatter\PublishedUtf8;
use Qualimetrix\Reporting\Report;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
