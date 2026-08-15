<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleLevel;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use ReflectionClass;
use ReflectionParameter;

#[CoversClass(Violation::class)]
final class ViolationTest extends TestCase
{
    #[Test]
    public function itGetFingerprintForMethod(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
            subject: self::subject(),
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            ruleName: 'cyclomatic-complexity',
            violationCode: 'cyclomatic-complexity',
            message: 'Method has complexity of 15',
            severity: Severity::Warning,
            metricValue: 15,
        );

        self::assertSame(
            'cyclomatic-complexity#cyclomatic-complexity:file:src/test.php',
            $violation->getFingerprint(),
        );
    }

    #[Test]
    public function itGetFingerprintForClass(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 10),
            subject: self::subject(),
            symbolPath: SymbolPath::forClass('App\Service', 'UserService'),
            ruleName: 'class-size',
            violationCode: 'class-size',
            message: 'Class is too large',
            severity: Severity::Error,
        );

        self::assertSame(
            'class-size#class-size:file:src/test.php',
            $violation->getFingerprint(),
        );
    }

    #[Test]
    public function itGetFingerprintForNamespace(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php')),
            subject: self::subject(),
            symbolPath: SymbolPath::forNamespace('App\Service'),
            ruleName: 'namespace-size',
            violationCode: 'namespace-size',
            message: 'Namespace has too many classes',
            severity: Severity::Warning,
            metricValue: 50,
        );

        self::assertSame(
            'namespace-size#namespace-size:file:src/test.php',
            $violation->getFingerprint(),
        );
    }

    #[Test]
    public function itGetFingerprintForFile(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/bootstrap.php')),
            subject: self::subject(),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/bootstrap.php')),
            ruleName: 'file-length',
            violationCode: 'file-length',
            message: 'File is too long',
            severity: Severity::Warning,
        );

        self::assertSame(
            'file-length#file-length:file:src/test.php',
            $violation->getFingerprint(),
        );
    }

    #[Test]
    public function itGetFingerprintForGlobalFunction(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/functions.php'), 5),
            subject: self::subject(),
            symbolPath: SymbolPath::forGlobalFunction('', 'myFunction'),
            ruleName: 'cyclomatic-complexity',
            violationCode: 'cyclomatic-complexity',
            message: 'Function has high complexity',
            severity: Severity::Warning,
        );

        self::assertSame(
            'cyclomatic-complexity#cyclomatic-complexity:file:src/test.php',
            $violation->getFingerprint(),
        );
    }

    #[Test]
    public function itViolationProperties(): void
    {
        $location = new Location(RelativePath::fromString('src/test.php'), 42);
        $symbolPath = SymbolPath::forMethod('App', 'Test', 'method');

        $violation = new Violation(
            location: $location,
            subject: self::subject(),
            symbolPath: $symbolPath,
            ruleName: 'test-rule',
            violationCode: 'test-rule',
            message: 'Test message',
            severity: Severity::Error,
            metricValue: 10,
        );

        self::assertSame($location, $violation->location);
        self::assertSame($symbolPath, $violation->symbolPath);
        self::assertSame('test-rule', $violation->ruleName);
        self::assertSame('Test message', $violation->message);
        self::assertSame(Severity::Error, $violation->severity);
        self::assertSame(10, $violation->metricValue);
    }

    #[Test]
    public function itViolationWithNullMetricValue(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/test.php')),
            subject: self::subject(),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/test.php')),
            ruleName: 'test-rule',
            violationCode: 'test-rule',
            message: 'Test message',
            severity: Severity::Warning,
        );

        self::assertNull($violation->metricValue);
    }

    #[Test]
    public function itViolationWithLevel(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
            subject: self::subject(),
            symbolPath: SymbolPath::forMethod('App\Service', 'UserService', 'calculate'),
            ruleName: 'complexity',
            violationCode: 'complexity',
            message: 'Method has complexity of 15',
            severity: Severity::Warning,
            metricValue: 15,
            level: RuleLevel::Callable,
        );

        self::assertSame(RuleLevel::Callable, $violation->level);
    }

    #[Test]
    public function itViolationWithNullLevel(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/test.php')),
            subject: self::subject(),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/test.php')),
            ruleName: 'test-rule',
            violationCode: 'test-rule',
            message: 'Test message',
            severity: Severity::Warning,
        );

        self::assertNull($violation->level);
    }

    #[Test]
    public function itGetDisplayMessageReturnsHumanMessageWhenAvailable(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/test.php'), 10),
            subject: self::subject(),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            ruleName: 'complexity',
            violationCode: 'complexity.callable',
            message: 'Cyclomatic complexity is 15, exceeds threshold of 10',
            severity: Severity::Error,
            recommendation: 'Cyclomatic complexity: 15 (threshold: 10) — too many code paths',
        );

        self::assertSame(
            'Cyclomatic complexity: 15 (threshold: 10) — too many code paths',
            $violation->getDisplayMessage(),
        );
    }

    #[Test]
    public function itGetDisplayMessageFallsBackToMessageWhenHumanMessageNull(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/test.php'), 10),
            subject: self::subject(),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            ruleName: 'complexity',
            violationCode: 'complexity.callable',
            message: 'Cyclomatic complexity is 15, exceeds threshold of 10',
            severity: Severity::Error,
        );

        self::assertSame(
            'Cyclomatic complexity is 15, exceeds threshold of 10',
            $violation->getDisplayMessage(),
        );
    }

    #[Test]
    public function itExposesItsChannel(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/test.php'), 10),
            subject: self::subject(),
            symbolPath: SymbolPath::forClass('App', 'Foo'),
            ruleName: 'architecture.layer-violation',
            violationCode: 'architecture.coverage',
            message: 'Uncovered dependency',
            severity: Severity::Info,
        );

        self::assertTrue(
            $violation->channel()->equals(
                new ViolationChannel('architecture.layer-violation', 'architecture.coverage'),
            ),
        );
    }

    #[Test]
    public function itCarriesNoAcceptedLevelUnlessOneIsGiven(): void
    {
        $violation = self::warning();

        self::assertNull($violation->acceptedLevel);
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
        $subject = MetricSubject::declaration(new DeclarationPath(
            SymbolPath::forMethod('App', 'Foo', 'run'),
            $path,
            120,
        ));
        $occurrence = OccurrenceKey::semantic('dependency', ['target' => 'Vendor\\Api']);
        $violation = new Violation(
            location: new Location($path, 900),
            subject: $subject,
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'run'),
            ruleName: 'architecture.layer-violation',
            violationCode: 'architecture.layer-violation',
            message: 'Human-readable display text',
            severity: Severity::Warning,
            occurrenceKey: $occurrence,
            dependencyTarget: SymbolPath::forClass('Vendor', 'Api'),
            dependencyType: DependencyType::New_,
        );

        self::assertSame(
            'architecture.layer-violation#architecture.layer-violation:'
            . $subject->toCanonical() . ':' . $occurrence->value . ':new:class:Vendor\\Api',
            $violation->getFingerprint(),
        );
    }

    #[Test]
    public function itFingerprintsEverySupportedDependencyEdgeShapeWithoutChangingExistingBytes(): void
    {
        $target = SymbolPath::forClass('App', 'Alpha');
        $occurrence = OccurrenceKey::semantic('dependency', ['id' => 1]);
        $prefix = 'rule#code:' . self::subject()->toCanonical() . ':' . $occurrence->value;

        $noEdge = self::fingerprintViolation(null, null, $occurrence);
        $typeWithoutTarget = self::fingerprintViolation(null, DependencyType::New_, $occurrence);
        $untyped = self::fingerprintViolation($target, null, $occurrence);
        $typed = self::fingerprintViolation($target, DependencyType::New_, $occurrence);

        self::assertSame($prefix, $noEdge->getFingerprint());
        self::assertSame($prefix, $typeWithoutTarget->getFingerprint());
        self::assertSame($prefix . ':untyped-edge:15:class:App\\Alpha', $untyped->getFingerprint());
        self::assertSame($prefix . ':new:class:App\\Alpha', $typed->getFingerprint());
        self::assertNotSame(
            $untyped->getFingerprint(),
            self::fingerprintViolation(SymbolPath::forClass('App', 'Beta'), null, $occurrence)->getFingerprint(),
        );
        self::assertNotSame($untyped->getFingerprint(), $typed->getFingerprint());
        self::assertNotContains('untyped-edge', array_map(
            static fn(DependencyType $type): string => $type->value,
            DependencyType::cases(),
        ));
    }

    /**
     * Every field {@see Violation::reportedAsBreach()} is supposed to carry
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
            'violationCode' => [$original->violationCode, $promoted->violationCode],
            'message' => [$original->message, $promoted->message],
            'metricValue' => [$original->metricValue, $promoted->metricValue],
            'level' => [$original->level, $promoted->level],
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
        $parameters = self::constructorParametersOfViolation();

        self::assertSame(
            [],
            array_values(array_diff($parameters, $accounted)),
            'a new Violation field must be copied by reportedAsBreach() and listed here, or listed as rewritten',
        );
        self::assertSame(
            [],
            array_values(array_diff($accounted, $parameters)),
            'this test names a constructor parameter Violation no longer has',
        );
    }

    /**
     * @return list<string>
     */
    private static function constructorParametersOfViolation(): array
    {
        $constructor = (new ReflectionClass(Violation::class))->getConstructor();

        self::assertNotNull($constructor, 'Violation is constructed by hand, so it has a constructor to read');

        return array_map(
            static fn(ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        );
    }

    private static function warning(): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('src/test.php'), 10),
            subject: self::subject(),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'Cyclomatic complexity is 31',
            severity: Severity::Warning,
            metricValue: 31,
            level: RuleLevel::Callable,
            relatedLocations: [new Location(RelativePath::fromString('src/other.php'), 3)],
            recommendation: 'Split the method',
            threshold: 10,
            dependencyTarget: SymbolPath::forClass('App', 'Bar'),
            dependencyType: DependencyType::New_,
        );
    }

    private static function fingerprintViolation(
        ?SymbolPath $target,
        ?DependencyType $type,
        ?OccurrenceKey $occurrence = null,
    ): Violation {
        return new Violation(
            location: new Location(RelativePath::fromString('src/test.php'), 10),
            subject: self::subject(),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/test.php')),
            ruleName: 'rule',
            violationCode: 'code',
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
