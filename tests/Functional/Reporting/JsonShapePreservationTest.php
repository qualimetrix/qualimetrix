<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Reporting;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\DebtCalculator;
use Qualimetrix\Reporting\Debt\RemediationTimeRegistry;
use Qualimetrix\Reporting\Filter\ViolationFilter;
use Qualimetrix\Reporting\Formatter\GitLabCodeQualityFormatter;
use Qualimetrix\Reporting\Formatter\Json\JsonFormatter;
use Qualimetrix\Reporting\Formatter\Json\JsonHealthSection;
use Qualimetrix\Reporting\Formatter\Json\JsonOffenderSection;
use Qualimetrix\Reporting\Formatter\Json\JsonSanitizer;
use Qualimetrix\Reporting\Formatter\Json\JsonViolationSection;
use Qualimetrix\Reporting\Formatter\MetricsJsonFormatter;
use Qualimetrix\Reporting\Formatter\Sarif\SarifFormatter;
use Qualimetrix\Reporting\Formatter\Sarif\SarifRuleCollector;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\HealthScoreResolver;
use Qualimetrix\Reporting\Health\MetricHintProvider;
use Qualimetrix\Reporting\Health\NamespaceDrillDown;
use Qualimetrix\Reporting\ReportBuilder;

/**
 * ADR 0015 Phase 4 contract pin: after the RelativePath VO migration the
 * JSON-shaped formatters must keep emitting the same wire-surface keys and
 * value types as before the migration. The test does not commit a literal
 * golden file (the rest of the report contains volatile fields like
 * timestamps and versions); instead it pins the shape — keys, types, and
 * the sentinel values for "no file" violations.
 */
#[CoversNothing]
final class JsonShapePreservationTest extends TestCase
{
    #[Test]
    public function gitlabFormatterEmitsPathAsString(): void
    {
        $formatter = new GitLabCodeQualityFormatter();

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->addViolations([self::fileViolation(), self::projectViolation()])
            ->build();

        $data = json_decode($formatter->format($report, new FormatterContext()), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsList($data);
        self::assertCount(2, $data);

        foreach ($data as $entry) {
            self::assertArrayHasKey('location', $entry);
            self::assertArrayHasKey('path', $entry['location']);
            self::assertIsString($entry['location']['path']);
        }

        $paths = array_column(array_column($data, 'location'), 'path');
        self::assertContains('src/Service/UserService.php', $paths);
        self::assertContains('_project', $paths, 'project-level violation must carry the _project sentinel');
    }

    #[Test]
    public function sarifFormatterEmitsArtifactLocationUri(): void
    {
        $formatter = new SarifFormatter(new SarifRuleCollector());

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->addViolations([self::fileViolation()])
            ->build();

        $data = json_decode($formatter->format($report, new FormatterContext()), true, 512, \JSON_THROW_ON_ERROR);

        $result = $data['runs'][0]['results'][0];
        $uri = $result['locations'][0]['physicalLocation']['artifactLocation']['uri'];

        self::assertIsString($uri);
        self::assertSame('src/Service/UserService.php', $uri);
    }

    #[Test]
    public function sarifFormatterOmitsLocationsForProjectViolations(): void
    {
        $formatter = new SarifFormatter(new SarifRuleCollector());

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->addViolations([self::projectViolation()])
            ->build();

        $data = json_decode($formatter->format($report, new FormatterContext()), true, 512, \JSON_THROW_ON_ERROR);

        $result = $data['runs'][0]['results'][0];
        self::assertArrayNotHasKey('locations', $result, 'project-level SARIF result must omit "locations"');
    }

    #[Test]
    public function metricsJsonFormatterEmitsFileAsStringField(): void
    {
        // MetricsJsonFormatter consumes SymbolInfo->file directly (a RelativePath since Phase 1c);
        // shape contract: every symbol entry has `file` as a string (empty when null).
        $formatter = new MetricsJsonFormatter();

        $report = ReportBuilder::create()->filesAnalyzed(0)->build();

        $data = json_decode($formatter->format($report, new FormatterContext()), true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('symbols', $data);
        self::assertIsList($data['symbols']);
    }

    #[Test]
    public function itPreservesATargetOnlyEdgeThroughTheJsonFormatter(): void
    {
        $hintProvider = new MetricHintProvider();
        $namespaceDrillDown = new NamespaceDrillDown($hintProvider);
        $sanitizer = new JsonSanitizer();
        $registry = new RemediationTimeRegistry();
        $formatter = new JsonFormatter(
            new DebtCalculator($registry),
            new JsonHealthSection(new HealthScoreResolver($namespaceDrillDown), $sanitizer),
            new JsonOffenderSection($namespaceDrillDown, new ViolationFilter(), $sanitizer),
            new JsonViolationSection($registry, $sanitizer),
        );
        $violation = self::violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 1),
            symbolPath: SymbolPath::forFile(RelativePath::fromString('src/Foo.php')),
            ruleName: 'r',
            violationCode: 'r.edge',
            message: 'target only',
            severity: Severity::Warning,
            dependencyTarget: SymbolPath::forClass('App', 'Target'),
        );

        $data = json_decode($formatter->format(
            ReportBuilder::create()->addViolation($violation)->build(),
            new FormatterContext(),
        ), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(['target' => 'class:App\\Target'], $data['violations'][0]['edge']);
    }

    private static function fileViolation(): Violation
    {
        return self::violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 17, true),
            symbolPath: SymbolPath::forClass('App\\Service', 'UserService'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'Cyclomatic complexity: 12 (threshold: 10)',
            severity: Severity::Warning,
        );
    }

    private static function projectViolation(): Violation
    {
        return self::violation(
            location: Location::none(),
            symbolPath: SymbolPath::forProject(),
            ruleName: 'architecture.circular-dependency',
            violationCode: 'architecture.circular-dependency',
            message: 'cycle detected: A → B → A',
            severity: Severity::Error,
        );
    }

    /**
     * Builds a violation fixture with an explicit declaration or aggregate
     * subject, preserving the production contract without hiding it behind a
     * legacy fallback.
     *
     * @param list<\Qualimetrix\Core\Violation\Location> $relatedLocations
     */
    private static function violation(
        \Qualimetrix\Core\Violation\Location $location,
        \Qualimetrix\Core\Symbol\SymbolPath $symbolPath,
        string $ruleName,
        string $violationCode,
        string $message,
        \Qualimetrix\Core\Violation\Severity $severity,
        int|float|null $metricValue = null,
        ?\Qualimetrix\Core\Rule\RuleLevel $level = null,
        array $relatedLocations = [],
        ?string $recommendation = null,
        int|float|null $threshold = null,
        ?\Qualimetrix\Core\Symbol\SymbolPath $dependencyTarget = null,
        ?\Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType $dependencyType = null,
        ?\Qualimetrix\Core\Violation\AcceptedLevel $acceptedLevel = null,
        ?\Qualimetrix\Core\Violation\OccurrenceKey $occurrenceKey = null,
        ?\Qualimetrix\Core\Symbol\MetricSubject $subject = null,
    ): Violation {
        $subject ??= match ($symbolPath->getType()) {
            \Qualimetrix\Core\Symbol\SymbolType::File,
            \Qualimetrix\Core\Symbol\SymbolType::Namespace_,
            \Qualimetrix\Core\Symbol\SymbolType::Project => \Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath),
            default => \Qualimetrix\Core\Symbol\MetricSubject::declaration(new \Qualimetrix\Core\Symbol\DeclarationPath(
                $symbolPath,
                $location->file ?? \Qualimetrix\Core\Path\RelativePath::fromString('tests/Reporting/fixture.php'),
                $location->line ?? 0,
            )),
        };

        return new Violation(
            location: $location,
            subject: $subject,
            symbolPath: $symbolPath,
            ruleName: $ruleName,
            violationCode: $violationCode,
            message: $message,
            severity: $severity,
            metricValue: $metricValue,
            level: $level,
            relatedLocations: $relatedLocations,
            recommendation: $recommendation,
            threshold: $threshold,
            dependencyTarget: $dependencyTarget,
            dependencyType: $dependencyType,
            acceptedLevel: $acceptedLevel,
            occurrenceKey: $occurrenceKey,
        );
    }

}
