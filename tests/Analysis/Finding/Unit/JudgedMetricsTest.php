<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\JudgedMetrics;
use ReflectionClass;

#[CoversClass(JudgedMetrics::class)]
final class JudgedMetricsTest extends TestCase
{
    /**
     * The whole point of the type: "at least one judged metric" is a fact
     * about the *signature*, not a runtime refusal that could be edited out
     * of a method body without anything noticing. If the mandatory first
     * parameter ever becomes variadic or optional, `JudgedMetrics::of()`
     * starts accepting the empty list and the guarantee is gone silently —
     * this test is what says so out loud.
     */
    #[Test]
    public function itLeavesTheEmptyMetricListUnstatableThroughTheFactorySignature(): void
    {
        $factory = (new ReflectionClass(JudgedMetrics::class))->getMethod('of');
        $parameters = $factory->getParameters();

        self::assertSame('metricKey', $parameters[0]->getName());
        self::assertFalse($parameters[0]->isVariadic(), 'A variadic first key would make the empty list expressible.');
        self::assertFalse($parameters[0]->isOptional(), 'An optional first key would make the empty list expressible.');
        self::assertSame('moreKeys', $parameters[1]->getName());
        self::assertTrue($parameters[1]->isVariadic());
        self::assertCount(2, $parameters);
    }

    /** Without a private constructor the signature above is not the only way in. */
    #[Test]
    public function itKeepsItsConstructorPrivateSoTheFactoryIsTheOnlyWayIn(): void
    {
        $constructor = (new ReflectionClass(JudgedMetrics::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    #[Test]
    public function itCarriesTheOneKeyItWasGiven(): void
    {
        self::assertSame(
            [MetricName::COHESION_LCOM],
            JudgedMetrics::of(MetricName::COHESION_LCOM)->keys,
        );
    }

    /**
     * Author order is preserved rather than canonicalised, because several
     * keys are alternatives the producer's own body chooses between — sorting
     * them would assert a relation between candidates that does not exist.
     */
    #[Test]
    public function itPreservesTheOrderTheCandidatesWereWrittenIn(): void
    {
        self::assertSame(
            [MetricName::COUPLING_CBO_APP, MetricName::COUPLING_CBO],
            JudgedMetrics::of(MetricName::COUPLING_CBO_APP, MetricName::COUPLING_CBO)->keys,
        );
    }

    /** An aggregate spelling is a key like any other here; the catalog check is registry assembly's. */
    #[Test]
    public function itCarriesAnAggregateSpellingUnchanged(): void
    {
        self::assertSame(
            ['size.class-count.sum'],
            JudgedMetrics::of(MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum))->keys,
        );
    }

    /**
     * A repeated key is refused rather than collapsed: it says the author
     * believes the channel reads that metric under two circumstances, and one
     * of the two is written wrong.
     */
    #[Test]
    public function itRefusesARepeatedKey(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('more than once');

        JudgedMetrics::of(MetricName::COMPLEXITY_CCN, MetricName::COMPLEXITY_CCN);
    }
}
