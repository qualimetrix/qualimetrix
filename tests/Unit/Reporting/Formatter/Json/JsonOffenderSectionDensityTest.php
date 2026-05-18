<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Json;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Filter\ViolationFilter;
use Qualimetrix\Reporting\Formatter\Json\JsonOffenderSection;
use Qualimetrix\Reporting\Formatter\Json\JsonSanitizer;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\MetricHintProvider;
use Qualimetrix\Reporting\Health\NamespaceDrillDown;
use Qualimetrix\Reporting\Health\WorstOffender;

#[CoversClass(JsonOffenderSection::class)]
final class JsonOffenderSectionDensityTest extends TestCase
{
    private JsonOffenderSection $section;

    protected function setUp(): void
    {
        $this->section = new JsonOffenderSection(
            new NamespaceDrillDown(new MetricHintProvider()),
            new ViolationFilter(),
            new JsonSanitizer(),
        );
    }

    #[Test]
    public function itIncludesViolationDensityInNamespaceOutput(): void
    {
        $offender = new WorstOffender(
            symbolPath: SymbolPath::forNamespace('App\\Payment'),
            file: null,
            healthOverall: 35.0,
            label: 'Poor',
            reason: 'high complexity',
            violationCount: 8,
            classCount: 4,
            violationDensity: 2.5,
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
            symbolPath: SymbolPath::forClass('App\\Service', 'UserService'),
            file: RelativePath::fromString('src/Service/UserService.php'),
            healthOverall: 30.0,
            label: 'Poor',
            reason: '',
            violationCount: 12,
            classCount: 0,
            violationDensity: 6.0,
        );

        $context = new FormatterContext();

        // Use formatNamespaces with showClassCount=false by testing the private method via formatClasses
        // Instead, test with the raw formatWorstOffenders via formatNamespaces (since it delegates)
        // For classes, we need to use formatClasses via Report
        $report = new \Qualimetrix\Reporting\Report(
            violations: [],
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
            symbolPath: SymbolPath::forNamespace('App\\Legacy'),
            file: null,
            healthOverall: 25.0,
            label: 'Poor',
            reason: '',
            violationCount: 15,
            classCount: 3,
            violationDensity: null,
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
            symbolPath: SymbolPath::forNamespace('App\\Small'),
            file: null,
            healthOverall: 45.0,
            label: 'Poor',
            reason: '',
            violationCount: 5,
            classCount: 2,
            violationDensity: 10.0,
        );

        $lowDensity = new WorstOffender(
            symbolPath: SymbolPath::forNamespace('App\\Large'),
            file: null,
            healthOverall: 30.0,
            label: 'Poor',
            reason: '',
            violationCount: 20,
            classCount: 10,
            violationDensity: 1.0,
        );

        // Default order: lowDensity first (lower health score)
        $context = new FormatterContext(options: ['rank-by' => 'density']);
        $result = $this->section->formatNamespaces([$lowDensity, $highDensity], $context, 10);

        self::assertCount(2, $result);
        // After density ranking, highDensity (10.0) should come first
        self::assertSame('App\\Small', $result[0]['symbolPath']);
        self::assertSame('App\\Large', $result[1]['symbolPath']);
    }

    #[Test]
    public function itPreservesOrderWhenRankingByCount(): void
    {
        $highDensity = new WorstOffender(
            symbolPath: SymbolPath::forNamespace('App\\Small'),
            file: null,
            healthOverall: 45.0,
            label: 'Poor',
            reason: '',
            violationCount: 5,
            classCount: 2,
            violationDensity: 10.0,
        );

        $lowDensity = new WorstOffender(
            symbolPath: SymbolPath::forNamespace('App\\Large'),
            file: null,
            healthOverall: 30.0,
            label: 'Poor',
            reason: '',
            violationCount: 20,
            classCount: 10,
            violationDensity: 1.0,
        );

        // Default order preserved
        $context = new FormatterContext(options: ['rank-by' => 'count']);
        $result = $this->section->formatNamespaces([$lowDensity, $highDensity], $context, 10);

        self::assertCount(2, $result);
        self::assertSame('App\\Large', $result[0]['symbolPath']);
        self::assertSame('App\\Small', $result[1]['symbolPath']);
    }
}
