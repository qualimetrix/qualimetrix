<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting;

use AiMessDetector\Reporting\DecompositionItem;
use AiMessDetector\Reporting\HealthScore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HealthScore::class)]
final class HealthScoreTest extends TestCase
{
    public function testConstructionWithDefaults(): void
    {
        $score = new HealthScore(
            name: 'health.complexity',
            score: 85.0,
            label: 'Acceptable',
            warningThreshold: 70.0,
            errorThreshold: 40.0,
        );

        self::assertSame('health.complexity', $score->name);
        self::assertSame(85.0, $score->score);
        self::assertSame('Acceptable', $score->label);
        self::assertSame(70.0, $score->warningThreshold);
        self::assertSame(40.0, $score->errorThreshold);
        self::assertSame([], $score->decomposition);
    }

    public function testConstructionWithDecomposition(): void
    {
        $item = new DecompositionItem(
            metricKey: 'ccn.avg',
            humanName: 'Cyclomatic (avg)',
            value: 3.5,
            goodValue: 'below 4',
            direction: 'lower_is_better',
            explanation: 'manageable branching',
        );

        $score = new HealthScore(
            name: 'health.complexity',
            score: 85.0,
            label: 'Acceptable',
            warningThreshold: 70.0,
            errorThreshold: 40.0,
            decomposition: [$item],
        );

        self::assertCount(1, $score->decomposition);
        self::assertSame('ccn.avg', $score->decomposition[0]->metricKey);
    }
}
