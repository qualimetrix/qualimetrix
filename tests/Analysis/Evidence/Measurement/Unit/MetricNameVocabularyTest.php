<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use ReflectionClass;

/**
 * The properties the key vocabulary has to keep for the rest of the product to
 * read it the way it does.
 */
#[CoversClass(MetricName::class)]
final class MetricNameVocabularyTest extends TestCase
{
    /**
     * `MetricName::base()` decides "the last segment is a strategy or it is part
     * of the name — there is no third case" by asking `AggregationStrategy`.
     * That is sound only while no key ends in a strategy word, and the near
     * misses are dense: `size.class-count`, `size.method-count` and
     * `cohesion.pure-method-count` all end in `-count`. One future key spelled
     * `….count` would make `base()` cut a real segment off, and the failure
     * would be a collector requirement silently unsatisfied rather than an
     * error — which is exactly what it was before Ш5e3 fixed the cut.
     */
    #[Test]
    public function noKeyEndsInASegmentThatIsAnAggregationStrategy(): void
    {
        $strategies = array_map(
            static fn(AggregationStrategy $strategy): string => $strategy->value,
            AggregationStrategy::cases(),
        );

        $offenders = [];

        foreach (self::keys() as $key) {
            $lastSegment = substr($key, (int) strrpos($key, '.') + 1);

            if (str_contains($key, '.') && \in_array($lastSegment, $strategies, true)) {
                $offenders[] = $key;
            }
        }

        self::assertSame([], $offenders, 'these keys are indistinguishable from an aggregated spelling of a shorter key');
    }

    /**
     * A key `B` equal to `A.<strategy>` would make one published spelling belong
     * to two metrics, and nothing downstream could tell which was meant.
     */
    #[Test]
    public function noKeyIsAnAggregatedSpellingOfAnother(): void
    {
        $keys = self::keys();
        $collisions = [];

        foreach ($keys as $key) {
            $base = MetricName::base($key);

            if ($base !== $key && \in_array($base, $keys, true)) {
                $collisions[] = $key;
            }
        }

        self::assertSame([], $collisions);
    }

    /**
     * The grammar the vocabulary is published in: family, then metric, in
     * lower-case kebab.
     */
    #[Test]
    public function everyKeyNamesItsFamilyInKebab(): void
    {
        $malformed = [];

        foreach (self::keys() as $key) {
            if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)+$/', $key) !== 1) {
                $malformed[] = $key;
            }
        }

        self::assertSame([], $malformed);
    }

    /**
     * @return list<string>
     */
    private static function keys(): array
    {
        $constants = (new ReflectionClass(MetricName::class))->getConstants();

        return array_values(array_filter($constants, static fn(mixed $value): bool => \is_string($value)));
    }
}
