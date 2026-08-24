<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Reporting\Formatter\Support\AcceptedLevelNarrator;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;
use XMLWriter;

/**
 * Formats report as Checkstyle XML output.
 *
 * Compatible with Checkstyle XML format for CI systems
 * (Jenkins, GitLab, GitHub Actions, etc.).
 */
final class CheckstyleFormatter implements FormatterInterface
{
    private const VERSION = '3.0';

    public function format(Report $report, FormatterContext $context): string
    {
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('checkstyle');
        $xml->writeAttribute('version', self::VERSION);

        $this->writeFiles($xml, $report->findings, $context);

        if ($report->coverage !== null && !$report->coverage->isComplete()) {
            $xml->startElement('file');
            $xml->writeAttribute('name', '[analysis]');
            foreach ($report->coverage->failures as $failure) {
                $xml->startElement('error');
                $xml->writeAttribute('line', '1');
                $xml->writeAttribute('severity', 'error');
                $xml->writeAttribute('message', \sprintf('%s: %s', $failure->path, $failure->message));
                $xml->writeAttribute('source', 'qmx.analysis.' . $failure->kind);
                $xml->endElement();
            }
            $xml->endElement();
        }

        $xml->endElement(); // checkstyle
        $xml->endDocument();

        return $xml->outputMemory();
    }

    public function getName(): string
    {
        return 'checkstyle';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::None;
    }

    /**
     * Groups findings by file and writes <file> elements.
     *
     * @param list<Finding> $findings
     */
    private function writeFiles(XMLWriter $xml, array $findings, FormatterContext $context): void
    {
        /** @var array<string, list<Finding>> $grouped */
        $grouped = [];

        foreach ($findings as $finding) {
            $file = $finding->location->file === null
                ? '[project]'
                : $context->relativizePath($finding->location->file);
            $grouped[$file] ??= [];
            $grouped[$file][] = $finding;
        }

        foreach ($grouped as $file => $fileFindings) {
            $xml->startElement('file');
            $xml->writeAttribute('name', $file);

            foreach ($fileFindings as $finding) {
                $this->writeError($xml, $finding);
            }

            $xml->endElement(); // file
        }
    }

    /**
     * Writes a single <error> element for a finding.
     *
     * Checkstyle XML has no field for the accepted level (the schema is
     * fixed to `line`/`severity`/`message`/`source`), so a measured breach
     * (ADR 0017) carries it appended to `message` — the only free-text
     * attribute Checkstyle consumers already surface.
     */
    private function writeError(XMLWriter $xml, Finding $finding): void
    {
        $xml->startElement('error');

        $xml->writeAttribute('line', (string) ($finding->location->line ?? 1));

        $xml->writeAttribute('severity', $this->severityToString($finding->severity));
        $xml->writeAttribute('message', $finding->message . $this->formatBreachSuffix($finding));
        $xml->writeAttribute('source', 'qmx.' . $finding->code);

        $xml->endElement(); // error
    }

    /**
     * " (accepted at 25, now 31)" on a measured breach, '' otherwise (ADR 0017).
     */
    private function formatBreachSuffix(Finding $finding): string
    {
        $breach = AcceptedLevelNarrator::describe($finding);

        return $breach === null ? '' : \sprintf(' (%s)', $breach);
    }

    private function severityToString(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'error',
            Severity::Warning => 'warning',
            Severity::Info => 'info',
        };
    }
}
