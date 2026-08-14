<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(WorstOffender::class)]
final class WorstOffenderTest extends TestCase
{
    #[Test]
    public function itConstructsWithDefaults(): void
    {
        $symbolPath = SymbolPath::forNamespace('App\\Service');

        $offender = new WorstOffender(
            symbolPath: $symbolPath,
            file: null,
            healthOverall: 45.0,
            label: 'App\\Service',
            reason: 'high complexity',
            evidence: new WorstOffenderEvidence(
                violationCount: 12,
                classCount: 5,
            ),
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

}
