<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Reporting\Formatter\Html\HtmlTreeBuilder;
use Qualimetrix\Reporting\Formatter\Html\HtmlTreeNode;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\ReportBuilder;
use Qualimetrix\Tests\Analysis\Evidence\Prioritization\Support\StubRemediationMinutes;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

#[CoversClass(HtmlTreeBuilder::class)]
#[CoversClass(HtmlTreeNode::class)]
final class HtmlTreeBuilderTest extends TestCase
{
    private HtmlTreeBuilder $builder;
    /** @var list<ComputedMetricDefinition> */
    private array $definitions = [];

    protected function setUp(): void
    {
        $this->builder = new HtmlTreeBuilder(
            new DebtCalculator(new RemediationTimeRegistry(StubChannelDeclarationRegistry::alwaysHigherMagnitude(), StubRemediationMinutes::withRealValues())),
            $this->catalog(),
        );
    }

    #[Test]
    public function itBuildsWithNullMetrics(): void
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
        self::assertSame('<project>', $tree['name']);
        self::assertSame('project', $tree['type']);
        self::assertArrayNotHasKey('children', $tree);

        $summary = $result['summary'];
        self::assertSame(5, $summary['totalFiles']);
        self::assertSame(0, $summary['totalClasses']);
        self::assertSame(0, $summary['totalViolations']);
    }

    #[Test]
    public function itBuildsWithEmptyMetrics(): void
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
        self::assertSame('<project>', $tree['name']);
        self::assertSame('project', $tree['type']);
        self::assertArrayNotHasKey('children', $tree);
        self::assertSame(0, $result['summary']['totalClasses']);
    }

    #[Test]
    public function itBuildsSingleNamespace(): void
    {
        $metrics = new InMemoryMetricRepository();

        // Add namespace metrics
        $metrics->add(
            SymbolPath::forNamespace('App\\Service'),
            MetricBag::fromArray(['loc.sum' => 200, 'classes.count' => 2]),
            null,
            null,
        );

        // Add classes
        $metrics->add(
            SymbolPath::forClass('App\\Service', 'UserService'),
            MetricBag::fromArray(['ccn.sum' => 5, 'loc.sum' => 120]),
            RelativePath::fromString('src/Service/UserService.php'),
            10,
        );
        $metrics->add(
            SymbolPath::forClass('App\\Service', 'OrderService'),
            MetricBag::fromArray(['ccn.sum' => 3, 'loc.sum' => 80]),
            RelativePath::fromString('src/Service/OrderService.php'),
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

    #[Test]
    public function itBuildsMultipleRootNamespaces(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App\\Controller', 'HomeController'),
            MetricBag::fromArray(['ccn.sum' => 2]),
            RelativePath::fromString('src/Controller/HomeController.php'),
            1,
        );
        $metrics->add(
            SymbolPath::forClass('Domain\\User', 'UserEntity'),
            MetricBag::fromArray(['ccn.sum' => 1]),
            RelativePath::fromString('src/Domain/User/UserEntity.php'),
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

    #[Test]
    public function itBuildsNestedNamespaces(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forNamespace('App\\Payment\\Processing'),
            MetricBag::fromArray(['loc.sum' => 100]),
            null,
            null,
        );
        $metrics->add(
            SymbolPath::forClass('App\\Payment\\Processing', 'PaymentProcessor'),
            MetricBag::fromArray(['ccn.sum' => 4]),
            RelativePath::fromString('src/Payment/Processing/PaymentProcessor.php'),
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

    #[Test]
    public function itBuildsProceduralFiles(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('', 'GlobalHelper'),
            MetricBag::fromArray(['ccn.sum' => 1]),
            RelativePath::fromString('src/GlobalHelper.php'),
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

    #[Test]
    public function itAttachesFindingToTreeNode(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App\\Service', 'UserService'),
            MetricBag::fromArray(['ccn.sum' => 15]),
            RelativePath::fromString('src/Service/UserService.php'),
            10,
        );

        // Method-level finding should be attached to the class
        $finding = self::finding(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 25),
            symbolPath: SymbolPath::forMethod('App\\Service', 'UserService', 'calculate'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'Cyclomatic complexity is 15',
            severity: Severity::Warning,
            metricValue: 15,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->addFinding($finding)
            ->build();

        // ADR 0015 Phase 4: Location::$file is already project-relative, so
        // FormatterContext::relativizePath no longer strips basePath. The
        // formatter prints the path verbatim.
        $context = new FormatterContext();
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
        self::assertSame('src/Service/UserService.php', $v['file']);
        self::assertSame(25, $v['line']);
    }

    #[Test]
    public function itCountsFindingsTotalBottomUp(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App\\A', 'ClassA'),
            MetricBag::fromArray(['ccn.sum' => 10]),
            RelativePath::fromString('src/A/ClassA.php'),
            1,
        );
        $metrics->add(
            SymbolPath::forClass('App\\B', 'ClassB'),
            MetricBag::fromArray(['ccn.sum' => 5]),
            RelativePath::fromString('src/B/ClassB.php'),
            1,
        );

        $v1 = self::finding(
            location: new Location(RelativePath::fromString('src/A/ClassA.php'), 10),
            symbolPath: SymbolPath::forClass('App\\A', 'ClassA'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Error,
            metricValue: 10,
        );
        $v2 = self::finding(
            location: new Location(RelativePath::fromString('src/A/ClassA.php'), 20),
            symbolPath: SymbolPath::forMethod('App\\A', 'ClassA', 'doStuff'),
            ruleName: 'complexity.cognitive',
            code: 'complexity.cognitive',
            message: 'Too cognitive',
            severity: Severity::Warning,
            metricValue: 8,
        );
        $v3 = self::finding(
            location: new Location(RelativePath::fromString('src/B/ClassB.php'), 5),
            symbolPath: SymbolPath::forClass('App\\B', 'ClassB'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'Complex',
            severity: Severity::Warning,
            metricValue: 5,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->addFinding($v1)
            ->addFinding($v2)
            ->addFinding($v3)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $tree = $result['tree'];
        // Root should have 3 total findings
        self::assertSame(3, $tree['violationCountTotal']);

        // App node should also have 3
        $appNode = $tree['children'][0];
        self::assertSame(3, $appNode['violationCountTotal']);

        // ClassA has 2 findings (1 class-level + 1 callable-level attached to class)
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

    #[Test]
    public function itNullsNanAndInfMetricValues(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App', 'Calculator'),
            MetricBag::fromArray(['normal' => 42, 'nan_val' => \NAN, 'inf_val' => \INF]),
            RelativePath::fromString('src/Calculator.php'),
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

    #[Test]
    public function itCalculatesDebtDuringBuild(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App', 'Service'),
            MetricBag::fromArray(['ccn.sum' => 10]),
            RelativePath::fromString('src/Service.php'),
            1,
        );

        $finding = self::finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Warning,
            metricValue: 10,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->addFinding($finding)
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

    #[Test]
    public function itIncludesHealthScoresInSummary(): void
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
            null,
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

    #[Test]
    public function itEscapesHtmlTagsInJsonEncoding(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App', 'ScriptTag</script>Test'),
            MetricBag::fromArray(['ccn.sum' => 1]),
            RelativePath::fromString('src/ScriptTagTest.php'),
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

    #[Test]
    public function itIncludesProjectMetadataWithScopedReporting(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $result = $this->builder->build($report, new FormatterContext(), scopedReporting: true);

        $project = $result['project'];
        self::assertArrayHasKey('name', $project);
        self::assertArrayHasKey('generatedAt', $project);
        self::assertArrayHasKey('qmxVersion', $project);
        self::assertTrue($project['scopedReporting']);
    }

    #[Test]
    public function itSetsScopedReportingFalseByDefault(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        self::assertFalse($result['project']['scopedReporting']);
    }

    #[Test]
    public function itIncludesOnlyHealthComputedMetricDefinitions(): void
    {
        $this->definitions = [
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
        ];

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

    #[Test]
    public function itFiltersInternalMetricsFromOutput(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App', 'Service'),
            MetricBag::fromArray([
                'ccn.sum' => 5,
                'internal:cache_key' => 42,
                'some:internal:value' => 99,
            ]),
            RelativePath::fromString('src/Service.php'),
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

    #[Test]
    public function itNullsNanMetricValueInFinding(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App', 'Service'),
            MetricBag::fromArray(['mi' => 50.0]),
            RelativePath::fromString('src/Service.php'),
            1,
        );

        $finding = self::finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'maintainability.index',
            code: 'maintainability.index',
            message: 'Low maintainability',
            severity: Severity::Warning,
            metricValue: \NAN,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->addFinding($finding)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $classNode = $result['tree']['children'][0]['children'][0];
        self::assertNull($classNode['violations'][0]['metricValue']);
    }

    #[Test]
    public function itAggregatesLocSumBottomUpDuringBuild(): void
    {
        $metrics = new InMemoryMetricRepository();

        // Classes have loc.sum but the namespace does not
        $metrics->add(
            SymbolPath::forClass('App\\Service', 'UserService'),
            MetricBag::fromArray(['loc.sum' => 100]),
            RelativePath::fromString('src/Service/UserService.php'),
            1,
        );
        $metrics->add(
            SymbolPath::forClass('App\\Service', 'OrderService'),
            MetricBag::fromArray(['loc.sum' => 150]),
            RelativePath::fromString('src/Service/OrderService.php'),
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

    #[Test]
    public function itForcesEmptyMetricsToJsonObject(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App', 'EmptyClass'),
            new MetricBag(),
            RelativePath::fromString('src/EmptyClass.php'),
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

    #[Test]
    public function itOmitsChildrenFromArrayWhenEmpty(): void
    {
        $node = new HtmlTreeNode('test', 'test', 'class');
        $array = $node->toArray();

        self::assertArrayNotHasKey('children', $array);
    }

    #[Test]
    public function itIncludesChildrenInArrayWhenPresent(): void
    {
        $parent = new HtmlTreeNode('parent', 'parent', 'namespace');
        $child = new HtmlTreeNode('child', 'child', 'class');
        $parent->children[] = $child;

        $array = $parent->toArray();

        self::assertArrayHasKey('children', $array);
        self::assertCount(1, $array['children']);
        self::assertSame('child', $array['children'][0]['name']);
    }

    #[Test]
    public function itSkipsFileFindingDuringBuild(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forClass('App', 'Service'),
            MetricBag::fromArray(['ccn.sum' => 5]),
            RelativePath::fromString('src/Service.php'),
            1,
        );

        // File-level finding should be skipped (not attached to any node)
        $finding = self::finding(
            location: new Location(RelativePath::fromString('src/helpers.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/helpers.php')),
            ruleName: 'size.loc',
            code: 'size.loc',
            message: 'File too large',
            severity: Severity::Warning,
            metricValue: 500,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(2)
            ->filesSkipped(0)
            ->duration(0.1)
            ->metrics($metrics)
            ->addFinding($finding)
            ->build();

        $result = $this->builder->build($report, new FormatterContext());

        $tree = $result['tree'];
        // Finding count in tree should be 0 (file-level skipped)
        self::assertSame(0, $tree['violationCountTotal']);
    }

    #[Test]
    public function itUsesReportTechDebtForTotalDebtMinutesWhenAvailable(): void
    {
        $metrics = new InMemoryMetricRepository();

        $metrics->add(
            SymbolPath::forProject(),
            MetricBag::fromArray(['loc.sum' => 100, 'classes.count' => 1]),
            null,
            null,
        );

        $metrics->add(
            SymbolPath::forNamespace('App'),
            MetricBag::fromArray([]),
            null,
            null,
        );

        $metrics->add(
            SymbolPath::forClass('App', 'Foo'),
            MetricBag::fromArray(['ccn.sum' => 5]),
            RelativePath::fromString('src/Foo.php'),
            1,
        );

        // Class-level finding (30 min debt via RemediationTimeRegistry)
        $classFinding = self::finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'Complex',
            severity: Severity::Error,
        );

        // File-level finding — won't be partitioned into any node
        $fileFinding = self::finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), null),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/Foo.php')),
            ruleName: 'size.loc',
            code: 'size.loc',
            message: 'File too long',
            severity: Severity::Warning,
        );

        // Report with techDebtMinutes = 50 (includes both findings)
        $report = new \Qualimetrix\Reporting\Report(
            findings: [$classFinding, $fileFinding],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 0.1,
            errorCount: 1,
            warningCount: 1,
            metrics: $metrics,
            techDebtMinutes: 50,
        );

        $result = $this->builder->build($report, new FormatterContext());

        // Both tree root and summary should show 50 (report's techDebtMinutes),
        // not the bottom-up aggregation which misses file-level findings (30)
        self::assertSame(50, $result['tree']['debtMinutes']);
        self::assertSame(50, $result['summary']['totalDebtMinutes']);
    }

    private function catalog(): ComputedMetricDefinitionCatalogInterface
    {
        $catalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('all')->willReturnCallback(fn(): array => $this->definitions);

        return $catalog;
    }

    /** @param list<\Qualimetrix\Analysis\Finding\Contract\Location> $relatedLocations */
    private static function finding(\Qualimetrix\Analysis\Finding\Contract\Location $location, \Qualimetrix\Core\Symbol\SymbolPath $symbolPath, string $ruleName, string $code, string $message, \Qualimetrix\Analysis\Finding\Contract\Severity $severity, int|float|null $metricValue = null, array $relatedLocations = [], ?string $recommendation = null, int|float|null $threshold = null, ?\Qualimetrix\Core\Symbol\SymbolPath $dependencyTarget = null, ?\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType $dependencyType = null, ?\Qualimetrix\Analysis\Finding\Contract\AcceptedLevel $acceptedLevel = null, ?\Qualimetrix\Analysis\Finding\Contract\OccurrenceKey $occurrenceKey = null, ?\Qualimetrix\Core\Symbol\MetricSubject $subject = null): Finding
    {
        $subject ??= match ($symbolPath->getType()) {
            \Qualimetrix\Core\Symbol\SymbolType::File, \Qualimetrix\Core\Symbol\SymbolType::Namespace_, \Qualimetrix\Core\Symbol\SymbolType::Project => \Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath),
            default => \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($symbolPath, $location->file ?? \Qualimetrix\Core\Path\RelativePath::fromString('tests/Reporting/fixture.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
        };
        return new Finding(location: $location, subject: $subject, symbolPath: $symbolPath, ruleName: $ruleName, code: $code, message: $message, severity: $severity, metricValue: $metricValue, relatedLocations: $relatedLocations, recommendation: $recommendation, threshold: $threshold, dependencyTarget: $dependencyTarget, dependencyType: $dependencyType, acceptedLevel: $acceptedLevel, occurrenceKey: $occurrenceKey);
    }

}
