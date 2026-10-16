<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\Formatter\Summary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown\WorstClassDrillDown;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderEvidence;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\DrillDown\FindingFilter;
use Qualimetrix\Reporting\Formatter\Ansi\AnsiColor;
use Qualimetrix\Reporting\Formatter\Summary\OffenderListRenderer;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Report;

#[CoversClass(OffenderListRenderer::class)]
final class OffenderListRendererDensityTest extends TestCase
{
    private OffenderListRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new OffenderListRenderer(
            new FindingFilter(),
            new WorstClassDrillDown(),
        );
    }

    #[Test]
    public function itDisplaysDensityInWorstClassesMeta(): void
    {
        $offender = new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of(SymbolPath::forClass('App\\Service', 'HeavyService'), RelativePath::fromString('src/Service/HeavyService.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            healthOverall: 30.0,
            label: 'Poor',
            reason: 'high complexity',
            evidence: new WorstOffenderEvidence(
                violationCount: 10,
                classCount: 0,
                violationDensity: 5.0,
            ),
            overallThresholds: [50.0, 30.0],
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            worstClasses: [$offender],
        );

        $color = new AnsiColor(false);
        $context = new FormatterContext(useColor: false, options: ['top' => '10']);
        $lines = [];

        $this->renderer->renderWorstClasses($report, $color, $context, $lines);

        $output = implode("\n", $lines);
        self::assertStringContainsString('10 violations', $output);
        self::assertStringContainsString('5.0/100 LOC', $output);
    }

    #[Test]
    public function itDoesNotDisplayDensityWhenZero(): void
    {
        $offender = new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of(SymbolPath::forClass('App\\Service', 'CleanService'), RelativePath::fromString('src/Service/CleanService.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            healthOverall: 80.0,
            label: 'Good',
            reason: '',
            evidence: new WorstOffenderEvidence(
                violationCount: 0,
                classCount: 0,
                violationDensity: 0.0,
            ),
            overallThresholds: [50.0, 30.0],
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            worstClasses: [$offender],
        );

        $color = new AnsiColor(false);
        $context = new FormatterContext(useColor: false, options: ['top' => '10']);
        $lines = [];

        $this->renderer->renderWorstClasses($report, $color, $context, $lines);

        $output = implode("\n", $lines);
        self::assertStringNotContainsString('/100 LOC', $output);
    }

    #[Test]
    public function itDoesNotDisplayDensityWhenNull(): void
    {
        $offender = new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of(SymbolPath::forClass('App\\Service', 'NoLocService'), RelativePath::fromString('src/Service/NoLocService.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            healthOverall: 40.0,
            label: 'Poor',
            reason: '',
            evidence: new WorstOffenderEvidence(
                violationCount: 5,
                classCount: 0,
                violationDensity: null,
            ),
            overallThresholds: [50.0, 30.0],
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            worstClasses: [$offender],
        );

        $color = new AnsiColor(false);
        $context = new FormatterContext(useColor: false, options: ['top' => '10']);
        $lines = [];

        $this->renderer->renderWorstClasses($report, $color, $context, $lines);

        $output = implode("\n", $lines);
        self::assertStringNotContainsString('/100 LOC', $output);
        self::assertStringContainsString('5 violations', $output);
    }

    #[Test]
    public function itReordersOffendersWhenRankingByDensity(): void
    {
        // Class A: 5 findings, 100 LOC => density = 5.0 (highest density)
        $offenderA = new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of(SymbolPath::forClass('App', 'SmallBad'), RelativePath::fromString('a.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            healthOverall: 40.0,
            label: 'Poor',
            reason: '',
            evidence: new WorstOffenderEvidence(
                violationCount: 5,
                classCount: 0,
                violationDensity: 5.0,
            ),
            overallThresholds: [50.0, 30.0],
        );

        // Class B: 10 findings, 1000 LOC => density = 1.0 (lower density but more findings)
        $offenderB = new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of(SymbolPath::forClass('App', 'BigBad'), RelativePath::fromString('b.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            healthOverall: 35.0,
            label: 'Poor',
            reason: '',
            evidence: new WorstOffenderEvidence(
                violationCount: 10,
                classCount: 0,
                violationDensity: 1.0,
            ),
            overallThresholds: [50.0, 30.0],
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            worstClasses: [$offenderB, $offenderA], // B first by health score
        );

        $color = new AnsiColor(false);
        $context = new FormatterContext(useColor: false, options: ['top' => '10', 'rank-by' => 'density']);
        $lines = [];

        $this->renderer->renderWorstClasses($report, $color, $context, $lines);

        $output = implode("\n", $lines);
        // SmallBad (density 5.0) should appear before BigBad (density 1.0)
        $posSmallBad = strpos($output, 'SmallBad');
        $posBigBad = strpos($output, 'BigBad');
        self::assertNotFalse($posSmallBad);
        self::assertNotFalse($posBigBad);
        self::assertLessThan($posBigBad, $posSmallBad, 'SmallBad should appear before BigBad when ranked by density');
    }

    #[Test]
    public function itRanksUnorderedCandidatesByScore(): void
    {
        $offenderA = new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of(SymbolPath::forClass('App', 'SmallBad'), RelativePath::fromString('a.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            healthOverall: 40.0,
            label: 'Poor',
            reason: '',
            evidence: new WorstOffenderEvidence(
                violationCount: 5,
                classCount: 0,
                violationDensity: 5.0,
            ),
            overallThresholds: [50.0, 30.0],
        );

        $offenderB = new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of(SymbolPath::forClass('App', 'BigBad'), RelativePath::fromString('b.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            healthOverall: 35.0,
            label: 'Poor',
            reason: '',
            evidence: new WorstOffenderEvidence(
                violationCount: 10,
                classCount: 0,
                violationDensity: 1.0,
            ),
            overallThresholds: [50.0, 30.0],
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            worstClasses: [$offenderA, $offenderB],
        );

        $color = new AnsiColor(false);
        // Score ranking uses the measured value, independent of input order.
        $context = new FormatterContext(useColor: false, options: ['top' => '10', 'rank-by' => 'score']);
        $lines = [];

        $this->renderer->renderWorstClasses($report, $color, $context, $lines);

        $output = implode("\n", $lines);
        $posBigBad = strpos($output, 'BigBad');
        $posSmallBad = strpos($output, 'SmallBad');
        self::assertNotFalse($posBigBad);
        self::assertNotFalse($posSmallBad);
        self::assertLessThan($posSmallBad, $posBigBad, 'BigBad should appear before SmallBad with default ranking');
    }

    #[Test]
    public function itSortsNullDensityOffendersLastWhenRankingByDensity(): void
    {
        $offenderWithDensity = new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of(SymbolPath::forClass('App', 'ClassA'), RelativePath::fromString('a.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            healthOverall: 40.0,
            label: 'Poor',
            reason: '',
            evidence: new WorstOffenderEvidence(
                violationCount: 3,
                classCount: 0,
                violationDensity: 2.0,
            ),
            overallThresholds: [50.0, 30.0],
        );

        $offenderNullDensity = new WorstOffender(
            subject: \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of(SymbolPath::forClass('App', 'ClassB'), RelativePath::fromString('b.php'), \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            healthOverall: 30.0,
            label: 'Poor',
            reason: '',
            evidence: new WorstOffenderEvidence(
                violationCount: 5,
                classCount: 0,
                violationDensity: null,
            ),
            overallThresholds: [50.0, 30.0],
        );

        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            worstClasses: [$offenderNullDensity, $offenderWithDensity],
        );

        $color = new AnsiColor(false);
        $context = new FormatterContext(useColor: false, options: ['top' => '10', 'rank-by' => 'density']);
        $lines = [];

        $this->renderer->renderWorstClasses($report, $color, $context, $lines);

        $output = implode("\n", $lines);
        $posA = strpos($output, 'ClassA');
        $posB = strpos($output, 'ClassB');
        self::assertNotFalse($posA);
        self::assertNotFalse($posB);
        self::assertLessThan($posB, $posA, 'ClassA (density=2.0) should appear before ClassB (density=null)');
    }
    #[Test]
    public function itCountsAllSelectedCandidatesAndRecommendsOnlyTheFullTop(): void
    {
        $offenders = [];
        for ($index = 1; $index <= 15; ++$index) {
            $path = SymbolPath::forNamespace('App\\N' . str_pad((string) $index, 2, '0', \STR_PAD_LEFT));
            $offenders[] = new WorstOffender(\Qualimetrix\Core\Symbol\MetricSubject::aggregate($path), 82.8, 'Critical', '', new WorstOffenderEvidence(1, 2), [90.0, 85.0]);
        }
        $report = new Report(
            fileNamespaces: \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex::fromRepository(null),
            findings: [],
            filesAnalyzed: 15,
            filesSkipped: 0,
            duration: 0.0,
            errorCount: 0,
            warningCount: 0,
            worstNamespaces: array_reverse($offenders),
        );
        foreach ([3 => 12, 12 => 3] as $top => $remaining) {
            $lines = [];
            $this->renderer->renderWorstNamespaces($report, new AnsiColor(true), new FormatterContext(options: ['top' => (string) $top]), $lines);
            $output = implode("\n", $lines);
            self::assertStringContainsString('+' . $remaining . ' more (use --format-opt=top=15)', $output);
            self::assertStringNotContainsString('--format=html', $output);
            self::assertStringContainsString('2 classes in subtree', $output);
            self::assertStringContainsString("\033[31m82.8\033[0m", $output);
            self::assertStringContainsString('App\\N01', $lines[1]);
        }
        $lines = [];
        $this->renderer->renderWorstNamespaces($report, new AnsiColor(false), new FormatterContext(namespace: \Qualimetrix\Tests\Core\Unit\Pattern\NamespacePatternStub::exact('App\\N14')), $lines);
        self::assertStringContainsString('App\\N14', implode("\n", $lines));
        self::assertStringNotContainsString('more', implode("\n", $lines));
    }

}
