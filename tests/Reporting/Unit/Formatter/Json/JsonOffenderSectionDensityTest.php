<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\Formatter\Json;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown\WorstClassDrillDown;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\DrillDown\FindingFilter;
use Qualimetrix\Reporting\Formatter\Json\JsonOffenderSection;
use Qualimetrix\Reporting\Formatter\Json\JsonSanitizer;
use Qualimetrix\Reporting\FormatterContext;

#[CoversClass(JsonOffenderSection::class)]
final class JsonOffenderSectionDensityTest extends TestCase
{
    private JsonOffenderSection $section;

    protected function setUp(): void
    {
        $this->section = new JsonOffenderSection(
            new WorstClassDrillDown(),
            new FindingFilter(),
            new JsonSanitizer(),
        );
    }

    #[Test]
    public function itIncludesViolationDensityInNamespaceOutput(): void
    {
        $offender = new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::aggregate(SymbolPath::forNamespace('App\\Payment')),
            healthOverall: 35.0,
            label: 'Poor',
            reason: 'high complexity',
            evidence: new WorstOffenderEvidence(
                violationCount: 8,
                classCount: 4,
                violationDensity: 2.5,
            ),
            overallThresholds: [50.0, 30.0],
        );

        $context = new FormatterContext();
        $result = $this->section->formatNamespaces([$offender], $context, 10);

        self::assertCount(1, $result);
        self::assertArrayHasKey('violationDensity', $result[0]);
        self::assertSame(2.5, $result[0]['violationDensity']);
        self::assertSame(8, $result[0]['violationCount']);
    }

    #[Test]
    public function itIncludesViolationDensityInClassOutput(): void
    {
        $offender = new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of(SymbolPath::forClass('App\\Service', 'UserService'), RelativePath::fromString('src/Service/UserService.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            healthOverall: 30.0,
            label: 'Poor',
            reason: '',
            evidence: new WorstOffenderEvidence(
                violationCount: 12,
                classCount: 0,
                violationDensity: 6.0,
            ),
            overallThresholds: [50.0, 30.0],
        );

        $context = new FormatterContext();

        // Use formatNamespaces with showClassCount=false by testing the private method via formatClasses
        // Instead, test with the raw formatWorstOffenders via formatNamespaces (since it delegates)
        // For classes, we need to use formatClasses via Report
        $report = new \Qualimetrix\Reporting\Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            worstClasses: [$offender],
        );

        $result = $this->section->formatClasses($report, $context, 10);

        self::assertCount(1, $result);
        self::assertArrayHasKey('violationDensity', $result[0]);
        self::assertSame(6.0, $result[0]['violationDensity']);
    }

    #[Test]
    public function itPreservesNullDensityInOutput(): void
    {
        $offender = new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::aggregate(SymbolPath::forNamespace('App\\Legacy')),
            healthOverall: 25.0,
            label: 'Poor',
            reason: '',
            evidence: new WorstOffenderEvidence(
                violationCount: 15,
                classCount: 3,
                violationDensity: null,
            ),
            overallThresholds: [50.0, 30.0],
        );

        $context = new FormatterContext();
        $result = $this->section->formatNamespaces([$offender], $context, 10);

        self::assertCount(1, $result);
        self::assertArrayHasKey('violationDensity', $result[0]);
        self::assertNull($result[0]['violationDensity']);
    }

    #[Test]
    public function itReordersNamespacesByDensity(): void
    {
        $highDensity = new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::aggregate(SymbolPath::forNamespace('App\\Small')),
            healthOverall: 45.0,
            label: 'Poor',
            reason: '',
            evidence: new WorstOffenderEvidence(
                violationCount: 5,
                classCount: 2,
                violationDensity: 10.0,
            ),
            overallThresholds: [50.0, 30.0],
        );

        $lowDensity = new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::aggregate(SymbolPath::forNamespace('App\\Large')),
            healthOverall: 30.0,
            label: 'Poor',
            reason: '',
            evidence: new WorstOffenderEvidence(
                violationCount: 20,
                classCount: 10,
                violationDensity: 1.0,
            ),
            overallThresholds: [50.0, 30.0],
        );

        // Default order: lowDensity first (lower health score)
        $context = new FormatterContext(options: ['rank-by' => 'density']);
        $result = $this->section->formatNamespaces([$highDensity, $lowDensity], $context, 10);

        self::assertCount(2, $result);
        // After density ranking, highDensity (10.0) should come first
        self::assertSame('App\\Small', $result[0]['symbolPath']);
        self::assertSame('App\\Large', $result[1]['symbolPath']);
    }

    #[Test]
    public function itRanksUnorderedCandidatesByScore(): void
    {
        $highDensity = new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::aggregate(SymbolPath::forNamespace('App\\Small')),
            healthOverall: 45.0,
            label: 'Poor',
            reason: '',
            evidence: new WorstOffenderEvidence(
                violationCount: 5,
                classCount: 2,
                violationDensity: 10.0,
            ),
            overallThresholds: [50.0, 30.0],
        );

        $lowDensity = new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::aggregate(SymbolPath::forNamespace('App\\Large')),
            healthOverall: 30.0,
            label: 'Poor',
            reason: '',
            evidence: new WorstOffenderEvidence(
                violationCount: 20,
                classCount: 10,
                violationDensity: 1.0,
            ),
            overallThresholds: [50.0, 30.0],
        );

        // Input order is deliberately different from score order.
        $context = new FormatterContext(options: ['rank-by' => 'score']);
        $result = $this->section->formatNamespaces([$highDensity, $lowDensity], $context, 10);

        self::assertCount(2, $result);
        self::assertSame('App\\Large', $result[0]['symbolPath']);
        self::assertSame('App\\Small', $result[1]['symbolPath']);
    }
    #[Test]
    public function itSelectsTheCompleteClassPopulationBeforeRankingAndTop(): void
    {
        $offenders = $this->completeClassPopulation();
        $report = new \Qualimetrix\Reporting\Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 15,
            filesSkipped: 0,
            duration: 0.0,
            errorCount: 0,
            warningCount: 0,
            worstClasses: array_reverse($offenders),
        );
        self::assertCount(15, $this->section->formatClasses($report, new FormatterContext(), 20));
        foreach ([
            \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::exact('App\\N14'),
            \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::regex('^App\\\\N14\\\\C$'),
        ] as $pattern) {
            $result = $this->section->formatClasses($report, new FormatterContext(namespace: $pattern), 20);
            self::assertCount(2, $result);
            self::assertSame([14, 15], array_column(array_column($result, 'metrics'), 'size.class-loc'));
        }
        $selected = $this->section->formatClasses($report, new FormatterContext(class: 'App\\N14\\C', options: ['rank-by' => 'density']), 1);
        self::assertCount(1, $selected);
        self::assertSame(15, $selected[0]['metrics']['size.class-loc']);
        self::assertSame('src/Duplicate.php', $selected[0]['file']);
        self::assertSame(['complexity', 'cohesion', 'coupling', 'typing', 'maintainability'], array_keys($selected[0]['healthScores']));
        self::assertSame(0.0, $selected[0]['healthScores']['typing']);
        self::assertArrayNotHasKey('overall', $selected[0]['healthScores']);
    }

    /** @return list<WorstOffender> */
    private function completeClassPopulation(): array
    {
        $items = [];
        for ($index = 1; $index <= 15; ++$index) {
            $path = SymbolPath::forClass('App\\N' . ($index < 14 ? str_pad((string) $index, 2, '0', \STR_PAD_LEFT) : '14'), 'C');
            $file = RelativePath::fromString($index < 14 ? 'src/C' . $index . '.php' : 'src/Duplicate.php');
            $subject = \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($path, $file, \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank($index === 15 ? 1 : 0)));
            $items[] = new WorstOffender(
                $subject,
                (float) min($index, 14),
                'Poor',
                '',
                new WorstOffenderEvidence(1, 0, ['size.class-loc' => $index], ['complexity' => 10.0, 'cohesion' => 20.0, 'coupling' => 30.0, 'typing' => 0.0, 'maintainability' => 40.0], (float) $index),
                [90.0, 85.0],
            );
        }
        return $items;
    }

}
