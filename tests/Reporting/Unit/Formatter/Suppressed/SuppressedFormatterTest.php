<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\Formatter\Suppressed;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\CoverageFailure;
use Qualimetrix\Reporting\FindingProjection\InertSuppressor;
use Qualimetrix\Reporting\FindingProjection\SuppressedFinding;
use Qualimetrix\Reporting\FindingProjection\SuppressionComposition;
use Qualimetrix\Reporting\FindingProjection\SuppressionMechanism;
use Qualimetrix\Reporting\Formatter\Suppressed\SuppressedFormatter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;
use Qualimetrix\Reporting\ReportCoverage;

#[CoversClass(SuppressedFormatter::class)]
final class SuppressedFormatterTest extends TestCase
{
    #[Test]
    public function itPublishesTheDocumentationAddressesInItsMeta(): void
    {
        $meta = $this->decode($this->format(new SuppressionComposition([])))['meta'];

        self::assertSame(['version', 'package', 'timestamp', 'docs', 'llmsTxt'], array_keys($meta));
        self::assertSame(ProductIdentity::docsUrl(), $meta['docs']);
        self::assertSame(ProductIdentity::llmsTxtUrl(), $meta['llmsTxt']);
    }

    #[Test]
    public function itNamesItselfSuppressedAndDefaultsToNoGrouping(): void
    {
        $formatter = new SuppressedFormatter();

        self::assertSame('suppressed', $formatter->getName());
        self::assertSame(GroupBy::None, $formatter->getDefaultGroupBy());
    }

    #[Test]
    public function itPublishesTheMultisetNoteSoCountsAreNotMisreadAsFindings(): void
    {
        $payload = $this->decode($this->format(new SuppressionComposition([])));

        self::assertArrayHasKey('note', $payload);
        self::assertStringContainsString('do not sum', $payload['note']);
    }

    #[Test]
    public function itCountsEachSuppressedEntryUnderItsOwnMechanism(): void
    {
        $finding = $this->finding();
        $composition = new SuppressionComposition([
            new SuppressedFinding($finding, SuppressionMechanism::Suppression, 'src/Foo.php:3'),
            new SuppressedFinding($finding, SuppressionMechanism::PathSuppression, 'src/Excluded'),
        ]);

        $payload = $this->decode($this->format($composition));

        self::assertSame(1, $payload['byMechanism']['suppression']);
        self::assertSame(1, $payload['byMechanism']['path-suppression']);
        self::assertSame(0, $payload['byMechanism']['baseline']);
        self::assertCount(2, $payload['suppressed']);
        self::assertSame('suppression', $payload['suppressed'][0]['mechanism']);
        self::assertSame('src/Foo.php:3', $payload['suppressed'][0]['suppressor']);
    }

    #[Test]
    public function itPublishesNeverMatchedSuppressorsSeparatelyFromSuppressedFindings(): void
    {
        $composition = new SuppressionComposition(
            all: [],
            neverMatched: [new InertSuppressor(SuppressionMechanism::RulePathSuppression, 'coupling.cbo: src/Gone.php')],
        );

        $payload = $this->decode($this->format($composition));

        self::assertSame([], $payload['suppressed']);
        self::assertCount(1, $payload['neverMatched']);
        self::assertSame('rule-path-suppression', $payload['neverMatched'][0]['mechanism']);
        self::assertSame('coupling.cbo: src/Gone.php', $payload['neverMatched'][0]['suppressor']);
    }

    /**
     * An empty composition means "nothing was suppressed". A missing one means
     * the run never built it, and answering "nothing" for that would be the
     * very answer this format exists to make trustworthy.
     */
    #[Test]
    public function itRefusesToPublishAReportWhoseCompositionWasNeverBuilt(): void
    {
        $formatter = new SuppressedFormatter();
        $report = new Report(findings: [], filesAnalyzed: 0, filesSkipped: 0, duration: 0.0, errorCount: 0, warningCount: 0);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('suppression composition');

        $formatter->format($report, new FormatterContext());
    }

    /**
     * An entry carries the identity the `json` report publishes, so the two
     * can be joined by machine, and both texts of the finding under their own
     * keys — `message` means on this surface what it means on every other.
     */
    #[Test]
    public function itPublishesTheIdentityAndBothTextsOfASuppressedFinding(): void
    {
        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Foo.php'));
        $finding = new Finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), 3),
            subject: MetricSubject::aggregate($symbolPath),
            symbolPath: $symbolPath,
            ruleName: 'code-smell.debug-code',
            code: 'code-smell.debug-code',
            message: 'Debug function call detected',
            severity: Severity::Warning,
            recommendation: 'Remove debug statements.',
            occurrenceKey: OccurrenceKey::semantic('debug-call', ['function' => 'var_dump']),
        );

        $entry = $this->decode($this->format(new SuppressionComposition([
            new SuppressedFinding($finding, SuppressionMechanism::Suppression, 'src/Foo.php:3'),
        ])))['suppressed'][0];

        self::assertSame($finding->subject->toCanonical(), $entry['subject']);
        self::assertSame($finding->occurrenceKey?->value, $entry['occurrence']);
        self::assertNotNull($entry['occurrence']);
        self::assertNull($entry['edge']);
        self::assertSame('Debug function call detected', $entry['message']);
        self::assertSame('Remove debug statements.', $entry['recommendation']);
    }

    #[Test]
    public function itPublishesTheRunsCoverageLikeEveryOtherFormat(): void
    {
        $report = new Report(
            findings: [],
            filesAnalyzed: 1,
            filesSkipped: 1,
            duration: 0.0,
            errorCount: 0,
            warningCount: 0,
            coverage: new ReportCoverage(2, 1, 0, 1, [new CoverageFailure('src/Bad.php', 'parse', 'Syntax error')]),
            suppressionComposition: new SuppressionComposition([]),
        );

        $coverage = $this->decode((new SuppressedFormatter())->format($report, new FormatterContext()))['coverage'];

        self::assertFalse($coverage['complete']);
        self::assertSame(1, $coverage['failed']);
        self::assertSame('src/Bad.php', $coverage['failures'][0]['path']);
    }

    private function format(SuppressionComposition $composition): string
    {
        $report = new Report(
            findings: [],
            filesAnalyzed: 0,
            filesSkipped: 0,
            duration: 0.0,
            errorCount: 0,
            warningCount: 0,
            suppressionComposition: $composition,
        );

        return (new SuppressedFormatter())->format($report, new FormatterContext());
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function finding(): Finding
    {
        $symbolPath = SymbolPath::forFile(RelativePath::fromString('src/Foo.php'));

        return new Finding(
            location: new Location(RelativePath::fromString('src/Foo.php'), 3),
            subject: MetricSubject::aggregate($symbolPath),
            symbolPath: $symbolPath,
            ruleName: 'code-smell.debug-code',
            code: 'code-smell.debug-code',
            message: 'test',
            severity: Severity::Warning,
        );
    }
}
