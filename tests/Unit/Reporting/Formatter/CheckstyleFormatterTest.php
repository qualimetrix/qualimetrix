<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Formatter;

use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Formatter\CheckstyleFormatter;
use AiMessDetector\Reporting\Report;
use AiMessDetector\Reporting\ReportBuilder;
use DOMDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckstyleFormatter::class)]
final class CheckstyleFormatterTest extends TestCase
{
    private CheckstyleFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new CheckstyleFormatter();
    }

    public function testGetNameReturnsCheckstyle(): void
    {
        self::assertSame('checkstyle', $this->formatter->getName());
    }

    public function testFormatReturnsValidXml(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.5)
            ->build();

        $output = $this->formatter->format($report);

        $xml = new DOMDocument();
        $loaded = $xml->loadXML($output);

        self::assertTrue($loaded, 'Output should be valid XML');
    }

    public function testFormatEmptyReport(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(42)
            ->filesSkipped(0)
            ->duration(0.15)
            ->build();

        $output = $this->formatter->format($report);
        $xml = $this->parseXml($output);

        $checkstyle = $xml->getElementsByTagName('checkstyle')->item(0);
        self::assertNotNull($checkstyle);
        self::assertSame('3.0', $checkstyle->getAttribute('version'));

        // No file elements for empty report
        self::assertSame(0, $xml->getElementsByTagName('file')->length);
    }

    public function testFormatReportWithViolations(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculateDiscount'),
                ruleName: 'cyclomatic-complexity',
                message: 'Cyclomatic complexity of 25 exceeds threshold',
                severity: Severity::Error,
                metricValue: 25,
            ))
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 120),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'processOrder'),
                ruleName: 'cyclomatic-complexity',
                message: 'Cyclomatic complexity of 12 exceeds threshold',
                severity: Severity::Warning,
                metricValue: 12,
            ))
            ->filesAnalyzed(42)
            ->filesSkipped(1)
            ->duration(0.23)
            ->build();

        $output = $this->formatter->format($report);
        $xml = $this->parseXml($output);

        $files = $xml->getElementsByTagName('file');
        self::assertSame(1, $files->length);

        $file = $files->item(0);
        self::assertNotNull($file);
        self::assertSame('src/Service/UserService.php', $file->getAttribute('name'));

        $errors = $file->getElementsByTagName('error');
        self::assertSame(2, $errors->length);

        // First error
        $error1 = $errors->item(0);
        self::assertNotNull($error1);
        self::assertSame('42', $error1->getAttribute('line'));
        self::assertSame('error', $error1->getAttribute('severity'));
        self::assertSame('Cyclomatic complexity of 25 exceeds threshold', $error1->getAttribute('message'));
        self::assertSame('aimd.cyclomatic-complexity', $error1->getAttribute('source'));

        // Second error
        $error2 = $errors->item(1);
        self::assertNotNull($error2);
        self::assertSame('120', $error2->getAttribute('line'));
        self::assertSame('warning', $error2->getAttribute('severity'));
    }

    public function testFormatGroupsViolationsByFile(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                message: 'Error in A',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                message: 'Error in B',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('src/A.php', 30),
                symbolPath: SymbolPath::forClass('App', 'A2'),
                ruleName: 'test',
                message: 'Second error in A',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report);
        $xml = $this->parseXml($output);

        $files = $xml->getElementsByTagName('file');
        self::assertSame(2, $files->length);

        // Find file A and count errors
        $fileAErrors = 0;
        $fileBErrors = 0;
        foreach ($files as $file) {
            $name = $file->getAttribute('name');
            $errorCount = $file->getElementsByTagName('error')->length;
            if ($name === 'src/A.php') {
                $fileAErrors = $errorCount;
            } elseif ($name === 'src/B.php') {
                $fileBErrors = $errorCount;
            }
        }

        self::assertSame(2, $fileAErrors);
        self::assertSame(1, $fileBErrors);
    }

    public function testFormatNamespaceLevelViolationWithoutLine(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php'),
                symbolPath: SymbolPath::forNamespace('App\Service'),
                ruleName: 'namespace-size',
                message: 'Namespace contains 16 classes (threshold: 10)',
                severity: Severity::Error,
                metricValue: 16,
            ))
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report);
        $xml = $this->parseXml($output);

        $error = $xml->getElementsByTagName('error')->item(0);
        self::assertNotNull($error);

        // No line attribute when location has no line
        self::assertFalse($error->hasAttribute('line'));
        self::assertSame('aimd.namespace-size', $error->getAttribute('source'));
    }

    public function testFormatEscapesXmlSpecialCharacters(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Test.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Test'),
                ruleName: 'test-rule',
                message: 'Message with <special> & "characters"',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report);

        // Should produce valid XML (the parser will throw if invalid)
        $xml = $this->parseXml($output);

        $error = $xml->getElementsByTagName('error')->item(0);
        self::assertNotNull($error);
        self::assertSame('Message with <special> & "characters"', $error->getAttribute('message'));
    }

    public function testXmlDeclarationIsPresent(): void
    {
        $report = new Report([], 0, 0, 0.0, 0, 0);
        $output = $this->formatter->format($report);

        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $output);
    }

    private function parseXml(string $output): DOMDocument
    {
        $xml = new DOMDocument();
        $loaded = $xml->loadXML($output);

        if (!$loaded) {
            self::fail('Failed to parse XML output');
        }

        return $xml;
    }
}
