<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Sarif;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Formatter\Sarif\SarifFormatter;
use Qualimetrix\Reporting\Formatter\Sarif\SarifRuleCollector;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\ReportBuilder;

/**
 * ADR 0015 Phase 4 regression pin: SARIF output uses POSIX separators
 * regardless of input platform, because RelativePath VOs are POSIX-only and
 * SarifFormatter no longer performs its own backslash-to-slash conversion.
 */
#[CoversClass(SarifFormatter::class)]
final class SarifFormatterPosixSeparatorTest extends TestCase
{
    #[Test]
    public function itEmitsPosixSeparatorsForArtifactLocations(): void
    {
        $formatter = new SarifFormatter(new SarifRuleCollector());

        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Sub/Dir/Foo.php'), 42, true),
            symbolPath: SymbolPath::forClass('App\\Sub\\Dir', 'Foo'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'too complex',
            severity: Severity::Warning,
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->addViolations([$violation])
            ->build();

        $output = $formatter->format($report, new FormatterContext());

        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        $uri = $data['runs'][0]['results'][0]['locations'][0]['physicalLocation']['artifactLocation']['uri'];
        self::assertSame('src/Sub/Dir/Foo.php', $uri);
        self::assertStringNotContainsString('\\', $uri, 'SARIF artifactLocation.uri must use POSIX separators');
    }

    #[Test]
    public function itEmitsPosixSeparatorsForRelatedLocations(): void
    {
        $formatter = new SarifFormatter(new SarifRuleCollector());

        $related = new Location(RelativePath::fromString('src/Other/Bar.php'), 7, true);
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Main/Foo.php'), 12, true),
            symbolPath: SymbolPath::forClass('App\\Main', 'Foo'),
            ruleName: 'architecture.layer-violation',
            violationCode: 'architecture.layer-violation',
            message: 'crosses layer boundary',
            severity: Severity::Error,
            relatedLocations: [$related],
        );

        $report = ReportBuilder::create()
            ->filesAnalyzed(2)
            ->addViolations([$violation])
            ->build();

        $output = $formatter->format($report, new FormatterContext());

        $data = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        $mainUri = $data['runs'][0]['results'][0]['locations'][0]['physicalLocation']['artifactLocation']['uri'];
        $relatedUri = $data['runs'][0]['results'][0]['relatedLocations'][0]['physicalLocation']['artifactLocation']['uri'];

        self::assertSame('src/Main/Foo.php', $mainUri);
        self::assertSame('src/Other/Bar.php', $relatedUri);
        self::assertStringNotContainsString('\\', $mainUri);
        self::assertStringNotContainsString('\\', $relatedUri);
    }
}
