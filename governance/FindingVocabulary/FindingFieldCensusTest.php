<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\FindingVocabulary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use ReflectionClass;
use ReflectionParameter;

/**
 * Split off from `FindingTest` (which keeps the hand-built construction
 * cases): every field {@see Finding::reportedAsBreach()} is supposed to
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
        );
    }

    private static function subject(): MetricSubject
    {
        return MetricSubject::aggregate(SymbolPath::forFile(RelativePath::fromString('src/test.php')));
    }
}
