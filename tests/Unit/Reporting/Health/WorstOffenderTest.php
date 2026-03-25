<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Health\WorstOffender;

#[CoversClass(WorstOffender::class)]
final class WorstOffenderTest extends TestCase
{
    public function testConstructionWithDefaults(): void
    {
        $symbolPath = SymbolPath::forNamespace('App\\Service');

        $offender = new WorstOffender(
            symbolPath: $symbolPath,
            file: null,
            healthOverall: 45.0,
            label: 'App\\Service',
            reason: 'high complexity',
            violationCount: 12,
            classCount: 5,
        );

        self::assertSame($symbolPath, $offender->symbolPath);
        self::assertNull($offender->file);
        self::assertSame(45.0, $offender->healthOverall);
        self::assertSame('App\\Service', $offender->label);
        self::assertSame('high complexity', $offender->reason);
        self::assertSame(12, $offender->violationCount);
        self::assertSame(5, $offender->classCount);
        self::assertSame([], $offender->metrics);
        self::assertSame([], $offender->healthScores);
    }

    public function testConstructionWithMetricsAndHealthScores(): void
    {
        $symbolPath = SymbolPath::forClass('App\\Service', 'UserService');

        $offender = new WorstOffender(
            symbolPath: $symbolPath,
            file: 'src/Service/UserService.php',
            healthOverall: 30.0,
            label: 'UserService',
            reason: 'low cohesion, high coupling',
            violationCount: 8,
            classCount: 1,
            metrics: ['ccn.avg' => 12.5, 'cbo' => 15],
            healthScores: ['health.complexity' => 35.0, 'health.coupling' => 25.0],
        );

        self::assertSame('src/Service/UserService.php', $offender->file);
        self::assertSame(['ccn.avg' => 12.5, 'cbo' => 15], $offender->metrics);
        self::assertSame(['health.complexity' => 35.0, 'health.coupling' => 25.0], $offender->healthScores);
    }
}
