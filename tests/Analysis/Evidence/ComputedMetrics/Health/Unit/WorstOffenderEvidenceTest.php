<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(WorstOffenderEvidence::class)]
final class WorstOffenderEvidenceTest extends TestCase
{
    #[Test]
    public function itConstructsWithMetricsAndHealthScores(): void
    {
        $evidence = new WorstOffenderEvidence(
            violationCount: 8,
            classCount: 1,
            metrics: ['complexity.ccn.avg' => 12.5, 'coupling.cbo' => 15],
            healthScores: ['health.complexity' => 35.0, 'health.coupling' => 25.0],
            violationDensity: 2.5,
        );
        $offender = WorstOffender::fromEvidence(
            SymbolPath::forClass('App\\Service', 'UserService'),
            RelativePath::fromString('src/Service/UserService.php'),
            30.0,
            'UserService',
            'low cohesion, high coupling',
            $evidence,
        );

        self::assertSame('src/Service/UserService.php', $offender->file?->value());
        self::assertSame(['complexity.ccn.avg' => 12.5, 'coupling.cbo' => 15], $offender->metrics);
        self::assertSame(['health.complexity' => 35.0, 'health.coupling' => 25.0], $offender->healthScores);
        self::assertSame(2.5, $offender->violationDensity);
    }
}
