<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter;

use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Report;
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

    public function format(Report $report): string
    {
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('checkstyle');
        $xml->writeAttribute('version', self::VERSION);

        $this->writeFiles($xml, $report->violations);

        $xml->endElement(); // checkstyle
        $xml->endDocument();

        return $xml->outputMemory();
    }

    public function getName(): string
    {
        return 'checkstyle';
    }

    /**
     * Groups violations by file and writes <file> elements.
     *
     * @param list<Violation> $violations
     */
    private function writeFiles(XMLWriter $xml, array $violations): void
    {
        /** @var array<string, list<Violation>> $grouped */
        $grouped = [];

        foreach ($violations as $violation) {
            $file = $violation->location->file;
            $grouped[$file] ??= [];
            $grouped[$file][] = $violation;
        }

        foreach ($grouped as $file => $fileViolations) {
            $xml->startElement('file');
            $xml->writeAttribute('name', $file);

            foreach ($fileViolations as $violation) {
                $this->writeError($xml, $violation);
            }

            $xml->endElement(); // file
        }
    }

    /**
     * Writes a single <error> element for a violation.
     */
    private function writeError(XMLWriter $xml, Violation $violation): void
    {
        $xml->startElement('error');

        if ($violation->location->line !== null) {
            $xml->writeAttribute('line', (string) $violation->location->line);
        }

        $xml->writeAttribute('severity', $this->severityToString($violation->severity));
        $xml->writeAttribute('message', $violation->message);
        $xml->writeAttribute('source', 'aimd.' . $violation->ruleName);

        $xml->endElement(); // error
    }

    private function severityToString(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'error',
            Severity::Warning => 'warning',
        };
    }
}
