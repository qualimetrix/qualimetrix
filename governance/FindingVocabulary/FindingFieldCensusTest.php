<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\FindingVocabulary;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\Channel\AcceptedLevel;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use ReflectionClass;
use ReflectionParameter;

/**
 * Split off from `FindingTest` (which keeps the hand-built construction
 * cases): every field {@see Finding::reportedAsBreach()} and
 * {@see Finding::reportedUncompared()} is supposed to
 * carry across, asserted one by one — **and the list itself checked against
 * the constructor by reflection**, because a hand-written list of twelve
 * assertions is exactly as forgettable as the constructor call it guards.
 * A field added with a default would otherwise be copied nowhere and
 * asserted nowhere, and every test in the suite would stay green.
 *
 * Each constructor parameter is therefore either named here as copied or
 * named below as rewritten; an unaccounted one fails the test, and so does a
 * name here that the constructor no longer has.
 */
#[CoversClass(Finding::class)]
final class FindingFieldCensusTest extends TestCase
{
    /** @param list<string> $rewritten */
    #[Test]
    #[DataProvider('provideReports')]
    public function itCopiesEveryOtherFieldWhenItReportsItselfAsABreach(string $method, array $rewritten): void
    {
        $original = self::warning();
        $acceptedLevel = new AcceptedLevel([9.0], 1);
        $reported = match ($method) {
            'reportedAsBreach' => $original->reportedAsBreach($acceptedLevel),
            'reportedUncompared' => $original->reportedUncompared($acceptedLevel, 'different-reason'),
            default => throw new LogicException('Unknown Finding report method.'),
        };

        /** @var array<string, array{mixed, mixed}> $copied */
        $copied = [
            'location' => [$original->location, $reported->location],
            'subject' => [$original->subject, $reported->subject],
            'symbolPath' => [$original->symbolPath, $reported->symbolPath],
            'ruleName' => [$original->ruleName, $reported->ruleName],
            'code' => [$original->code, $reported->code],
            'message' => [$original->message, $reported->message],
            'metricValue' => [$original->metricValue, $reported->metricValue],
            'relatedLocations' => [$original->relatedLocations, $reported->relatedLocations],
            'recommendation' => [$original->recommendation, $reported->recommendation],
            'threshold' => [$original->threshold, $reported->threshold],
            'dependencyTarget' => [$original->dependencyTarget, $reported->dependencyTarget],
            'dependencyType' => [$original->dependencyType, $reported->dependencyType],
            'occurrenceKey' => [$original->occurrenceKey, $reported->occurrenceKey],
            'addressedProducer' => [$original->addressedProducer, $reported->addressedProducer],
        ];
        if ($method === 'reportedUncompared') {
            $copied['severity'] = [$original->severity, $reported->severity];
        }

        foreach ($copied as $field => [$before, $after]) {
            self::assertSame($before, $after, \sprintf('%s() did not carry over $%s', $method, $field));
        }

        self::assertSame($method === 'reportedAsBreach' ? Severity::Error : $original->severity, $reported->severity);
        self::assertSame($acceptedLevel, $reported->acceptedLevel);
        self::assertSame($method === 'reportedAsBreach' ? null : 'different-reason', $reported->uncomparedReason);
        $accounted = [...array_keys($copied), ...$rewritten];
        $parameters = self::constructorParametersOfFinding();

        self::assertSame(
            [],
            array_values(array_diff($parameters, $accounted)),
            \sprintf('a new Finding field must be copied by %s() and listed here, or listed as rewritten', $method),
        );
        self::assertSame(
            [],
            array_values(array_diff($accounted, $parameters)),
            'this test names a constructor parameter Finding no longer has',
        );
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function provideReports(): iterable
    {
        yield 'breach' => ['reportedAsBreach', ['severity', 'acceptedLevel', 'uncomparedReason']];
        yield 'uncompared' => ['reportedUncompared', ['acceptedLevel', 'uncomparedReason']];
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
            ruleName: 'complexity.ccn',
            code: 'complexity.ccn',
            message: 'Cyclomatic complexity is 31',
            severity: Severity::Warning,
            metricValue: 31,
            relatedLocations: [new Location(RelativePath::fromString('src/other.php'), 3)],
            recommendation: 'Split the method',
            threshold: 10,
            dependencyTarget: SymbolPath::forClass('App', 'Bar'),
            dependencyType: DependencyType::New_,
            acceptedLevel: new AcceptedLevel([7.0], 1),
            occurrenceKey: OccurrenceKey::semantic('kind', ['k' => 'v']),
            addressedProducer: 'complexity.ccn',
            uncomparedReason: 'analysis-incomplete',
        );
    }

    private static function subject(): MetricSubject
    {
        return MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/test.php')));
    }
}
