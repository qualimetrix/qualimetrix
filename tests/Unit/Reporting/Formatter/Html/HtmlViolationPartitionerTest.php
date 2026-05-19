<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Formatter\Html\HtmlTreeNode;
use Qualimetrix\Reporting\Formatter\Html\HtmlViolationPartitioner;
use Qualimetrix\Reporting\FormatterContext;

#[CoversClass(HtmlViolationPartitioner::class)]
final class HtmlViolationPartitionerTest extends TestCase
{
    private HtmlViolationPartitioner $partitioner;

    protected function setUp(): void
    {
        $this->partitioner = new HtmlViolationPartitioner();
    }

    // --- partition() tests ---

    #[Test]
    public function itPartitionsEmptyViolationsList(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $result = $this->partitioner->partition([], ['App\\Service' => $node]);

        self::assertSame([], $result);
    }

    #[Test]
    public function itAttachesClassViolationToClassNode(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $result = $this->partitioner->partition([$violation], ['App\\Service' => $node]);

        self::assertCount(1, $result);
        self::assertArrayHasKey('App\\Service', $result);
        self::assertSame([$violation], $result['App\\Service']);
    }

    #[Test]
    public function itAttachesMethodViolationToParentClassNode(): void
    {
        $classNode = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service.php'), 25),
            symbolPath: SymbolPath::forMethod('App', 'Service', 'calculate'),
            ruleName: 'complexity.cognitive',
            violationCode: 'complexity.cognitive',
            message: 'Too cognitive',
            severity: Severity::Warning,
        );

        $result = $this->partitioner->partition([$violation], ['App\\Service' => $classNode]);

        self::assertCount(1, $result);
        self::assertArrayHasKey('App\\Service', $result);
        self::assertSame([$violation], $result['App\\Service']);
    }

    #[Test]
    public function itPartitionsNamespaceViolation(): void
    {
        $nsNode = new HtmlTreeNode('App\\Service', 'App\\Service', 'namespace');

        $violation = new Violation(
            location: Location::none(),
            symbolPath: SymbolPath::forNamespace('App\\Service'),
            ruleName: 'size.namespace-size',
            violationCode: 'size.namespace-size',
            message: 'Too many classes',
            severity: Severity::Warning,
        );

        $result = $this->partitioner->partition([$violation], ['App\\Service' => $nsNode]);

        self::assertCount(1, $result);
        self::assertArrayHasKey('App\\Service', $result);
    }

    #[Test]
    public function itSkipsFileViolationDuringPartition(): void
    {
        $classNode = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/helpers.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/helpers.php')),
            ruleName: 'size.loc',
            violationCode: 'size.loc',
            message: 'File too large',
            severity: Severity::Warning,
        );

        $result = $this->partitioner->partition([$violation], ['App\\Service' => $classNode]);

        self::assertSame([], $result);
    }

    #[Test]
    public function itFallsBackToNamespaceNodeForMethodWhenClassNodeMissing(): void
    {
        $nsNode = new HtmlTreeNode('App', 'App', 'namespace');

        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forMethod('App', 'Service', 'calculate'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        // No class node exists, only the namespace node
        $result = $this->partitioner->partition([$violation], ['App' => $nsNode]);

        self::assertCount(1, $result);
        self::assertArrayHasKey('App', $result);
        self::assertSame([$violation], $result['App']);
    }

    #[Test]
    public function itFallsBackToNamespaceNodeForClassWhenClassNodeMissing(): void
    {
        $nsNode = new HtmlTreeNode('App', 'App', 'namespace');

        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $result = $this->partitioner->partition([$violation], ['App' => $nsNode]);

        self::assertCount(1, $result);
        self::assertArrayHasKey('App', $result);
    }

    #[Test]
    public function itDropsMethodViolationWhenNoClassAndNoNamespaceNode(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forMethod('App', 'Service', 'calculate'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $result = $this->partitioner->partition([$violation], []);

        self::assertSame([], $result);
    }

    #[Test]
    public function itPartitionsViolationsAcrossMultipleFilesAndTypes(): void
    {
        $classA = new HtmlTreeNode('ClassA', 'App\\A\\ClassA', 'class');
        $classB = new HtmlTreeNode('ClassB', 'App\\B\\ClassB', 'class');

        $v1 = new Violation(
            location: new Location(RelativePath::fromString('src/A/ClassA.php'), 10),
            symbolPath: SymbolPath::forClass('App\\A', 'ClassA'),
            ruleName: 'r1',
            violationCode: 'r1',
            message: 'm1',
            severity: Severity::Error,
        );
        $v2 = new Violation(
            location: new Location(RelativePath::fromString('src/A/ClassA.php'), 20),
            symbolPath: SymbolPath::forMethod('App\\A', 'ClassA', 'foo'),
            ruleName: 'r2',
            violationCode: 'r2',
            message: 'm2',
            severity: Severity::Warning,
        );
        $v3 = new Violation(
            location: new Location(RelativePath::fromString('src/B/ClassB.php'), 5),
            symbolPath: SymbolPath::forClass('App\\B', 'ClassB'),
            ruleName: 'r3',
            violationCode: 'r3',
            message: 'm3',
            severity: Severity::Warning,
        );

        $nodes = [
            'App\\A\\ClassA' => $classA,
            'App\\B\\ClassB' => $classB,
        ];

        $result = $this->partitioner->partition([$v1, $v2, $v3], $nodes);

        self::assertCount(2, $result);
        self::assertCount(2, $result['App\\A\\ClassA']); // v1 (class) + v2 (method -> class)
        self::assertCount(1, $result['App\\B\\ClassB']);
    }

    // --- attach() tests ---

    #[Test]
    public function itAttachesNothingWhenNoViolations(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $this->partitioner->attach(
            ['App\\Service' => $node],
            [],
            new FormatterContext(),
        );

        self::assertSame([], $node->violations);
    }

    #[Test]
    public function itFormatsViolationDataOnAttach(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Warning,
            metricValue: 15,
            recommendation: 'Split the method',
        );

        $this->partitioner->attach(
            ['App\\Service' => $node],
            ['App\\Service' => [$violation]],
            new FormatterContext(basePath: 'src'),
        );

        self::assertCount(1, $node->violations);
        $v = $node->violations[0];
        self::assertSame('complexity.cyclomatic', $v['ruleName']);
        self::assertSame('complexity.cyclomatic', $v['violationCode']);
        self::assertSame('Too complex', $v['message']);
        self::assertSame('Split the method', $v['recommendation']);
        self::assertSame('warning', $v['severity']);
        self::assertSame(15, $v['metricValue']);
        self::assertSame('App\\Service', $v['symbolPath']);
        self::assertSame('Service.php', $v['file']);
        self::assertSame(10, $v['line']);
    }

    #[Test]
    public function itNullsNanAndInfMetricValuesOnAttach(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $nanViolation = new Violation(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'r1',
            violationCode: 'r1',
            message: 'm1',
            severity: Severity::Warning,
            metricValue: \NAN,
        );

        $infViolation = new Violation(
            location: new Location(RelativePath::fromString('src/Service.php'), 20),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'r2',
            violationCode: 'r2',
            message: 'm2',
            severity: Severity::Warning,
            metricValue: \INF,
        );

        $this->partitioner->attach(
            ['App\\Service' => $node],
            ['App\\Service' => [$nanViolation, $infViolation]],
            new FormatterContext(),
        );

        self::assertCount(2, $node->violations);
        self::assertNull($node->violations[0]['metricValue']);
        self::assertNull($node->violations[1]['metricValue']);
    }

    #[Test]
    public function itSkipsUnknownNodePathsOnAttach(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Other.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Other'),
            ruleName: 'r1',
            violationCode: 'r1',
            message: 'm1',
            severity: Severity::Warning,
        );

        $this->partitioner->attach(
            ['App\\Service' => $node],
            ['App\\Other' => [$violation]],
            new FormatterContext(),
        );

        self::assertSame([], $node->violations);
    }

    #[Test]
    public function itProducesEmptyFileWhenAttachingLocationNone(): void
    {
        $node = new HtmlTreeNode('NS', 'App', 'namespace');

        $violation = new Violation(
            location: Location::none(),
            symbolPath: SymbolPath::forNamespace('App'),
            ruleName: 'arch.circular',
            violationCode: 'arch.circular',
            message: 'Circular dependency',
            severity: Severity::Error,
        );

        $this->partitioner->attach(
            ['App' => $node],
            ['App' => [$violation]],
            new FormatterContext(),
        );

        self::assertCount(1, $node->violations);
        self::assertSame('', $node->violations[0]['file']);
        self::assertNull($node->violations[0]['line']);
    }
}
