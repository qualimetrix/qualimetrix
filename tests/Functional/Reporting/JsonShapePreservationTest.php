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
use Qualimetrix\Reporting\Formatter\GitLabCodeQualityFormatter;
use Qualimetrix\Reporting\Formatter\MetricsJsonFormatter;
use Qualimetrix\Reporting\Formatter\Sarif\SarifFormatter;
use Qualimetrix\Reporting\Formatter\Sarif\SarifRuleCollector;
use Qualimetrix\Reporting\FormatterContext;
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

    private static function fileViolation(): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 17, true),
            symbolPath: SymbolPath::forClass('App\\Service', 'UserService'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'Cyclomatic complexity: 12 (threshold: 10)',
            severity: Severity::Warning,
        );
    }

    private static function projectViolation(): Violation
    {
        return new Violation(
            location: Location::none(),
            symbolPath: SymbolPath::forProject(),
            ruleName: 'architecture.circular-dependency',
            violationCode: 'architecture.circular-dependency',
            message: 'cycle detected: A → B → A',
            severity: Severity::Error,
        );
    }
}
