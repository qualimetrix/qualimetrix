<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Formatter\Html;

use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\ComputedMetric\ComputedMetricDefinition;
use AiMessDetector\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Debt\DebtCalculator;
use AiMessDetector\Reporting\Debt\RemediationTimeRegistry;
use AiMessDetector\Reporting\Formatter\Html\HtmlTreeBuilder;
use AiMessDetector\Reporting\Formatter\Html\HtmlTreeNode;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\ReportBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlTreeBuilder::class)]
#[CoversClass(HtmlTreeNode::class)]
final class HtmlTreeBuilderTest extends TestCase
{
    private HtmlTreeBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new HtmlTreeBuilder(
            new DebtCalculator(new RemediationTimeRegistry()),
        );
    }

    protected function tearDown(): void
    {
        ComputedMetricDefinitionHolder::reset();
    }

    public function testBuildWithNullMetrics(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(5)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        self::assertArrayHasKey('project', $result);
        self::assertArrayHasKey('tree', $result);
        self::assertArrayHasKey('summary', $result);
        self::assertArrayHasKey('computedMetricDefinitions', $result);

        $tree = $result['tree'];
        self::assertSame(basename(getcwd() ?: '.'), $tree['name']);
        self::assertSame('project', $tree['type']);
        self::assertArrayNotHasKey('children', $tree);

        $summary = $result['summary'];
        self::assertSame(5, $summary['totalFiles']);
        self::assertSame(0, $summary['totalClasses']);
        self::assertSame(0, $summary['totalViolations']);
    }

    public function testBuildWithEmptyMetrics(): void
    {
        $metrics = new InMemoryMetricRepository();

        $report = ReportBuilder::create()
            ->filesAnalyzed(3)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $tree = $result['tree'];
        self::assertSame(basename(getcwd() ?: '.'), $tree['name']);
        self::assertSame('project', $tree['type']);
        self::assertArrayNotHasKey('children', $tree);
        self::assertSame(0, $result['summary']['totalClasses']);
    }

    public function testBuildSingleNamespace(): void
    {
        $metrics = new InMemoryMetricRepository();

        // Add namespace metrics
        $metrics->add(
            SymbolPath::forNamespace('App\\Service'),
            MetricBag::fromArray(['loc.sum' => 200, 'classes.count' => 2]),
            '',
            null,
        );

        // Add classes
        $metrics->add(
            SymbolPath::forClass('App\\Service', 'UserService'),
            MetricBag::fromArray(['ccn.sum' => 5, 'loc.sum' => 120]),
            'src/Service/UserService.php',
            10,
        );
        $metrics->add(
            SymbolPath::forClass('App\\Service', 'OrderService'),
            MetricBag::fromArray(['ccn.sum' => 3, 'loc.sum' => 80]),
            'src/Service/OrderService.php',
            5,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $tree = $result['tree'];
        self::assertArrayHasKey('children', $tree);
        self::assertCount(1, $tree['children']); // App

        $appNode = $tree['children'][0];
        self::assertSame('App', $appNode['name']);
        self::assertSame('App', $appNode['path']);
        self::assertSame('namespace', $appNode['type']);

        self::assertCount(1, $appNode['children']); // Service
        $serviceNode = $appNode['children'][0];
        self::assertSame('Service', $serviceNode['name']);
        self::assertSame('App\\Service', $serviceNode['path']);
        self::assertSame('namespace', $serviceNode['type']);

        // Two classes under Service
        self::assertCount(2, $serviceNode['children']);

        $classNames = array_map(
            static fn(array $child): string => $child['name'],
            $serviceNode['children'],
        );
        self::assertContains('UserService', $classNames);
        self::assertContains('OrderService', $classNames);

        self::assertSame(2, $result['summary']['totalClasses']);
    }

    public function testBuildMultipleRootNamespaces(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App\\Controller', 'HomeController'),
            MetricBag::fromArray(['ccn.sum' => 2]),
            'src/Controller/HomeController.php',
            1,
        );
        $metrics->add(
            SymbolPath::forClass('Domain\\User', 'UserEntity'),
            MetricBag::fromArray(['ccn.sum' => 1]),
            'src/Domain/User/UserEntity.php',
            1,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $tree = $result['tree'];
        self::assertCount(2, $tree['children']); // App, Domain

        $rootNames = array_map(
            static fn(array $child): string => $child['name'],
            $tree['children'],
        );
        self::assertContains('App', $rootNames);
        self::assertContains('Domain', $rootNames);
    }

    public function testBuildNestedNamespaces(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forNamespace('App\\Payment\\Processing'),
            MetricBag::fromArray(['loc.sum' => 100]),
            '',
            null,
        );
        $metrics->add(
            SymbolPath::forClass('App\\Payment\\Processing', 'PaymentProcessor'),
            MetricBag::fromArray(['ccn.sum' => 4]),
            'src/Payment/Processing/PaymentProcessor.php',
            1,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $tree = $result['tree'];
        // App -> Payment -> Processing -> PaymentProcessor
        $appNode = $tree['children'][0];
        self::assertSame('App', $appNode['name']);
        self::assertSame('App', $appNode['path']);

        $paymentNode = $appNode['children'][0];
        self::assertSame('Payment', $paymentNode['name']);
        self::assertSame('App\\Payment', $paymentNode['path']);

        $processingNode = $paymentNode['children'][0];
        self::assertSame('Processing', $processingNode['name']);
        self::assertSame('App\\Payment\\Processing', $processingNode['path']);

        self::assertCount(1, $processingNode['children']);
        self::assertSame('PaymentProcessor', $processingNode['children'][0]['name']);
    }

    public function testBuildProceduralFiles(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('', 'GlobalHelper'),
            MetricBag::fromArray(['ccn.sum' => 1]),
            'src/GlobalHelper.php',
            1,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $tree = $result['tree'];
        self::assertCount(1, $tree['children']);

        $noNsNode = $tree['children'][0];
        self::assertSame('(no namespace)', $noNsNode['name']);
        self::assertSame('namespace', $noNsNode['type']);

        self::assertCount(1, $noNsNode['children']);
        self::assertSame('GlobalHelper', $noNsNode['children'][0]['name']);
    }

    public function testBuildViolationAttachment(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App\\Service', 'UserService'),
            MetricBag::fromArray(['ccn.sum' => 15]),
            'src/Service/UserService.php',
            10,
        );

        // Method-level violation should be attached to the class
        $violation = new Violation(
            location: new Location('src/Service/UserService.php', 25),
            symbolPath: SymbolPath::forMethod('App\\Service', 'UserService', 'calculate'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Cyclomatic complexity is 15',
            severity: Severity::Warning,
            metricValue: 15,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->addViolation($violation)
            ->build();

        $context = new FormatterContext(basePath: 'src');
        $result = $this->builder->build($report, $context);

        $tree = $result['tree'];
        // App -> Service -> UserService
        $classNode = $tree['children'][0]['children'][0]['children'][0];
        self::assertSame('UserService', $classNode['name']);
        self::assertCount(1, $classNode['violations']);

        $v = $classNode['violations'][0];
        self::assertSame('complexity.cyclomatic', $v['ruleName']);
        self::assertSame('complexity.cyclomatic', $v['violationCode']);
        self::assertSame('Cyclomatic complexity is 15', $v['message']);
        self::assertSame('warning', $v['severity']);
        self::assertSame(15, $v['metricValue']);
        self::assertSame('App\\Service\\UserService::calculate', $v['symbolPath']);
        self::assertSame('Service/UserService.php', $v['file']);
        self::assertSame(25, $v['line']);
    }

    public function testBuildViolationCountTotalBottomUp(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App\\A', 'ClassA'),
            MetricBag::fromArray(['ccn.sum' => 10]),
            'src/A/ClassA.php',
            1,
        );
        $metrics->add(
            SymbolPath::forClass('App\\B', 'ClassB'),
            MetricBag::fromArray(['ccn.sum' => 5]),
            'src/B/ClassB.php',
            1,
        );

        $v1 = new Violation(
            location: new Location('src/A/ClassA.php', 10),
            symbolPath: SymbolPath::forClass('App\\A', 'ClassA'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Error,
            metricValue: 10,
        );
        $v2 = new Violation(
            location: new Location('src/A/ClassA.php', 20),
            symbolPath: SymbolPath::forMethod('App\\A', 'ClassA', 'doStuff'),
            ruleName: 'complexity.cognitive',
            violationCode: 'complexity.cognitive',
            message: 'Too cognitive',
            severity: Severity::Warning,
            metricValue: 8,
        );
        $v3 = new Violation(
            location: new Location('src/B/ClassB.php', 5),
            symbolPath: SymbolPath::forClass('App\\B', 'ClassB'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Complex',
            severity: Severity::Warning,
            metricValue: 5,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->addViolation($v1)
            ->addViolation($v2)
            ->addViolation($v3)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $tree = $result['tree'];
        // Root should have 3 total violations
        self::assertSame(3, $tree['violationCountTotal']);

        // App node should also have 3
        $appNode = $tree['children'][0];
        self::assertSame(3, $appNode['violationCountTotal']);

        // ClassA has 2 violations (1 class-level + 1 method-level attached to class)
        $aNode = null;
        $bNode = null;
        foreach ($appNode['children'] as $child) {
            if ($child['name'] === 'A') {
                $aNode = $child;
            }
            if ($child['name'] === 'B') {
                $bNode = $child;
            }
        }
        self::assertNotNull($aNode);
        self::assertNotNull($bNode);

        self::assertSame(2, $aNode['violationCountTotal']);
        self::assertSame(1, $bNode['violationCountTotal']);
    }

    public function testBuildNanInfMetrics(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App', 'Calculator'),
            MetricBag::fromArray(['normal' => 42, 'nan_val' => \NAN, 'inf_val' => \INF]),
            'src/Calculator.php',
            1,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $tree = $result['tree'];
        $classNode = $tree['children'][0]['children'][0];
        self::assertSame('Calculator', $classNode['name']);

        $classMetrics = (array) $classNode['metrics'];
        self::assertSame(42, $classMetrics['normal']);
        self::assertNull($classMetrics['nan_val']);
        self::assertNull($classMetrics['inf_val']);
    }

    public function testBuildDebtCalculation(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App', 'Service'),
            MetricBag::fromArray(['ccn.sum' => 10]),
            'src/Service.php',
            1,
        );

        $violation = new Violation(
            location: new Location('src/Service.php', 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Warning,
            metricValue: 10,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->addViolation($violation)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        // complexity.cyclomatic = 30 minutes per RemediationTimeRegistry
        $tree = $result['tree'];
        self::assertSame(30, $tree['debtMinutes']);
        self::assertSame(30, $result['summary']['totalDebtMinutes']);

        // Class node should have 30 minutes
        $classNode = $tree['children'][0]['children'][0];
        self::assertSame(30, $classNode['debtMinutes']);
    }

    public function testBuildHealthScoresInSummary(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forProject(),
            MetricBag::fromArray([
                'health.overall' => 85.0,
                'health.complexity' => 90.0,
                'loc.sum' => 1000,
                'classes.count' => 10,
            ]),
            '',
            null,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.5)
            ->metrics($metrics)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $healthScores = (array) $result['summary']['healthScores'];
        self::assertSame(85.0, $healthScores['health.overall']);
        self::assertSame(90.0, $healthScores['health.complexity']);
        self::assertArrayNotHasKey('loc.sum', $healthScores);
        self::assertArrayNotHasKey('classes.count', $healthScores);
    }

    public function testBuildJsonHexTag(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App', 'ScriptTag</script>Test'),
            MetricBag::fromArray(['ccn.sum' => 1]),
            'src/ScriptTagTest.php',
            1,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        // The tree should be buildable and the class name should contain </script>
        $classNode = $result['tree']['children'][0]['children'][0];
        self::assertStringContainsString('</script>', $classNode['name']);

        // Verify it can be safely JSON-encoded with JSON_HEX_TAG
        $json = json_encode($result, \JSON_HEX_TAG | \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('</script>', $json);
        self::assertStringContainsString('\\u003C', $json);
    }

    public function testBuildProjectMetadata(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $result = $this->builder->build($report, new FormatterContext(), partialAnalysis: true);

        $project = $result['project'];
        self::assertArrayHasKey('name', $project);
        self::assertArrayHasKey('generatedAt', $project);
        self::assertArrayHasKey('aimdVersion', $project);
        self::assertTrue($project['partialAnalysis']);
    }

    public function testBuildPartialAnalysisFalseByDefault(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        self::assertFalse($result['project']['partialAnalysis']);
    }

    public function testBuildComputedMetricDefinitions(): void
    {
        ComputedMetricDefinitionHolder::setDefinitions([
            new ComputedMetricDefinition(
                name: 'health.overall',
                formulas: ['class' => '100'],
                description: 'Overall health score',
                levels: [SymbolType::Class_, SymbolType::Namespace_, SymbolType::Project],
                inverted: false,
            ),
            new ComputedMetricDefinition(
                name: 'computed.custom',
                formulas: ['class' => '50'],
                description: 'Custom metric',
                levels: [SymbolType::Class_],
            ),
        ]);

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $defs = (array) $result['computedMetricDefinitions'];
        // Only health.* should be included
        self::assertArrayHasKey('health.overall', $defs);
        self::assertArrayNotHasKey('computed.custom', $defs);

        $healthDef = $defs['health.overall'];
        self::assertSame('Overall health score', $healthDef['description']);
        self::assertSame([0, 100], $healthDef['scale']);
        self::assertFalse($healthDef['inverted']);
    }

    public function testBuildInternalMetricsFiltered(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App', 'Service'),
            MetricBag::fromArray([
                'ccn.sum' => 5,
                'internal:cache_key' => 42,
                'some:internal:value' => 99,
            ]),
            'src/Service.php',
            1,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $classNode = $result['tree']['children'][0]['children'][0];
        $classMetrics = (array) $classNode['metrics'];
        self::assertArrayHasKey('ccn.sum', $classMetrics);
        self::assertArrayNotHasKey('internal:cache_key', $classMetrics);
        self::assertArrayNotHasKey('some:internal:value', $classMetrics);
    }

    public function testBuildViolationWithNanMetricValue(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App', 'Service'),
            MetricBag::fromArray(['mi' => 50.0]),
            'src/Service.php',
            1,
        );

        $violation = new Violation(
            location: new Location('src/Service.php', 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'maintainability.index',
            violationCode: 'maintainability.index',
            message: 'Low maintainability',
            severity: Severity::Warning,
            metricValue: \NAN,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->addViolation($violation)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $classNode = $result['tree']['children'][0]['children'][0];
        self::assertNull($classNode['violations'][0]['metricValue']);
    }

    public function testBuildLocSumAggregatedBottomUp(): void
    {
        $metrics = new InMemoryMetricRepository();

        // Classes have loc.sum but the namespace does not
        $metrics->add(
            SymbolPath::forClass('App\\Service', 'UserService'),
            MetricBag::fromArray(['loc.sum' => 100]),
            'src/Service/UserService.php',
            1,
        );
        $metrics->add(
            SymbolPath::forClass('App\\Service', 'OrderService'),
            MetricBag::fromArray(['loc.sum' => 150]),
            'src/Service/OrderService.php',
            1,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $tree = $result['tree'];
        // App -> Service -> [UserService, OrderService]
        $serviceNode = $tree['children'][0]['children'][0];
        self::assertSame('Service', $serviceNode['name']);

        $serviceMetrics = (array) $serviceNode['metrics'];
        self::assertSame(250, $serviceMetrics['loc.sum']);

        // Root should also aggregate
        $rootMetrics = (array) $tree['metrics'];
        self::assertSame(250, $rootMetrics['loc.sum']);
    }

    public function testBuildEmptyMetricsForceObjectInJson(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App', 'EmptyClass'),
            new MetricBag(),
            'src/EmptyClass.php',
            1,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $classNode = $result['tree']['children'][0]['children'][0];
        // Encoding to JSON should produce {} not []
        $json = json_encode($classNode['metrics'], \JSON_THROW_ON_ERROR);
        self::assertSame('{}', $json);
    }

    public function testToArrayOmitsChildrenWhenEmpty(): void
    {
        $node = new HtmlTreeNode('test', 'test', 'class');
        $array = $node->toArray();

        self::assertArrayNotHasKey('children', $array);
    }

    public function testToArrayIncludesChildrenWhenPresent(): void
    {
        $parent = new HtmlTreeNode('parent', 'parent', 'namespace');
        $child = new HtmlTreeNode('child', 'child', 'class');
        $parent->children[] = $child;

        $array = $parent->toArray();

        self::assertArrayHasKey('children', $array);
        self::assertCount(1, $array['children']);
        self::assertSame('child', $array['children'][0]['name']);
    }

    public function testBuildFileViolationSkipped(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App', 'Service'),
            MetricBag::fromArray(['ccn.sum' => 5]),
            'src/Service.php',
            1,
        );

        // File-level violation should be skipped (not attached to any node)
        $violation = new Violation(
            location: new Location('src/helpers.php', 1),
            symbolPath: SymbolPath::forFile('src/helpers.php'),
            ruleName: 'size.loc',
            violationCode: 'size.loc',
            message: 'File too large',
            severity: Severity::Warning,
            metricValue: 500,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->addViolation($violation)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $tree = $result['tree'];
        // Violation count in tree should be 0 (file-level skipped)
        self::assertSame(0, $tree['violationCountTotal']);
    }

    public function testBuildRelativizesFilePaths(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App', 'Service'),
            MetricBag::fromArray(['ccn.sum' => 5]),
            '/project/src/Service.php',
            1,
        );

        $violation = new Violation(
            location: new Location('/project/src/Service.php', 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Complex',
            severity: Severity::Warning,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->addViolation($violation)
            ->build();

        $context = new FormatterContext(basePath: '/project');
        $result = $this->builder->build($report, $context);

        $classNode = $result['tree']['children'][0]['children'][0];
        self::assertSame('src/Service.php', $classNode['violations'][0]['file']);
    }
}
