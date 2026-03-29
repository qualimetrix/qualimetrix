<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Json;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Reporting\Formatter\Json\JsonHealthSection;
use Qualimetrix\Reporting\Formatter\Json\JsonSanitizer;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\DecompositionItem;
use Qualimetrix\Reporting\Health\HealthContributor;
use Qualimetrix\Reporting\Health\HealthScore;
use Qualimetrix\Reporting\Health\HealthScoreResolver;
use Qualimetrix\Reporting\Health\MetricHintProvider;
use Qualimetrix\Reporting\Health\NamespaceDrillDown;
use Qualimetrix\Reporting\Report;

#[CoversClass(JsonHealthSection::class)]
final class JsonHealthSectionTest extends TestCase
{
    private JsonHealthSection $section;

    protected function setUp(): void
    {
        $resolver = new HealthScoreResolver(new NamespaceDrillDown(new MetricHintProvider()));
        $this->section = new JsonHealthSection($resolver, new JsonSanitizer());
    }

    /**
     * @param array<string, HealthScore> $healthScores
     */
    private function buildReport(array $healthScores = []): Report
    {
        return new Report(
            violations: [],
            filesAnalyzed: 10,
            filesSkipped: 0,
            duration: 1.0,
            errorCount: 0,
            warningCount: 0,
            healthScores: $healthScores,
        );
    }

    public function testFormatReturnsNullWhenNoHealthScoresWithNamespaceFilter(): void
    {
        $report = $this->buildReport();
        $context = new FormatterContext(namespace: 'App\\Service');

        self::assertNull($this->section->format($report, $context));
    }

    public function testFormatReturnsNullForEmptyScoresWithoutNamespace(): void
    {
        $report = $this->buildReport();
        $context = new FormatterContext();

        self::assertNull($this->section->format($report, $context));
    }

    public function testFormatReturnsCorrectStructure(): void
    {
        $decomposition = new DecompositionItem(
            metricKey: 'ccn_avg',
            humanName: 'Average Cyclomatic Complexity',
            value: 5.2,
            goodValue: '< 10',
            direction: 'lower-is-better',
            explanation: 'Average complexity across methods',
        );

        $contributor = new HealthContributor(
            className: 'UserService',
            symbolPath: 'App\\Service::UserService',
            metricValues: ['ccn' => 15.0, 'loc' => 200],
        );

        $healthScore = new HealthScore(
            name: 'complexity',
            score: 72.5,
            label: 'Good',
            warningThreshold: 60.0,
            errorThreshold: 40.0,
            decomposition: [$decomposition],
            worstContributors: [$contributor],
        );

        $report = $this->buildReport(['complexity' => $healthScore]);
        $context = new FormatterContext();

        $result = $this->section->format($report, $context);

        self::assertNotNull($result);
        self::assertArrayHasKey('complexity', $result);

        $complexity = $result['complexity'];
        self::assertSame(72.5, $complexity['score']);
        self::assertSame('Good', $complexity['label']);
        self::assertSame(60.0, $complexity['threshold']['warning']);
        self::assertSame(40.0, $complexity['threshold']['error']);

        // Decomposition
        self::assertCount(1, $complexity['decomposition']);
        $decomItem = $complexity['decomposition'][0];
        self::assertSame('ccn_avg', $decomItem['metric']);
        self::assertSame('Average Cyclomatic Complexity', $decomItem['humanName']);
        self::assertSame(5.2, $decomItem['value']);
        self::assertSame('< 10', $decomItem['good']);
        self::assertSame('lower-is-better', $decomItem['direction']);

        // Worst contributors
        self::assertCount(1, $complexity['worstContributors']);
        $contrib = $complexity['worstContributors'][0];
        self::assertSame('UserService', $contrib['className']);
        self::assertSame('App\\Service::UserService', $contrib['symbolPath']);
        self::assertSame(15.0, $contrib['metrics']['ccn']);
        self::assertSame(200, $contrib['metrics']['loc']);
    }

    public function testFormatSanitizesNonFiniteValues(): void
    {
        $decomposition = new DecompositionItem(
            metricKey: 'mi_avg',
            humanName: 'Maintainability Index',
            value: \NAN,
            goodValue: '> 70',
            direction: 'higher-is-better',
            explanation: 'Average MI',
        );

        $contributor = new HealthContributor(
            className: 'BadService',
            symbolPath: 'App::BadService',
            metricValues: ['mi' => \INF, 'loc' => 50],
        );

        $healthScore = new HealthScore(
            name: 'maintainability',
            score: \INF,
            label: 'N/A',
            warningThreshold: \NAN,
            errorThreshold: -\INF,
            decomposition: [$decomposition],
            worstContributors: [$contributor],
        );

        $report = $this->buildReport(['maintainability' => $healthScore]);
        $context = new FormatterContext();

        $result = $this->section->format($report, $context);

        self::assertNotNull($result);
        $maint = $result['maintainability'];

        self::assertNull($maint['score']);
        self::assertNull($maint['threshold']['warning']);
        self::assertNull($maint['threshold']['error']);
        self::assertNull($maint['decomposition'][0]['value']);
        self::assertNull($maint['worstContributors'][0]['metrics']['mi']);
        self::assertSame(50, $maint['worstContributors'][0]['metrics']['loc']);
    }

    public function testFormatWithNullScore(): void
    {
        $healthScore = new HealthScore(
            name: 'cohesion',
            score: null,
            label: 'N/A',
            warningThreshold: 60.0,
            errorThreshold: 40.0,
        );

        $report = $this->buildReport(['cohesion' => $healthScore]);
        $context = new FormatterContext();

        $result = $this->section->format($report, $context);

        self::assertNotNull($result);
        self::assertNull($result['cohesion']['score']);
        self::assertSame([], $result['cohesion']['decomposition']);
        self::assertSame([], $result['cohesion']['worstContributors']);
    }

    public function testFormatMultipleHealthScores(): void
    {
        $scores = [
            'complexity' => new HealthScore(
                name: 'complexity',
                score: 80.0,
                label: 'Good',
                warningThreshold: 60.0,
                errorThreshold: 40.0,
            ),
            'cohesion' => new HealthScore(
                name: 'cohesion',
                score: 55.0,
                label: 'Fair',
                warningThreshold: 70.0,
                errorThreshold: 50.0,
            ),
        ];

        $report = $this->buildReport($scores);
        $context = new FormatterContext();

        $result = $this->section->format($report, $context);

        self::assertNotNull($result);
        self::assertCount(2, $result);
        self::assertArrayHasKey('complexity', $result);
        self::assertArrayHasKey('cohesion', $result);
        self::assertSame(80.0, $result['complexity']['score']);
        self::assertSame(55.0, $result['cohesion']['score']);
    }
}
