<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Summary;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Filter\ViolationFilter;
use Qualimetrix\Reporting\Formatter\Summary\OffenderListRenderer;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\MetricHintProvider;
use Qualimetrix\Reporting\Health\NamespaceDrillDown;
use Qualimetrix\Reporting\Health\WorstOffender;
use Qualimetrix\Reporting\Report;

#[CoversClass(OffenderListRenderer::class)]
final class OffenderListRendererDensityTest extends TestCase
{
    private OffenderListRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new OffenderListRenderer(
            new ViolationFilter(),
            new NamespaceDrillDown(new MetricHintProvider()),
        );
    }

    public function testDensityDisplayedInWorstClassesMeta(): void
    {
        $offender = new WorstOffender(
            symbolPath: SymbolPath::forClass('App\\Service', 'HeavyService'),
            file: 'src/Service/HeavyService.php',
            healthOverall: 30.0,
            label: 'Poor',
            reason: 'high complexity',
            violationCount: 10,
            classCount: 0,
            violationDensity: 5.0,
        );

        $report = new Report(
            violations: [],
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

    public function testDensityNotDisplayedWhenZero(): void
    {
        $offender = new WorstOffender(
            symbolPath: SymbolPath::forClass('App\\Service', 'CleanService'),
            file: 'src/Service/CleanService.php',
            healthOverall: 80.0,
            label: 'Good',
            reason: '',
            violationCount: 0,
            classCount: 0,
            violationDensity: 0.0,
        );

        $report = new Report(
            violations: [],
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

    public function testDensityNotDisplayedWhenNull(): void
    {
        $offender = new WorstOffender(
            symbolPath: SymbolPath::forClass('App\\Service', 'NoLocService'),
            file: 'src/Service/NoLocService.php',
            healthOverall: 40.0,
            label: 'Poor',
            reason: '',
            violationCount: 5,
            classCount: 0,
            violationDensity: null,
        );

        $report = new Report(
            violations: [],
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

    public function testRankByDensityReordersOffenders(): void
    {
        // Class A: 5 violations, 100 LOC => density = 5.0 (highest density)
        $offenderA = new WorstOffender(
            symbolPath: SymbolPath::forClass('App', 'SmallBad'),
            file: 'a.php',
            healthOverall: 40.0,
            label: 'Poor',
            reason: '',
            violationCount: 5,
            classCount: 0,
            violationDensity: 5.0,
        );

        // Class B: 10 violations, 1000 LOC => density = 1.0 (lower density but more violations)
        $offenderB = new WorstOffender(
            symbolPath: SymbolPath::forClass('App', 'BigBad'),
            file: 'b.php',
            healthOverall: 35.0,
            label: 'Poor',
            reason: '',
            violationCount: 10,
            classCount: 0,
            violationDensity: 1.0,
        );

        $report = new Report(
            violations: [],
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

    public function testRankByCountPreservesOriginalOrder(): void
    {
        $offenderA = new WorstOffender(
            symbolPath: SymbolPath::forClass('App', 'SmallBad'),
            file: 'a.php',
            healthOverall: 40.0,
            label: 'Poor',
            reason: '',
            violationCount: 5,
            classCount: 0,
            violationDensity: 5.0,
        );

        $offenderB = new WorstOffender(
            symbolPath: SymbolPath::forClass('App', 'BigBad'),
            file: 'b.php',
            healthOverall: 35.0,
            label: 'Poor',
            reason: '',
            violationCount: 10,
            classCount: 0,
            violationDensity: 1.0,
        );

        $report = new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            worstClasses: [$offenderB, $offenderA], // B first (default by health score)
        );

        $color = new AnsiColor(false);
        // rank-by=count (default) => should preserve original order
        $context = new FormatterContext(useColor: false, options: ['top' => '10', 'rank-by' => 'count']);
        $lines = [];

        $this->renderer->renderWorstClasses($report, $color, $context, $lines);

        $output = implode("\n", $lines);
        $posBigBad = strpos($output, 'BigBad');
        $posSmallBad = strpos($output, 'SmallBad');
        self::assertNotFalse($posBigBad);
        self::assertNotFalse($posSmallBad);
        self::assertLessThan($posSmallBad, $posBigBad, 'BigBad should appear before SmallBad with default ranking');
    }

    public function testNullDensityOffendersSortedLastWhenRankByDensity(): void
    {
        $offenderWithDensity = new WorstOffender(
            symbolPath: SymbolPath::forClass('App', 'ClassA'),
            file: 'a.php',
            healthOverall: 40.0,
            label: 'Poor',
            reason: '',
            violationCount: 3,
            classCount: 0,
            violationDensity: 2.0,
        );

        $offenderNullDensity = new WorstOffender(
            symbolPath: SymbolPath::forClass('App', 'ClassB'),
            file: 'b.php',
            healthOverall: 30.0,
            label: 'Poor',
            reason: '',
            violationCount: 5,
            classCount: 0,
            violationDensity: null,
        );

        $report = new Report(
            violations: [],
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
}
