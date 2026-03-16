<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Formatter;

use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Formatter\Sarif\SarifFormatter;
use AiMessDetector\Reporting\Formatter\Sarif\SarifRuleCollector;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\ReportBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SarifFormatter::class)]
final class SarifFormatterTest extends TestCase
{
    private SarifFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new SarifFormatter(new SarifRuleCollector());
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

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertJson($output);
    }

    public function testFormatEmptyReport(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(42)
            ->filesSkipped(0)
            ->duration(0.15)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
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
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Cyclomatic complexity of 25 exceeds threshold',
                severity: Severity::Error,
                metricValue: 25,
            ))
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 120),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'processOrder'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Cyclomatic complexity of 12 exceeds threshold',
                severity: Severity::Warning,
                metricValue: 12,
            ))
            ->filesAnalyzed(42)
            ->filesSkipped(1)
            ->duration(0.23)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $run = $data['runs'][0];

        // Should have 1 unique rule (both violations use same rule)
        self::assertCount(1, $run['tool']['driver']['rules']);
        $rule = $run['tool']['driver']['rules'][0];
        self::assertSame('complexity.cyclomatic', $rule['id']);
        self::assertSame('Complexity Cyclomatic', $rule['name']);
        self::assertSame('Code complexity exceeds threshold', $rule['shortDescription']['text']);
        // Max severity is Error, so defaultConfiguration level should be 'error'
        self::assertSame('error', $rule['defaultConfiguration']['level']);

        // Should have 2 results
        self::assertCount(2, $run['results']);

        // First violation
        $result1 = $run['results'][0];
        self::assertSame('complexity.cyclomatic', $result1['ruleId']);
        self::assertSame('error', $result1['level']);
        self::assertSame('Cyclomatic complexity of 25 exceeds threshold', $result1['message']['text']);
        self::assertSame('src/Service/UserService.php', $result1['locations'][0]['physicalLocation']['artifactLocation']['uri']);
        self::assertArrayNotHasKey('uriBaseId', $result1['locations'][0]['physicalLocation']['artifactLocation']);
        self::assertSame(42, $result1['locations'][0]['physicalLocation']['region']['startLine']);
        self::assertSame(1, $result1['locations'][0]['physicalLocation']['region']['startColumn']);

        // Second violation
        $result2 = $run['results'][1];
        self::assertSame('complexity.cyclomatic', $result2['ruleId']);
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
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Complexity too high',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'class-size',
                violationCode: 'class-size',
                message: 'Class too large',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('src/C.php', 30),
                symbolPath: SymbolPath::forClass('App', 'C'),
                ruleName: 'maintainability-index',
                violationCode: 'maintainability-index',
                message: 'Low maintainability',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(3)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $run = $data['runs'][0];

        // Should have 3 unique rules
        self::assertCount(3, $run['tool']['driver']['rules']);

        $ruleIds = array_map(fn(array $r): string => $r['id'], $run['tool']['driver']['rules']);
        self::assertContains('complexity.cyclomatic', $ruleIds);
        self::assertContains('class-size', $ruleIds);
        self::assertContains('maintainability-index', $ruleIds);

        // Check rule names are formatted correctly
        $ruleNames = array_map(fn(array $r): string => $r['name'], $run['tool']['driver']['rules']);
        self::assertContains('Complexity Cyclomatic', $ruleNames);
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
                violationCode: 'namespace-size',
                message: 'Namespace contains 16 classes (threshold: 10)',
                severity: Severity::Error,
                metricValue: 16,
            ))
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
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
                violationCode: 'test',
                message: 'Error violation',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Warning violation',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
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
                ruleName: 'design.lcom',
                violationCode: 'design.lcom',
                message: 'LCOM too high',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'design.inheritance',
                violationCode: 'design.inheritance',
                message: 'Inheritance too deep',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $rules = $data['runs'][0]['tool']['driver']['rules'];

        // Find specific rules
        $lcomRule = null;
        $inheritanceRule = null;
        foreach ($rules as $rule) {
            if ($rule['id'] === 'design.lcom') {
                $lcomRule = $rule;
            }
            if ($rule['id'] === 'design.inheritance') {
                $inheritanceRule = $rule;
            }
        }

        self::assertNotNull($lcomRule);
        self::assertNotNull($inheritanceRule);
        self::assertSame('Lack of cohesion of methods', $lcomRule['shortDescription']['text']);
        self::assertSame('Inheritance structure issue', $inheritanceRule['shortDescription']['text']);
    }

    public function testRuleIdUsesViolationCode(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'complexity',
                violationCode: 'complexity.method',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $run = $data['runs'][0];

        // Result ruleId should use violationCode
        $result = $run['results'][0];
        self::assertSame('complexity.method', $result['ruleId']);

        // Rule in rules array should use violationCode as id
        $rule = $run['tool']['driver']['rules'][0];
        self::assertSame('complexity.method', $rule['id']);
    }

    public function testGetDefaultGroupBy(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    public function testRelativizesAbsolutePathsWithBasePath(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('/home/user/project/src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(basePath: '/home/user/project');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $run = $data['runs'][0];

        // Path should be relativized
        $uri = $run['results'][0]['locations'][0]['physicalLocation']['artifactLocation']['uri'];
        self::assertSame('src/Service/UserService.php', $uri);

        // Should have originalUriBaseIds
        self::assertArrayHasKey('originalUriBaseIds', $run);
        self::assertSame('file:///home/user/project/', $run['originalUriBaseIds']['%SRCROOT%']['uri']);
    }

    public function testWindowsBasePathNormalizedToFileUri(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'test',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(basePath: 'C:\Users\project');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $run = $data['runs'][0];

        self::assertArrayHasKey('originalUriBaseIds', $run);
        self::assertSame('file:///C:/Users/project/', $run['originalUriBaseIds']['%SRCROOT%']['uri']);
    }

    public function testAlreadyRelativePathUnchanged(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(basePath: '/home/user/project');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        // Already-relative path should remain unchanged
        $uri = $data['runs'][0]['results'][0]['locations'][0]['physicalLocation']['artifactLocation']['uri'];
        self::assertSame('src/Service/UserService.php', $uri);
    }

    public function testNoBasePathKeepsAbsolutePaths(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('/home/user/project/src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        // No basePath (default)
        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $run = $data['runs'][0];

        // Absolute path should remain unchanged
        $uri = $run['results'][0]['locations'][0]['physicalLocation']['artifactLocation']['uri'];
        self::assertSame('/home/user/project/src/Service/UserService.php', $uri);

        // No originalUriBaseIds when basePath is empty
        self::assertArrayNotHasKey('originalUriBaseIds', $run);
    }

    public function testProjectLevelViolationOmitsLocations(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: Location::none(),
                symbolPath: SymbolPath::forNamespace('App'),
                ruleName: 'architecture',
                violationCode: 'architecture.circular',
                message: 'Circular dependency detected',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $result = $data['runs'][0]['results'][0];
        // Project-level violations should not have locations key at all
        self::assertArrayNotHasKey('locations', $result);
    }

    public function testUnixPathToFileUriProducesThreeSlashes(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('/home/user/project/src/Service/UserService.php', 42),
                symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(basePath: '/home/user/project');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $run = $data['runs'][0];
        $uri = $run['originalUriBaseIds']['%SRCROOT%']['uri'];

        // Must be file:///home/user/project/ (3 slashes), NOT file:////home/user/project/ (4 slashes)
        self::assertSame('file:///home/user/project/', $uri);
        self::assertStringNotContainsString('file:////', $uri);
    }

    public function testPathWithSpacesIsPercentEncoded(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forClass('App', 'Foo'),
                ruleName: 'test',
                violationCode: 'test',
                message: 'Test',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(basePath: '/Users/dev/My Project');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $run = $data['runs'][0];
        $uri = $run['originalUriBaseIds']['%SRCROOT%']['uri'];

        // Space should be percent-encoded as %20
        self::assertSame('file:///Users/dev/My%20Project/', $uri);
        self::assertStringNotContainsString(' ', $uri);
    }

    public function testRuleHelpUriMapsToDocsCategoryPage(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'code-smell.boolean-argument',
                violationCode: 'code-smell.boolean-argument',
                message: 'Boolean argument',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('src/C.php', 30),
                symbolPath: SymbolPath::forClass('App', 'C'),
                ruleName: 'unknown-rule',
                violationCode: 'unknown-rule',
                message: 'Unknown',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(3)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $rules = $data['runs'][0]['tool']['driver']['rules'];
        $rulesByCode = [];
        foreach ($rules as $rule) {
            $rulesByCode[$rule['id']] = $rule;
        }

        // Known categories map to their docs page
        self::assertSame('https://aimd.dev/rules/complexity/', $rulesByCode['complexity.cyclomatic']['helpUri']);
        self::assertSame('https://aimd.dev/rules/code-smell/', $rulesByCode['code-smell.boolean-argument']['helpUri']);

        // Unknown category falls back to repository URL
        self::assertSame('https://github.com/FractalizeR/php_ai_mess_detector', $rulesByCode['unknown-rule']['helpUri']);
    }

    public function testRuleDescriptionUsesViolationCodeNotRuleName(): void
    {
        // When ruleName differs from violationCode, the description should
        // be looked up by violationCode (which matches the match arms).
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Foo.php', 10),
                symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
                ruleName: 'cyclomatic-complexity',
                violationCode: 'complexity.cyclomatic',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.01)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $rule = $data['runs'][0]['tool']['driver']['rules'][0];
        // Should use the description matching 'complexity.cyclomatic', not 'cyclomatic-complexity'
        self::assertSame('Code complexity exceeds threshold', $rule['shortDescription']['text']);
    }

    public function testRelatedLocationsIncludedInResult(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/Service/UserService.php', 10),
                symbolPath: SymbolPath::forFile('src/Service/UserService.php'),
                ruleName: 'duplication.code-duplication',
                violationCode: 'duplication.code-duplication',
                message: 'Duplicated code block (20 lines, 3 occurrences)',
                severity: Severity::Warning,
                metricValue: 20,
                relatedLocations: [
                    new Location('src/Service/OrderService.php', 42),
                    new Location('src/Service/PaymentService.php', 88),
                ],
            ))
            ->filesAnalyzed(3)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(basePath: '/home/user/project');
        $output = $this->formatter->format($report, $context);
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $result = $data['runs'][0]['results'][0];

        // Should have relatedLocations
        self::assertArrayHasKey('relatedLocations', $result);
        self::assertCount(2, $result['relatedLocations']);

        // First related location
        $rel0 = $result['relatedLocations'][0];
        self::assertSame(0, $rel0['id']);
        self::assertSame('src/Service/OrderService.php', $rel0['physicalLocation']['artifactLocation']['uri']);
        self::assertSame('%SRCROOT%', $rel0['physicalLocation']['artifactLocation']['uriBaseId']);
        self::assertSame(42, $rel0['physicalLocation']['region']['startLine']);
        self::assertSame('Related location', $rel0['message']['text']);

        // Second related location
        $rel1 = $result['relatedLocations'][1];
        self::assertSame(1, $rel1['id']);
        self::assertSame('src/Service/PaymentService.php', $rel1['physicalLocation']['artifactLocation']['uri']);
        self::assertSame(88, $rel1['physicalLocation']['region']['startLine']);
    }

    public function testNoRelatedLocationsOmitsField(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'complexity.cyclomatic',
                violationCode: 'complexity.cyclomatic',
                message: 'Too complex',
                severity: Severity::Error,
            ))
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $result = $data['runs'][0]['results'][0];

        // Should NOT have relatedLocations when empty
        self::assertArrayNotHasKey('relatedLocations', $result);
    }

    public function testDefaultConfigurationLevelUsesMaxSeverity(): void
    {
        $report = ReportBuilder::create()
            ->addViolation(new Violation(
                location: new Location('src/A.php', 10),
                symbolPath: SymbolPath::forClass('App', 'A'),
                ruleName: 'cyclomatic-complexity',
                violationCode: 'complexity.cyclomatic',
                message: 'Warning level',
                severity: Severity::Warning,
            ))
            ->addViolation(new Violation(
                location: new Location('src/B.php', 20),
                symbolPath: SymbolPath::forClass('App', 'B'),
                ruleName: 'cyclomatic-complexity',
                violationCode: 'complexity.cyclomatic',
                message: 'Error level',
                severity: Severity::Error,
            ))
            ->addViolation(new Violation(
                location: new Location('src/C.php', 30),
                symbolPath: SymbolPath::forClass('App', 'C'),
                ruleName: 'class-size',
                violationCode: 'size.class',
                message: 'Warning only',
                severity: Severity::Warning,
            ))
            ->filesAnalyzed(3)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());
        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        $rules = $data['runs'][0]['tool']['driver']['rules'];
        $rulesByCode = [];
        foreach ($rules as $rule) {
            $rulesByCode[$rule['id']] = $rule;
        }

        // complexity.cyclomatic has both Warning and Error violations -> max is Error
        self::assertSame('error', $rulesByCode['complexity.cyclomatic']['defaultConfiguration']['level']);

        // size.class has only Warning violations -> Warning
        self::assertSame('warning', $rulesByCode['size.class']['defaultConfiguration']['level']);
    }
}
