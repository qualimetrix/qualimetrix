<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Formatter\Html\HtmlFindingPartitioner;
use Qualimetrix\Reporting\Formatter\Html\HtmlTreeNode;
use Qualimetrix\Reporting\FormatterContext;

#[CoversClass(HtmlFindingPartitioner::class)]
final class HtmlFindingPartitionerTest extends TestCase
{
    private HtmlFindingPartitioner $partitioner;

    protected function setUp(): void
    {
        $this->partitioner = new HtmlFindingPartitioner();
    }

    // --- partition() tests ---

    #[Test]
    public function itPartitionsEmptyFindingsList(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $result = $this->partitioner->partition([], ['App\\Service' => $node]);

        self::assertSame([], $result);
    }

    #[Test]
    public function itAttachesClassFindingToClassNode(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $finding = self::finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $result = $this->partitioner->partition([$finding], ['App\\Service' => $node]);

        self::assertCount(1, $result);
        self::assertArrayHasKey('App\\Service', $result);
        self::assertSame([$finding], $result['App\\Service']);
    }

    #[Test]
    public function itAttachesMethodFindingToParentClassNode(): void
    {
        $classNode = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $finding = self::finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 25),
            symbolPath: SymbolPath::forMethod('App', 'Service', 'calculate'),
            ruleName: 'complexity.cognitive',
            code: 'complexity.cognitive',
            message: 'Too cognitive',
            severity: Severity::Warning,
        );

        $result = $this->partitioner->partition([$finding], ['App\\Service' => $classNode]);

        self::assertCount(1, $result);
        self::assertArrayHasKey('App\\Service', $result);
        self::assertSame([$finding], $result['App\\Service']);
    }

    #[Test]
    public function itPartitionsNamespaceFinding(): void
    {
        $nsNode = new HtmlTreeNode('App\\Service', 'App\\Service', 'namespace');

        $finding = self::finding(
            location: Location::none(),
            symbolPath: SymbolPath::forNamespace('App\\Service'),
            ruleName: 'size.namespace-size',
            code: 'size.namespace-size',
            message: 'Too many classes',
            severity: Severity::Warning,
        );

        $result = $this->partitioner->partition([$finding], ['App\\Service' => $nsNode]);

        self::assertCount(1, $result);
        self::assertArrayHasKey('App\\Service', $result);
    }

    #[Test]
    public function itSkipsFileFindingDuringPartition(): void
    {
        $classNode = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $finding = self::finding(
            location: new Location(RelativePath::fromString('src/helpers.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/helpers.php')),
            ruleName: 'size.loc',
            code: 'size.loc',
            message: 'File too large',
            severity: Severity::Warning,
        );

        $result = $this->partitioner->partition([$finding], ['App\\Service' => $classNode]);

        self::assertSame([], $result);
    }

    #[Test]
    public function itFallsBackToNamespaceNodeForMethodWhenClassNodeMissing(): void
    {
        $nsNode = new HtmlTreeNode('App', 'App', 'namespace');

        $finding = self::finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forMethod('App', 'Service', 'calculate'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        // No class node exists, only the namespace node
        $result = $this->partitioner->partition([$finding], ['App' => $nsNode]);

        self::assertCount(1, $result);
        self::assertArrayHasKey('App', $result);
        self::assertSame([$finding], $result['App']);
    }

    #[Test]
    public function itFallsBackToNamespaceNodeForClassWhenClassNodeMissing(): void
    {
        $nsNode = new HtmlTreeNode('App', 'App', 'namespace');

        $finding = self::finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $result = $this->partitioner->partition([$finding], ['App' => $nsNode]);

        self::assertCount(1, $result);
        self::assertArrayHasKey('App', $result);
    }

    #[Test]
    public function itDropsMethodFindingWhenNoClassAndNoNamespaceNode(): void
    {
        $finding = self::finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forMethod('App', 'Service', 'calculate'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Warning,
        );

        $result = $this->partitioner->partition([$finding], []);

        self::assertSame([], $result);
    }

    #[Test]
    public function itPartitionsFindingsAcrossMultipleFilesAndTypes(): void
    {
        $classA = new HtmlTreeNode('ClassA', 'App\\A\\ClassA', 'class');
        $classB = new HtmlTreeNode('ClassB', 'App\\B\\ClassB', 'class');

        $v1 = self::finding(
            location: new Location(RelativePath::fromString('src/A/ClassA.php'), 10),
            symbolPath: SymbolPath::forClass('App\\A', 'ClassA'),
            ruleName: 'r1',
            code: 'r1',
            message: 'm1',
            severity: Severity::Error,
        );
        $v2 = self::finding(
            location: new Location(RelativePath::fromString('src/A/ClassA.php'), 20),
            symbolPath: SymbolPath::forMethod('App\\A', 'ClassA', 'foo'),
            ruleName: 'r2',
            code: 'r2',
            message: 'm2',
            severity: Severity::Warning,
        );
        $v3 = self::finding(
            location: new Location(RelativePath::fromString('src/B/ClassB.php'), 5),
            symbolPath: SymbolPath::forClass('App\\B', 'ClassB'),
            ruleName: 'r3',
            code: 'r3',
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
    public function itAttachesNothingWhenNoFindings(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $this->partitioner->attach(
            ['App\\Service' => $node],
            [],
            new FormatterContext(),
        );

        self::assertSame([], $node->findings);
    }

    #[Test]
    public function itFormatsFindingDataOnAttach(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $finding = self::finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic',
            message: 'Too complex',
            severity: Severity::Warning,
            metricValue: 15,
            recommendation: 'Split the method',
        );

        // ADR 0015 Phase 4: basePath no longer participates in path
        // rendering; FormatterContext::relativizePath emits Location::$file
        // verbatim (the VO is already project-relative).
        $this->partitioner->attach(
            ['App\\Service' => $node],
            ['App\\Service' => [$finding]],
            new FormatterContext(),
        );

        self::assertCount(1, $node->findings);
        $v = $node->findings[0];
        self::assertSame('complexity.cyclomatic', $v['ruleName']);
        self::assertSame('complexity.cyclomatic', $v['violationCode']);
        self::assertSame('Too complex', $v['message']);
        self::assertSame('Split the method', $v['recommendation']);
        self::assertSame('warning', $v['severity']);
        self::assertSame(15, $v['metricValue']);
        self::assertSame('App\\Service', $v['symbolPath']);
        self::assertSame('src/Service.php', $v['file']);
        self::assertSame(10, $v['line']);
    }

    #[Test]
    public function itPreservesCanonicalSubjectAndOccurrenceInHtmlPayload(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');
        $logical = SymbolPath::forMethod('App', 'Service', 'run');
        $subject = MetricSubject::declaration(DeclarationPath::of($logical, RelativePath::fromString('src/Service.php'), DeclarationOrdinal::fromRank(0)));
        $occurrence = OccurrenceKey::semantic('test', ['id' => 1]);
        $finding = self::finding(new Location(RelativePath::fromString('src/Service.php'), 10), $logical, 'r', 'r', 'message', Severity::Warning, occurrenceKey: $occurrence, subject: $subject);

        $this->partitioner->attach(['App\\Service' => $node], ['App\\Service' => [$finding]], new FormatterContext());

        self::assertSame($subject->toCanonical(), $node->findings[0]['subject']);
        self::assertSame($occurrence->value, $node->findings[0]['occurrence']);
        self::assertSame($logical->toString(), $node->findings[0]['symbolPath']);
    }

    #[Test]
    public function itKeepsDuplicateLogicalDeclarationsAsDistinctHtmlPayloadRows(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');
        $logical = SymbolPath::forMethod('App', 'Service', 'run');
        $firstSubject = MetricSubject::declaration(
            DeclarationPath::of($logical, RelativePath::fromString('src/Service.php'), DeclarationOrdinal::fromRank(0)),
        );
        $secondSubject = MetricSubject::declaration(
            DeclarationPath::of($logical, RelativePath::fromString('src/Service.php'), DeclarationOrdinal::fromRank(1)),
        );

        $this->partitioner->attach(
            ['App\\Service' => $node],
            ['App\\Service' => [
                self::finding(
                    new Location(RelativePath::fromString('src/Service.php'), 10),
                    $logical,
                    'r',
                    'r',
                    'first declaration',
                    Severity::Warning,
                    subject: $firstSubject,
                ),
                self::finding(
                    new Location(RelativePath::fromString('src/Service.php'), 20),
                    $logical,
                    'r',
                    'r',
                    'second declaration',
                    Severity::Warning,
                    subject: $secondSubject,
                ),
            ]],
            new FormatterContext(),
        );

        self::assertCount(2, $node->findings);
        self::assertSame(
            [$firstSubject->toCanonical(), $secondSubject->toCanonical()],
            array_column($node->findings, 'subject'),
        );
        self::assertSame([$logical->toString(), $logical->toString()], array_column($node->findings, 'symbolPath'));
    }

    #[Test]
    public function itNullsNanAndInfMetricValuesOnAttach(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $nanFinding = self::finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'r1',
            code: 'r1',
            message: 'm1',
            severity: Severity::Warning,
            metricValue: \NAN,
        );

        $infFinding = self::finding(
            location: new Location(RelativePath::fromString('src/Service.php'), 20),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'r2',
            code: 'r2',
            message: 'm2',
            severity: Severity::Warning,
            metricValue: \INF,
        );

        $this->partitioner->attach(
            ['App\\Service' => $node],
            ['App\\Service' => [$nanFinding, $infFinding]],
            new FormatterContext(),
        );

        self::assertCount(2, $node->findings);
        self::assertNull($node->findings[0]['metricValue']);
        self::assertNull($node->findings[1]['metricValue']);
    }

    #[Test]
    public function itSkipsUnknownNodePathsOnAttach(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $finding = self::finding(
            location: new Location(RelativePath::fromString('src/Other.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Other'),
            ruleName: 'r1',
            code: 'r1',
            message: 'm1',
            severity: Severity::Warning,
        );

        $this->partitioner->attach(
            ['App\\Service' => $node],
            ['App\\Other' => [$finding]],
            new FormatterContext(),
        );

        self::assertSame([], $node->findings);
    }

    #[Test]
    public function itProducesEmptyFileWhenAttachingLocationNone(): void
    {
        $node = new HtmlTreeNode('NS', 'App', 'namespace');

        $finding = self::finding(
            location: Location::none(),
            symbolPath: SymbolPath::forNamespace('App'),
            ruleName: 'arch.circular',
            code: 'arch.circular',
            message: 'Circular dependency',
            severity: Severity::Error,
        );

        $this->partitioner->attach(
            ['App' => $node],
            ['App' => [$finding]],
            new FormatterContext(),
        );

        self::assertCount(1, $node->findings);
        self::assertSame('', $node->findings[0]['file']);
        self::assertNull($node->findings[0]['line']);
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
