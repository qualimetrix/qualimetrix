<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Json;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Formatter\Json\JsonSanitizer;
use Qualimetrix\Reporting\Formatter\Json\JsonViolationSection;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Tests\Analysis\Evidence\Prioritization\Support\StubRemediationMinutes;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

#[CoversClass(JsonViolationSection::class)]
final class JsonViolationSectionTest extends TestCase
{
    private JsonViolationSection $section;

    protected function setUp(): void
    {
        $this->section = new JsonViolationSection(
            new RemediationTimeRegistry(StubChannelDeclarationRegistry::alwaysHigherMagnitude(), StubRemediationMinutes::withRealValues()),
            new JsonSanitizer(),
        );
    }

    // --- format ---

    #[Test]
    public function itFormatsEmptyViolations(): void
    {
        $result = $this->section->format([], new FormatterContext());

        self::assertSame([], $result);
    }

    #[Test]
    public function itFormatsSingleViolation(): void
    {
        $violation = self::violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
            symbolPath: SymbolPath::forMethod('App\\Service', 'UserService', 'process'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Cyclomatic complexity is 15, threshold is 10',
            severity: Severity::Warning,
            metricValue: 15,
            threshold: 10,
            recommendation: 'Consider splitting the method',
        );

        $context = new FormatterContext(basePath: '/project');
        $result = $this->section->format([$violation], $context);

        self::assertCount(1, $result);
        $item = $result[0];

        self::assertSame('src/Service/UserService.php', $item['file']);
        self::assertSame(42, $item['line']);
        self::assertStringContainsString('UserService', $item['symbol']);
        self::assertStringContainsString('process', $item['symbol']);
        self::assertSame('App\\Service', $item['namespace']);
        self::assertSame('complexity.cyclomatic', $item['rule']);
        self::assertSame('complexity.cyclomatic', $item['code']);
        self::assertSame('warning', $item['severity']);
        self::assertSame('Cyclomatic complexity is 15, threshold is 10', $item['message']);
        self::assertSame('Consider splitting the method', $item['recommendation']);
        self::assertSame(15, $item['metricValue']);
        self::assertSame(10, $item['threshold']);
        self::assertArrayHasKey('techDebtMinutes', $item);
        self::assertNull($item['acceptedLevel']);
    }

    #[Test]
    public function itProjectsCanonicalIdentityWithoutParsingTheSubject(): void
    {
        $logical = SymbolPath::forMethod('App\\Service', 'ExampleService', 'run');
        $subject = MetricSubject::declaration(DeclarationPath::of($logical, RelativePath::fromString('src/Service/ExampleService.php'), DeclarationOrdinal::fromRank(0)));
        $target = SymbolPath::forClass('App\\Dependency', 'Target');

        $result = $this->section->format([self::violation(
            location: new Location(RelativePath::fromString('src/Service/ExampleService.php'), 15),
            subject: $subject,
            symbolPath: $logical,
            ruleName: 'architecture.layer-violation',
            violationCode: 'architecture.layer-violation',
            message: 'Forbidden dependency',
            severity: Severity::Error,
            dependencyTarget: $target,
            dependencyType: DependencyType::New_,
            occurrenceKey: OccurrenceKey::semantic('dependency', ['name' => 'target']),
        )], new FormatterContext());

        self::assertSame($subject->toCanonical(), $result[0]['subject']);
        self::assertSame($logical->toString(), $result[0]['symbol']);
        self::assertSame('architecture.layer-violation#architecture.layer-violation', $result[0]['channel']);
        self::assertSame(OccurrenceKey::semantic('dependency', ['name' => 'target'])->value, $result[0]['occurrence']);
        self::assertSame([
            'type' => DependencyType::New_->value,
            'target' => $target->toCanonical(),
        ], $result[0]['edge']);
    }

    #[Test]
    public function itProjectsATargetOnlyEdgeWithoutInventingAType(): void
    {
        $target = SymbolPath::forClass('App', 'Target');
        $result = $this->section->format([self::violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 10),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/Foo.php')),
            ruleName: 'r',
            violationCode: 'r.edge',
            message: 'target only',
            severity: Severity::Warning,
            dependencyTarget: $target,
        )], new FormatterContext());

        self::assertSame(['target' => 'class:App\\Target'], $result[0]['edge']);
    }

    #[Test]
    public function itIncludesTheAcceptedLevelOnAMagnitudeBreach(): void
    {
        $violation = (self::violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
            symbolPath: SymbolPath::forMethod('App\\Service', 'UserService', 'process'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Cyclomatic complexity is 31',
            severity: Severity::Warning,
            metricValue: 31,
        ))->reportedAsBreach(new AcceptedLevel([25.0], 1));

        $result = $this->section->format([$violation], new FormatterContext());

        self::assertSame(
            ['shape' => 'magnitude', 'describe' => '25', 'count' => 1],
            $result[0]['acceptedLevel'],
        );
        // Promotion propagates through the ordinary severity field.
        self::assertSame('error', $result[0]['severity']);
    }

    #[Test]
    public function itIncludesTheAcceptedLevelOnAnOccurrenceBreachWithoutMetricValue(): void
    {
        $violation = (self::violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 5),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/Foo.php')),
            ruleName: 'code-smell.goto',
            violationCode: 'code-smell.goto',
            message: 'goto statement used',
            severity: Severity::Warning,
        ))->reportedAsBreach(new AcceptedLevel(null, 3));

        $result = $this->section->format([$violation], new FormatterContext());

        self::assertSame(
            ['shape' => 'occurrence', 'describe' => '3 occurrences', 'count' => 3],
            $result[0]['acceptedLevel'],
        );
    }

    #[Test]
    public function itFormatsViolationWithNoneLocation(): void
    {
        $violation = self::violation(
            location: Location::none(),
            symbolPath: SymbolPath::forNamespace('App\\Cycle'),
            ruleName: 'architecture.circular-dependency',
            violationCode: 'architecture.circular-dependency',
            message: 'Circular dependency detected',
            severity: Severity::Error,
        );

        $context = new FormatterContext();
        $result = $this->section->format([$violation], $context);

        self::assertCount(1, $result);
        self::assertNull($result[0]['file']);
    }

    #[Test]
    public function itFormatsViolationWithEmptyNamespace(): void
    {
        $violation = self::violation(
            location: new Location(RelativePath::fromString('src/helpers.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/helpers.php')),
            ruleName: 'size.loc',
            violationCode: 'size.loc',
            message: 'File too long',
            severity: Severity::Warning,
        );

        $context = new FormatterContext();
        $result = $this->section->format([$violation], $context);

        self::assertCount(1, $result);
        self::assertNull($result[0]['namespace']);
    }

    #[Test]
    public function itSanitizesNonFiniteMetricValues(): void
    {
        $violation = self::violation(
            location: new Location(RelativePath::fromString('src/Bad.php'), 10),
            symbolPath: SymbolPath::forClass('App', 'Bad'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'Bad value',
            severity: Severity::Warning,
            metricValue: \NAN,
            threshold: \INF,
        );

        $context = new FormatterContext();
        $result = $this->section->format([$violation], $context);

        self::assertNull($result[0]['metricValue']);
        self::assertNull($result[0]['threshold']);
    }

    // --- sort ---

    #[Test]
    public function itSortsByCanonicalSubjectAfterChannel(): void
    {
        $warning = self::violation(
            location: new Location(RelativePath::fromString('a.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('a.php')),
            ruleName: 'size.loc',
            violationCode: 'size.loc',
            message: 'warning',
            severity: Severity::Warning,
        );

        $error = self::violation(
            location: new Location(RelativePath::fromString('b.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('b.php')),
            ruleName: 'size.loc',
            violationCode: 'size.loc',
            message: 'error',
            severity: Severity::Error,
        );

        $sorted = $this->section->sort([$warning, $error]);

        self::assertSame(Severity::Warning, $sorted[0]->severity);
        self::assertSame(Severity::Error, $sorted[1]->severity);
    }

    #[Test]
    public function itSortsByCanonicalSubjectInsteadOfExceedance(): void
    {
        $low = self::violation(
            location: new Location(RelativePath::fromString('a.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('a.php')),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'low exceedance',
            severity: Severity::Warning,
            metricValue: 12,
            threshold: 10,
        );

        $high = self::violation(
            location: new Location(RelativePath::fromString('b.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('b.php')),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic',
            message: 'high exceedance',
            severity: Severity::Warning,
            metricValue: 50,
            threshold: 10,
        );

        $sorted = $this->section->sort([$low, $high]);

        self::assertSame('low exceedance', $sorted[0]->message);
        self::assertSame('high exceedance', $sorted[1]->message);
    }

    #[Test]
    public function itSortsByChannelSubjectOccurrenceAndEdge(): void
    {
        $v1 = self::violation(
            location: new Location(RelativePath::fromString('b.php'), 10),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('b.php')),
            ruleName: 'r',
            violationCode: 'r.a',
            message: 'v1',
            severity: Severity::Warning,
        );

        $v2 = self::violation(
            location: new Location(RelativePath::fromString('a.php'), 20),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('a.php')),
            ruleName: 'r',
            violationCode: 'r.a',
            message: 'v2',
            severity: Severity::Warning,
        );

        $v3 = self::violation(
            location: new Location(RelativePath::fromString('a.php'), 10),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('a.php')),
            ruleName: 'r',
            violationCode: 'r.a',
            message: 'v3',
            severity: Severity::Warning,
            occurrenceKey: OccurrenceKey::semantic('test', ['id' => 1]),
        );

        $v4 = self::violation(
            location: new Location(RelativePath::fromString('a.php'), 5),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('a.php')),
            ruleName: 'r',
            violationCode: 'r.a',
            message: 'v4',
            severity: Severity::Warning,
            occurrenceKey: OccurrenceKey::semantic('test', ['id' => 1]),
            dependencyTarget: SymbolPath::forClass('App', 'Target'),
            dependencyType: DependencyType::New_,
        );

        $sorted = $this->section->sort([$v1, $v4, $v3, $v2]);

        self::assertSame('v2', $sorted[0]->message);
        self::assertSame('v3', $sorted[1]->message);
        self::assertSame('v4', $sorted[2]->message);
        self::assertSame('v1', $sorted[3]->message);
    }

    #[Test]
    public function itSortsTheExactNoEdgeUntypedAndTypedMatrix(): void
    {
        $make = static fn(
            string $message,
            ?SymbolPath $target = null,
            ?DependencyType $type = null,
        ): Violation => self::violation(
            location: new Location(RelativePath::fromString('matrix.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('matrix.php')),
            ruleName: 'r',
            violationCode: 'r.edge',
            message: $message,
            severity: Severity::Warning,
            dependencyTarget: $target,
            dependencyType: $type,
        );
        $alpha = SymbolPath::forClass('App', 'Alpha');
        $beta = SymbolPath::forClass('App', 'Beta');
        $zulu = SymbolPath::forClass('App', 'Zulu');

        $sorted = $this->section->sort([
            $make('type_hint/Alpha', $alpha, DependencyType::TypeHint),
            $make('untyped/Beta', $beta),
            $make('new/Zulu', $zulu, DependencyType::New_),
            $make('no-edge'),
            $make('new/Alpha', $alpha, DependencyType::New_),
            $make('untyped/Alpha', $alpha),
        ]);

        self::assertSame([
            'no-edge',
            'untyped/Alpha',
            'untyped/Beta',
            'new/Alpha',
            'new/Zulu',
            'type_hint/Alpha',
        ], array_map(static fn(Violation $violation): string => $violation->message, $sorted));
    }

    #[Test]
    public function itSortsByChannelWithoutReadingMetricValues(): void
    {
        $inf = self::violation(
            location: new Location(RelativePath::fromString('a.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('a.php')),
            ruleName: 'r',
            violationCode: 'r.a',
            message: 'inf',
            severity: Severity::Warning,
            metricValue: \INF,
            threshold: 10,
        );

        $normal = self::violation(
            location: new Location(RelativePath::fromString('b.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('b.php')),
            ruleName: 'r',
            violationCode: 'r.b',
            message: 'normal',
            severity: Severity::Warning,
            metricValue: 20,
            threshold: 10,
        );

        $sorted = $this->section->sort([$inf, $normal]);

        self::assertSame('inf', $sorted[0]->message);
        self::assertSame('normal', $sorted[1]->message);
    }

    // --- countByRule ---

    #[Test]
    public function itCountsByRuleWhenEmpty(): void
    {
        self::assertSame([], $this->section->countByRule([]));
    }

    #[Test]
    public function itCountsByRuleGroupsAndSortsDescending(): void
    {
        $makeViolation = static fn(string $rule): Violation => self::violation(
            location: new Location(RelativePath::fromString('f.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('f.php')),
            ruleName: $rule,
            violationCode: $rule,
            message: 'msg',
            severity: Severity::Warning,
        );

        $violations = [
            $makeViolation('size.loc'),
            $makeViolation('complexity.cyclomatic'),
            $makeViolation('size.loc'),
            $makeViolation('size.loc'),
            $makeViolation('complexity.cyclomatic'),
        ];

        $counts = $this->section->countByRule($violations);

        self::assertSame(['size.loc' => 3, 'complexity.cyclomatic' => 2], $counts);
    }

    /**
     * Builds a violation fixture with an explicit declaration or aggregate
     * subject, preserving the production contract without hiding it behind a
     * legacy fallback.
     *
     * @param list<\Qualimetrix\Analysis\Finding\Contract\Location> $relatedLocations
     */
    private static function violation(
        \Qualimetrix\Analysis\Finding\Contract\Location $location,
        \Qualimetrix\Core\Symbol\SymbolPath $symbolPath,
        string $ruleName,
        string $violationCode,
        string $message,
        \Qualimetrix\Analysis\Finding\Contract\Severity $severity,
        int|float|null $metricValue = null,
        ?\Qualimetrix\Analysis\Finding\Contract\Rule\RuleLevel $level = null,
        array $relatedLocations = [],
        ?string $recommendation = null,
        int|float|null $threshold = null,
        ?\Qualimetrix\Core\Symbol\SymbolPath $dependencyTarget = null,
        ?\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType $dependencyType = null,
        ?\Qualimetrix\Analysis\Finding\Contract\AcceptedLevel $acceptedLevel = null,
        ?\Qualimetrix\Analysis\Finding\Contract\OccurrenceKey $occurrenceKey = null,
        ?\Qualimetrix\Core\Symbol\MetricSubject $subject = null,
    ): Violation {
        $subject ??= match ($symbolPath->getType()) {
            \Qualimetrix\Core\Symbol\SymbolType::File,
            \Qualimetrix\Core\Symbol\SymbolType::Namespace_,
            \Qualimetrix\Core\Symbol\SymbolType::Project => \Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath),
            default => \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($symbolPath, $location->file ?? \Qualimetrix\Core\Path\RelativePath::fromString('tests/Reporting/fixture.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
        };

        return new Violation(
            location: $location,
            subject: $subject,
            symbolPath: $symbolPath,
            ruleName: $ruleName,
            violationCode: $violationCode,
            message: $message,
            severity: $severity,
            metricValue: $metricValue,
            level: $level,
            relatedLocations: $relatedLocations,
            recommendation: $recommendation,
            threshold: $threshold,
            dependencyTarget: $dependencyTarget,
            dependencyType: $dependencyType,
            acceptedLevel: $acceptedLevel,
            occurrenceKey: $occurrenceKey,
        );
    }

}
