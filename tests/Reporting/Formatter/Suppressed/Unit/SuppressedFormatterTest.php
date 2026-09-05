<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Formatter\Suppressed\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\FindingProjection\InertSuppressor;
use Qualimetrix\Reporting\FindingProjection\SuppressedFinding;
use Qualimetrix\Reporting\FindingProjection\SuppressionComposition;
use Qualimetrix\Reporting\FindingProjection\SuppressionMechanism;
use Qualimetrix\Reporting\Formatter\Suppressed\SuppressedFormatter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;

#[CoversClass(SuppressedFormatter::class)]
final class SuppressedFormatterTest extends TestCase
{
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
            new SuppressedFinding($finding, SuppressionMechanism::PathExclusion, 'src/Excluded'),
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
            neverMatched: [new InertSuppressor(SuppressionMechanism::RulePathExclusion, 'coupling.cbo: src/Gone.php')],
        );

        $payload = $this->decode($this->format($composition));

        self::assertSame([], $payload['suppressed']);
        self::assertCount(1, $payload['neverMatched']);
        self::assertSame('rule-path-suppression', $payload['neverMatched'][0]['mechanism']);
        self::assertSame('coupling.cbo: src/Gone.php', $payload['neverMatched'][0]['suppressor']);
    }

    #[Test]
    public function itTreatsAMissingCompositionAsEmptyRatherThanFailing(): void
    {
        $formatter = new SuppressedFormatter();
        $report = new Report(findings: [], filesAnalyzed: 0, filesSkipped: 0, duration: 0.0, errorCount: 0, warningCount: 0);

        $payload = $this->decode($formatter->format($report, new FormatterContext()));

        self::assertSame([], $payload['suppressed']);
        self::assertSame([], $payload['neverMatched']);
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
