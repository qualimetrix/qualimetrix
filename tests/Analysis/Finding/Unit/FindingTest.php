<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use ReflectionClass;
use ReflectionParameter;

#[CoversClass(Finding::class)]
final class FindingTest extends TestCase
{
    #[Test]
    public function itGetFingerprintForMethod(): void
    {
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
            subject: self::subject(),
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            ruleName: 'cyclomatic-complexity',
            code: 'cyclomatic-complexity',
            message: 'Method has complexity of 15',
            severity: Severity::Warning,
            metricValue: 15,
        );

        self::assertSame(
            'cyclomatic-complexity#cyclomatic-complexity:file:src/test.php',
            $finding->getFingerprint(),
        );
    }

    #[Test]
    public function itGetFingerprintForClass(): void
    {
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 10),
            subject: self::subject(),
            symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
            ruleName: 'class-size',
            code: 'class-size',
            message: 'Class is too large',
            severity: Severity::Error,
        );

        self::assertSame(
            'class-size#class-size:file:src/test.php',
            $finding->getFingerprint(),
        );
    }

    #[Test]
    public function itGetFingerprintForNamespace(): void
    {
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/Service/UserService.php')),
            subject: self::subject(),
            symbolPath: SymbolPath::forNamespace('App\Service'),
            ruleName: 'namespace-size',
            code: 'namespace-size',
            message: 'Namespace has too many classes',
            severity: Severity::Warning,
            metricValue: 50,
        );

        self::assertSame(
            'namespace-size#namespace-size:file:src/test.php',
            $finding->getFingerprint(),
        );
    }

    #[Test]
    public function itGetFingerprintForFile(): void
    {
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/bootstrap.php')),
            subject: self::subject(),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/bootstrap.php')),
            ruleName: 'file-length',
            code: 'file-length',
            message: 'File is too long',
            severity: Severity::Warning,
        );

        self::assertSame(
            'file-length#file-length:file:src/test.php',
            $finding->getFingerprint(),
        );
    }

    #[Test]
    public function itGetFingerprintForGlobalFunction(): void
    {
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/functions.php'), 5),
            subject: self::subject(),
            symbolPath: SymbolPath::forGlobalFunction('', 'myFunction'),
            ruleName: 'cyclomatic-complexity',
            code: 'cyclomatic-complexity',
            message: 'Function has high complexity',
            severity: Severity::Warning,
        );

        self::assertSame(
            'cyclomatic-complexity#cyclomatic-complexity:file:src/test.php',
            $finding->getFingerprint(),
        );
    }

    #[Test]
    public function itFindingProperties(): void
    {
        $location = new Location(RelativePath::fromString('src/test.php'), 42);
        $symbolPath = SymbolPath::forMethod('App', 'Test', 'method');

        $finding = new Finding(
            location: $location,
            subject: self::subject(),
            symbolPath: $symbolPath,
            ruleName: 'test-rule',
            code: 'test-rule',
            message: 'Test message',
            severity: Severity::Error,
            metricValue: 10,
        );

        self::assertSame($location, $finding->location);
        self::assertSame($symbolPath, $finding->symbolPath);
        self::assertSame('test-rule', $finding->ruleName);
        self::assertSame('Test message', $finding->message);
        self::assertSame(Severity::Error, $finding->severity);
        self::assertSame(10, $finding->metricValue);
    }

    #[Test]
    public function itFindingWithNullMetricValue(): void
    {
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/test.php')),
            subject: self::subject(),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/test.php')),
            ruleName: 'test-rule',
            code: 'test-rule',
            message: 'Test message',
            severity: Severity::Warning,
        );

        self::assertNull($finding->metricValue);
    }

    #[Test]
    public function itGetDisplayMessageReturnsHumanMessageWhenAvailable(): void
    {
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/test.php'), 10),
            subject: self::subject(),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            ruleName: 'complexity',
            code: 'complexity.callable',
            message: 'Cyclomatic complexity is 15, exceeds threshold of 10',
            severity: Severity::Error,
            recommendation: 'Cyclomatic complexity: 15 (threshold: 10) — too many code paths',
        );

        self::assertSame(
            'Cyclomatic complexity: 15 (threshold: 10) — too many code paths',
            $finding->getDisplayMessage(),
        );
    }

    #[Test]
    public function itGetDisplayMessageFallsBackToMessageWhenHumanMessageNull(): void
    {
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/test.php'), 10),
            subject: self::subject(),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            ruleName: 'complexity',
            code: 'complexity.callable',
            message: 'Cyclomatic complexity is 15, exceeds threshold of 10',
            severity: Severity::Error,
        );

        self::assertSame(
            'Cyclomatic complexity is 15, exceeds threshold of 10',
            $finding->getDisplayMessage(),
        );
    }

    #[Test]
    public function itExposesItsChannel(): void
    {
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/test.php'), 10),
            subject: self::subject(),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            ruleName: 'architecture.layer-violation',
            code: 'architecture.coverage',
            message: 'Uncovered dependency',
            severity: Severity::Info,
        );

        self::assertTrue(
            $finding->channel()->equals(
                new FindingChannel('architecture.layer-violation', 'architecture.coverage'),
            ),
        );
    }

    #[Test]
    public function itCarriesNoAcceptedLevelUnlessOneIsGiven(): void
    {
        $finding = self::warning();

        self::assertNull($finding->acceptedLevel);
    }

    #[Test]
    public function itReportsItselfAsABreachAtErrorCarryingTheAcceptedLevel(): void
    {
        $level = new AcceptedLevel([25.0], 1);

        $promoted = self::warning()->reportedAsBreach($level);

        self::assertSame(Severity::Error, $promoted->severity);
        self::assertSame($level, $promoted->acceptedLevel);
    }

    #[Test]
    public function itUsesSubjectOccurrenceAndEdgeRatherThanLocationOrMessageForFingerprint(): void
    {
        $path = RelativePath::fromString('src/Foo.php');
        $subject = MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Foo', 'run'), $path, DeclarationOrdinal::fromRank(0)));
        $occurrence = OccurrenceKey::semantic('dependency', ['target' => 'Vendor\\Api']);
        $finding = new Finding(
            location: new Location($path, 900),
            subject: $subject,
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'run'),
            ruleName: 'architecture.layer-violation',
            code: 'architecture.layer-violation',
            message: 'Human-readable display text',
            severity: Severity::Warning,
            occurrenceKey: $occurrence,
            dependencyTarget: SymbolPath::forClass('Vendor', 'Api'),
            dependencyType: DependencyType::New_,
        );

        self::assertSame(
            'architecture.layer-violation#architecture.layer-violation:'
            . $subject->toCanonical() . ':' . $occurrence->value . ':new:class:Vendor\\Api',
            $finding->getFingerprint(),
        );
    }

    #[Test]
    public function itFingerprintsEverySupportedDependencyEdgeShapeWithoutChangingExistingBytes(): void
    {
        $target = SymbolPath::forClass('App', 'Alpha');
        $occurrence = OccurrenceKey::semantic('dependency', ['id' => 1]);
        $prefix = 'rule#code:' . self::subject()->toCanonical() . ':' . $occurrence->value;

        $noEdge = self::fingerprintFinding(null, null, $occurrence);
        $typeWithoutTarget = self::fingerprintFinding(null, DependencyType::New_, $occurrence);
        $untyped = self::fingerprintFinding($target, null, $occurrence);
        $typed = self::fingerprintFinding($target, DependencyType::New_, $occurrence);

        self::assertSame($prefix, $noEdge->getFingerprint());
        self::assertSame($prefix, $typeWithoutTarget->getFingerprint());
        self::assertSame($prefix . ':untyped-edge:15:class:App\\Alpha', $untyped->getFingerprint());
        self::assertSame($prefix . ':new:class:App\\Alpha', $typed->getFingerprint());
        self::assertNotSame(
            $untyped->getFingerprint(),
            self::fingerprintFinding(SymbolPath::forClass('App', 'Beta'), null, $occurrence)->getFingerprint(),
        );
        self::assertNotSame($untyped->getFingerprint(), $typed->getFingerprint());
        self::assertNotContains('untyped-edge', array_map(
            static fn(DependencyType $type): string => $type->value,
            DependencyType::cases(),
        ));
    }

    /**
     * Every field {@see Finding::reportedAsBreach()} is supposed to carry
     * across, asserted one by one — **and the list itself checked against the
     * constructor by reflection**, because a hand-written list of twelve
     * assertions is exactly as forgettable as the constructor call it guards.
     * A field added with a default would otherwise be copied nowhere and
     * asserted nowhere, and every test in the suite would stay green.
     *
     * Each constructor parameter is therefore either named here as copied or
     * named below as rewritten; an unaccounted one fails the test, and so
     * does a name here that the constructor no longer has.
     */
    #[Test]
    public function itCopiesEveryOtherFieldWhenItReportsItselfAsABreach(): void
    {
        $original = self::warning();

        $promoted = $original->reportedAsBreach(new AcceptedLevel(null, 1));

        /** @var array<string, array{mixed, mixed}> $copied */
        $copied = [
            'location' => [$original->location, $promoted->location],
            'subject' => [$original->subject, $promoted->subject],
            'symbolPath' => [$original->symbolPath, $promoted->symbolPath],
            'ruleName' => [$original->ruleName, $promoted->ruleName],
            'code' => [$original->code, $promoted->code],
            'message' => [$original->message, $promoted->message],
            'metricValue' => [$original->metricValue, $promoted->metricValue],
            'relatedLocations' => [$original->relatedLocations, $promoted->relatedLocations],
            'recommendation' => [$original->recommendation, $promoted->recommendation],
            'threshold' => [$original->threshold, $promoted->threshold],
            'dependencyTarget' => [$original->dependencyTarget, $promoted->dependencyTarget],
            'dependencyType' => [$original->dependencyType, $promoted->dependencyType],
            'occurrenceKey' => [$original->occurrenceKey, $promoted->occurrenceKey],
        ];

        foreach ($copied as $field => [$before, $after]) {
            self::assertSame($before, $after, \sprintf('reportedAsBreach() did not carry over $%s', $field));
        }

        // The two the promotion is *about*, asserted by the case above.
        $rewritten = ['severity', 'acceptedLevel'];
        $accounted = [...array_keys($copied), ...$rewritten];
        $parameters = self::constructorParametersOfFinding();

        self::assertSame(
            [],
            array_values(array_diff($parameters, $accounted)),
            'a new Finding field must be copied by reportedAsBreach() and listed here, or listed as rewritten',
        );
        self::assertSame(
            [],
            array_values(array_diff($accounted, $parameters)),
            'this test names a constructor parameter Finding no longer has',
        );
    }

    /**
     * @return list<string>
     */
    private static function constructorParametersOfFinding(): array
    {
        $constructor = (new ReflectionClass(Finding::class))->getConstructor();

        self::assertNotNull($constructor, 'Finding is constructed by hand, so it has a constructor to read');

        return array_map(
            static fn(ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        );
    }

    private static function warning(): Finding
    {
        return new Finding(
            location: new Location(RelativePath::fromString('src/test.php'), 10),
            subject: self::subject(),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity.cyclomatic',
            code: 'complexity.cyclomatic.callable',
            message: 'Cyclomatic complexity is 31',
            severity: Severity::Warning,
            metricValue: 31,
            relatedLocations: [new Location(RelativePath::fromString('src/other.php'), 3)],
            recommendation: 'Split the method',
            threshold: 10,
            dependencyTarget: SymbolPath::forClass('App', 'Bar'),
            dependencyType: DependencyType::New_,
        );
    }

    private static function fingerprintFinding(
        ?SymbolPath $target,
        ?DependencyType $type,
        ?OccurrenceKey $occurrence = null,
    ): Finding {
        return new Finding(
            location: new Location(RelativePath::fromString('src/test.php'), 10),
            subject: self::subject(),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/test.php')),
            ruleName: 'rule',
            code: 'code',
            message: 'edge shape',
            severity: Severity::Warning,
            dependencyTarget: $target,
            dependencyType: $type,
            occurrenceKey: $occurrence,
        );
    }

    private static function subject(): MetricSubject
    {
        return MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/test.php')));
    }
}
