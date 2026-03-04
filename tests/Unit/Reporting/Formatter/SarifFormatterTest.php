<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Formatter;

use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Formatter\SarifFormatter;
use AiMessDetector\Reporting\ReportBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SarifFormatter::class)]
final class SarifFormatterTest extends TestCase
{
    private SarifFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new SarifFormatter();
    }

    public function testGetNameReturnsSarif(): void
    {
        self::assertSame('sarif', $this->formatter->getName());
    }

    public function testFormatReturnsValidJson(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.5)
            ->build();

        $output = $this->formatter->format($report);

        self::assertJson($output);
    }

    public function testFormatEmptyReport(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(42)
            ->filesSkipped(0)
            ->duration(0.15)
            ->build();

        $output = $this->formatter->format($report);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Verify SARIF structure
        self::assertSame('https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json', $data['$schema']);
        self::assertSame('2.1.0', $data['version']);
        self::assertArrayHasKey('runs', $data);
        self::assertCount(1, $data['runs']);

        // Verify tool information
        $run = $data['runs'][0];
        self::assertArrayHasKey('tool', $run);
        self::assertSame('AI Mess Detector', $run['tool']['driver']['name']);
        self::assertSame('0.1.0', $run['tool']['driver']['version']);
        self::assertSame('https://github.com/FractalizeR/php_ai_mess_detector', $run['tool']['driver']['informationUri']);

        // Empty report should have no rules and no results
        self::assertSame([], $run['tool']['driver']['rules']);
        self::assertSame([], $run['results']);
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
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $run = $data['runs'][0];

        // Should have 1 unique rule (both violations use same rule)
        self::assertCount(1, $run['tool']['driver']['rules']);
        $rule = $run['tool']['driver']['rules'][0];
        self::assertSame('cyclomatic-complexity', $rule['id']);
        self::assertSame('Cyclomatic Complexity', $rule['name']);
        self::assertSame('Code complexity exceeds threshold', $rule['shortDescription']['text']);
        self::assertSame('warning', $rule['defaultConfiguration']['level']);

        // Should have 2 results
        self::assertCount(2, $run['results']);

        // First violation
        $result1 = $run['results'][0];
        self::assertSame('cyclomatic-complexity', $result1['ruleId']);
        self::assertSame('error', $result1['level']);
        self::assertSame('Cyclomatic complexity of 25 exceeds threshold', $result1['message']['text']);
        self::assertSame('src/Service/UserService.php', $result1['locations'][0]['physicalLocation']['artifactLocation']['uri']);
        self::assertSame('%SRCROOT%', $result1['locations'][0]['physicalLocation']['artifactLocation']['uriBaseId']);
        self::assertSame(42, $result1['locations'][0]['physicalLocation']['region']['startLine']);
        self::assertSame(1, $result1['locations'][0]['physicalLocation']['region']['startColumn']);

        // Second violation
        $result2 = $run['results'][1];
        self::assertSame('cyclomatic-complexity', $result2['ruleId']);
        self::assertSame('warning', $result2['level']);
        self::assertSame('Cyclomatic complexity of 12 exceeds threshold', $result2['message']['text']);
        self::assertSame(120, $result2['locations'][0]['physicalLocation']['region']['startLine']);
    }

    public function testFormatMultipleRules(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'cyclomatic-complexity',
                message: 'Complexity too high',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'class-size',
                message: 'Class too large',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('src/C.php', 30),
                symbolPath: SymbolPath::forClass('App', 'C'),
                ruleName: 'maintainability-index',
                message: 'Low maintainability',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(3)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $run = $data['runs'][0];

        // Should have 3 unique rules
        self::assertCount(3, $run['tool']['driver']['rules']);

        $ruleIds = array_map(fn(array $r): string => $r['id'], $run['tool']['driver']['rules']);
        self::assertContains('cyclomatic-complexity', $ruleIds);
        self::assertContains('class-size', $ruleIds);
        self::assertContains('maintainability-index', $ruleIds);

        // Check rule names are formatted correctly
        $ruleNames = array_map(fn(array $r): string => $r['name'], $run['tool']['driver']['rules']);
        self::assertContains('Cyclomatic Complexity', $ruleNames);
        self::assertContains('Class Size', $ruleNames);
        self::assertContains('Maintainability Index', $ruleNames);

        // Should have 3 results
        self::assertCount(3, $run['results']);
    }

    public function testFormatNamespaceLevelViolation(): void
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
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $result = $data['runs'][0]['results'][0];
        // Namespace violations without line should default to line 1
        self::assertSame(1, $result['locations'][0]['physicalLocation']['region']['startLine']);
    }

    public function testMapsSeverityCorrectly(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'test',
                message: 'Error violation',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                message: 'Warning violation',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $results = $data['runs'][0]['results'];

        // Verify severity mapping
        self::assertSame('error', $results[0]['level']);
        self::assertSame('warning', $results[1]['level']);
    }

    public function testRuleDescriptions(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'lcom',
                message: 'LCOM too high',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'inheritance-depth',
                message: 'Inheritance too deep',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $rules = $data['runs'][0]['tool']['driver']['rules'];

        // Find specific rules
        $lcomRule = null;
        $inheritanceRule = null;
        foreach ($rules as $rule) {
            if ($rule['id'] === 'lcom') {
                $lcomRule = $rule;
            }
            if ($rule['id'] === 'inheritance-depth') {
                $inheritanceRule = $rule;
            }
        }

        self::assertNotNull($lcomRule);
        self::assertNotNull($inheritanceRule);
        self::assertSame('Lack of cohesion of methods exceeds threshold', $lcomRule['shortDescription']['text']);
        self::assertSame('Inheritance depth exceeds threshold', $inheritanceRule['shortDescription']['text']);
    }
}
