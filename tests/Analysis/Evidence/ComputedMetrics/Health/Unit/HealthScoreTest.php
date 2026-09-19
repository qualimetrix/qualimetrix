<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use Error;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthCoverage;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;
use ReflectionClass;

#[CoversClass(HealthScore::class)]
final class HealthScoreTest extends TestCase
{
    /**
     * ADR 0062: a score that says nothing about what it covers is the state
     * the coverage field exists to end, and a default on that parameter is how
     * it would come back — silently, on the next producer to forget it.
     */
    #[Test]
    public function itRequiresEveryScoreToStateWhatItCovers(): void
    {
        $optional = [];

        foreach ((new ReflectionClass(HealthScore::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                $optional[] = $parameter->getName();
            }
        }

        self::assertSame(['decomposition', 'worstContributors'], $optional);
    }

    #[Test]
    public function itRefusesAWriteToAConstructedScore(): void
    {
        $score = new HealthScore(
            name: 'health.complexity',
            score: 85.0,
            label: 'Fair',
            warningThreshold: 70.0,
            errorThreshold: 40.0,
            coverage: HealthCoverage::notApplicable('fixture: this test is not about coverage'),
        );

        self::expectException(Error::class);
        self::expectExceptionMessage('Cannot modify readonly property');

        // @phpstan-ignore assign.propertyProtectedSet
        $score->label = 'Good';
    }
}
