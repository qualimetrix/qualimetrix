<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\ComputedMetrics\Health\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(WorstOffender::class)]
final class WorstOffenderTest extends TestCase
{
    #[Test]
    public function itConstructsWithDefaults(): void
    {
        $symbolPath = SymbolPath::forNamespace('App\\Service');

        $offender = new WorstOffender(
            healthOverall: 45.0,
            label: 'App\\Service',
            reason: 'high complexity',
            evidence: new WorstOffenderEvidence(
                violationCount: 12,
                classCount: 5,
            ),
            subject: MetricSubject::aggregate($symbolPath),
            overallThresholds: [50.0, 30.0],
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

    #[Test]
    public function itDerivesThePublishedSymbolAndFileFromItsExactSubject(): void
    {
        $symbol = SymbolPath::forClass('App', 'Twin');
        $file = RelativePath::fromString('src/Twins.php');
        $subject = MetricSubject::declaration(DeclarationPath::of($symbol, $file, DeclarationOrdinal::fromRank(1)));
        $offender = new WorstOffender($subject, 20.0, 'Critical', '', new WorstOffenderEvidence(0, 0), [50.0, 30.0]);
        self::assertSame($subject, $offender->subject);
        self::assertSame($symbol, $offender->symbolPath);
        self::assertSame($file, $offender->file);
        self::assertSame('src/Twins.php', $offender->pathString());
    }

    #[Test]
    public function itCarriesEveryOptionalEvidenceFieldThroughFromEvidence(): void
    {
        $evidence = new WorstOffenderEvidence(
            violationCount: 8,
            classCount: 1,
            metrics: ['complexity.ccn.avg' => 12.5, 'coupling.cbo' => 15],
            healthScores: ['health.complexity' => 35.0, 'health.coupling' => 25.0],
            violationDensity: 2.5,
        );
        $offender = WorstOffender::fromEvidence(
            MetricSubject::declaration(DeclarationPath::of(SymbolPath::forClass('App\\Service', 'UserService'), RelativePath::fromString('src/Service/UserService.php'), DeclarationOrdinal::fromRank(0))),
            30.0,
            'UserService',
            'low cohesion, high coupling',
            $evidence,
            [50.0, 30.0],
        );

        self::assertSame('src/Service/UserService.php', $offender->file?->value());
        self::assertSame(['complexity.ccn.avg' => 12.5, 'coupling.cbo' => 15], $offender->metrics);
        self::assertSame(['health.complexity' => 35.0, 'health.coupling' => 25.0], $offender->healthScores);
        self::assertSame(2.5, $offender->violationDensity);
    }
    #[Test]
    public function itOrdersDuplicateDeclarationsByFileAndOrdinalForBothRankModes(): void
    {
        $symbol = SymbolPath::forClass('App', 'Twin');
        $offenders = [];
        foreach ([['src/B.php', 0], ['src/A.php', 1], ['src/A.php', 0]] as [$name, $ordinal]) {
            $file = RelativePath::fromString($name);
            $subject = MetricSubject::declaration(DeclarationPath::of($symbol, $file, DeclarationOrdinal::fromRank($ordinal)));
            $offenders[] = new WorstOffender($subject, 0.0, 'Critical', '', new WorstOffenderEvidence(0, 0, [], [], 0.0), [50.0, 30.0]);
        }
        $expected = [$offenders[2], $offenders[1], $offenders[0]];
        foreach ([\Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\RankBy::Score, \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\RankBy::Density] as $mode) {
            self::assertSame($expected, WorstOffender::rank($offenders, $mode));
            self::assertSame($expected, WorstOffender::rank(array_reverse($offenders), $mode));
        }
    }

    #[Test]
    public function itKeepsAbsentDensityAfterMeasuredZeroAndSortsScoresIndependently(): void
    {
        $symbol = SymbolPath::forNamespace('App');
        $subject = MetricSubject::aggregate($symbol);
        $missing = new WorstOffender($subject, 0.0, 'Critical', '', new WorstOffenderEvidence(1, 0, [], [], null), [50.0, 30.0]);
        $zero = new WorstOffender($subject, 30.0, 'Critical', '', new WorstOffenderEvidence(0, 0, [], [], 0.0), [50.0, 30.0]);
        $dense = new WorstOffender($subject, 90.0, 'Good', '', new WorstOffenderEvidence(2, 0, [], [], 4.0), [50.0, 30.0]);
        self::assertSame([$missing, $zero, $dense], WorstOffender::rank([$dense, $zero, $missing], \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\RankBy::Score));
        self::assertSame([$dense, $zero, $missing], WorstOffender::rank([$missing, $zero, $dense], \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\RankBy::Density));
    }

}
