<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Reporting\FindingProjection\SuppressedFinding;
use Qualimetrix\Reporting\FindingProjection\SuppressionComposition;
use Qualimetrix\Reporting\FindingProjection\SuppressionMechanism;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\Formatter\PublishedUtf8;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\ReportBuilder;
use Qualimetrix\Reporting\ReportCoverage;

/**
 * The parser accepts a byte like 0xFF inside an identifier, so an analysis can
 * finish with a symbol name that is not UTF-8. Every format must still publish
 * a document it declares valid — and must say that it repaired one, rather
 * than substitute in silence.
 */
#[CoversClass(PublishedUtf8::class)]
final class InvalidUtf8PublicationTest extends TestCase
{
    private const string BROKEN = "Br\xFFken";

    /** @return iterable<string, array{string}> */
    public static function structuredFormats(): iterable
    {
        foreach (['json', 'metrics', 'suppressed', 'sarif', 'gitlab', 'checkstyle', 'html'] as $format) {
            yield $format => [$format];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function proseFormats(): iterable
    {
        foreach (['summary', 'text', 'text-verbose', 'github', 'health'] as $format) {
            yield $format => [$format];
        }
    }

    #[Test]
    #[DataProvider('structuredFormats')]
    public function itPublishesAValidDocumentAndSaysItRepairedOne(string $format): void
    {
        $output = $this->format($format);

        self::assertTrue(mb_check_encoding($output, 'UTF-8'), 'the document is valid UTF-8');
        self::assertStringContainsString("Br\u{FFFD}ken", $this->readable($format, $output));

        match ($format) {
            'json', 'metrics', 'suppressed' => self::assertGreaterThanOrEqual(1, self::decode($output)['invalidUtf8Replaced'] ?? 0),
            'sarif' => self::assertContains(
                'QMX-PUBLICATION-INVALID-UTF8',
                array_column(array_column(self::decode($output)['runs'][0]['invocations'][0]['toolExecutionNotifications'], 'descriptor'), 'id'),
            ),
            'gitlab' => self::assertContains('publication.invalid-utf8', array_column(self::decode($output), 'check_name')),
            'checkstyle' => self::assertStringContainsString('source="qmx.publication.invalid-utf8"', $output),
            'html' => self::assertStringContainsString('data-qmx-publication="invalid-utf8"', $output),
            default => self::fail('No repair mark is asserted for ' . $format),
        };
    }

    #[Test]
    public function itAddsNoRepairMarkWhenNothingNeededRepair(): void
    {
        $output = $this->format('json', 'Intact');

        self::assertArrayNotHasKey('invalidUtf8Replaced', self::decode($output));
    }

    /**
     * Prose surfaces carry the bytes through untouched; they must not fail.
     */
    #[Test]
    #[DataProvider('proseFormats')]
    public function itStillRendersTheProseFormats(string $format): void
    {
        self::assertNotSame('', $this->format($format));
    }

    private function format(string $format, string $className = self::BROKEN): string
    {
        $symbol = SymbolPath::forClass('App', $className);
        $file = RelativePath::fromString('src/A.php');
        $finding = new Finding(
            location: new Location($file, 3),
            subject: MetricSubject::declaration(DeclarationPath::of($symbol, $file, DeclarationOrdinal::fromRank(0))),
            symbolPath: $symbol,
            ruleName: 'complexity.ccn',
            code: 'complexity.ccn',
            message: \sprintf('Class %s is too complex', $className),
            severity: Severity::Error,
        );

        $metrics = new InMemoryMetricRepository();
        $metrics->add($symbol, MetricBag::fromArray(['complexity.ccn.sum' => 12]), $file, 3);

        $report = ReportBuilder::create()
            ->metrics($metrics)
            ->addFinding($finding)
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->coverage(new ReportCoverage(1, 1, 0, 0))
            ->suppressionComposition(new SuppressionComposition([
                new SuppressedFinding($finding, SuppressionMechanism::Suppression, 'src/A.php:3'),
            ]))
            ->build();

        /** @var FormatterRegistryInterface $registry */
        $registry = (new ContainerFactory())->create()->get(FormatterRegistryInterface::class);

        return $registry->get($format)->format($report, new FormatterContext(useColor: false, basePath: '/project'));
    }

    private function readable(string $format, string $output): string
    {
        return match ($format) {
            'checkstyle' => self::parsedXml($output),
            'html' => (string) json_encode(self::htmlPayload($output), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            default => (string) json_encode(self::decode($output), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        };
    }

    private static function parsedXml(string $xml): string
    {
        $document = simplexml_load_string($xml);
        self::assertNotFalse($document, 'the document parses as XML');

        return (string) $document->asXML();
    }

    /** @return array<mixed> */
    private static function htmlPayload(string $html): array
    {
        self::assertSame(1, preg_match('~<script type="application/json" id="report-data">(.*?)</script>~s', $html, $match));

        return self::decode($match[1]);
    }

    /** @return array<mixed> */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
