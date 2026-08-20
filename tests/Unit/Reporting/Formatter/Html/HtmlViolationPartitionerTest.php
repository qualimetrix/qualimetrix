<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
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

        $violation = self::violation(
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

        $violation = self::violation(
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

        $violation = self::violation(
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

        $violation = self::violation(
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

        $violation = self::violation(
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

        $violation = self::violation(
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
        $violation = self::violation(
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

        $v1 = self::violation(
            location: new Location(RelativePath::fromString('src/A/ClassA.php'), 10),
            symbolPath: SymbolPath::forClass('App\\A', 'ClassA'),
            ruleName: 'r1',
            violationCode: 'r1',
            message: 'm1',
            severity: Severity::Error,
        );
        $v2 = self::violation(
            location: new Location(RelativePath::fromString('src/A/ClassA.php'), 20),
            symbolPath: SymbolPath::forMethod('App\\A', 'ClassA', 'foo'),
            ruleName: 'r2',
            violationCode: 'r2',
            message: 'm2',
            severity: Severity::Warning,
        );
        $v3 = self::violation(
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

        $violation = self::violation(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
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
            ['App\\Service' => [$violation]],
            new FormatterContext(),
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
        $violation = self::violation(new Location(RelativePath::fromString('src/Service.php'), 10), $logical, 'r', 'r', 'message', Severity::Warning, occurrenceKey: $occurrence, subject: $subject);

        $this->partitioner->attach(['App\\Service' => $node], ['App\\Service' => [$violation]], new FormatterContext());

        self::assertSame($subject->toCanonical(), $node->violations[0]['subject']);
        self::assertSame($occurrence->value, $node->violations[0]['occurrence']);
        self::assertSame($logical->toString(), $node->violations[0]['symbolPath']);
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
                self::violation(
                    new Location(RelativePath::fromString('src/Service.php'), 10),
                    $logical,
                    'r',
                    'r',
                    'first declaration',
                    Severity::Warning,
                    subject: $firstSubject,
                ),
                self::violation(
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

        self::assertCount(2, $node->violations);
        self::assertSame(
            [$firstSubject->toCanonical(), $secondSubject->toCanonical()],
            array_column($node->violations, 'subject'),
        );
        self::assertSame([$logical->toString(), $logical->toString()], array_column($node->violations, 'symbolPath'));
    }

    #[Test]
    public function itNullsNanAndInfMetricValuesOnAttach(): void
    {
        $node = new HtmlTreeNode('Service', 'App\\Service', 'class');

        $nanViolation = self::violation(
            location: new Location(RelativePath::fromString('src/Service.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Service'),
            ruleName: 'r1',
            violationCode: 'r1',
            message: 'm1',
            severity: Severity::Warning,
            metricValue: \NAN,
        );

        $infViolation = self::violation(
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

        $violation = self::violation(
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

        $violation = self::violation(
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

    /** @param list<\Qualimetrix\Analysis\Finding\Contract\Location> $relatedLocations */
    private static function violation(\Qualimetrix\Analysis\Finding\Contract\Location $location, \Qualimetrix\Core\Symbol\SymbolPath $symbolPath, string $ruleName, string $violationCode, string $message, \Qualimetrix\Analysis\Finding\Contract\Severity $severity, int|float|null $metricValue = null, ?\Qualimetrix\Analysis\Finding\Contract\Rule\RuleLevel $level = null, array $relatedLocations = [], ?string $recommendation = null, int|float|null $threshold = null, ?\Qualimetrix\Core\Symbol\SymbolPath $dependencyTarget = null, ?\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType $dependencyType = null, ?\Qualimetrix\Analysis\Finding\Contract\AcceptedLevel $acceptedLevel = null, ?\Qualimetrix\Analysis\Finding\Contract\OccurrenceKey $occurrenceKey = null, ?\Qualimetrix\Core\Symbol\MetricSubject $subject = null): Violation
    {
        $subject ??= match ($symbolPath->getType()) {
            \Qualimetrix\Core\Symbol\SymbolType::File, \Qualimetrix\Core\Symbol\SymbolType::Namespace_, \Qualimetrix\Core\Symbol\SymbolType::Project => \Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath),
            default => \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($symbolPath, $location->file ?? \Qualimetrix\Core\Path\RelativePath::fromString('tests/Reporting/fixture.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
        };
        return new Violation(location: $location, subject: $subject, symbolPath: $symbolPath, ruleName: $ruleName, violationCode: $violationCode, message: $message, severity: $severity, metricValue: $metricValue, level: $level, relatedLocations: $relatedLocations, recommendation: $recommendation, threshold: $threshold, dependencyTarget: $dependencyTarget, dependencyType: $dependencyType, acceptedLevel: $acceptedLevel, occurrenceKey: $occurrenceKey);
    }

}
