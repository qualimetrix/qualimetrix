<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\Formatter\Prose\AsciiGlyphs;
use Qualimetrix\Reporting\Formatter\Prose\GlyphMode;
use Qualimetrix\Reporting\Formatter\Prose\ProseText;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\ReportBuilder;

#[CoversClass(ProseText::class)]
final class InvalidUtf8ProsePublicationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function formats(): iterable
    {
        foreach (['text', 'summary', 'health', 'github'] as $format) {
            yield $format => [$format];
        }
    }

    #[Test]
    #[DataProvider('formats')]
    public function itPublishesTheRealFormatterBodyWithoutTransliteratingOtherLetters(string $format): void
    {
        $symbol = SymbolPath::forClass('App', "CaféK\xFF");
        $file = RelativePath::fromString('src/A.php');
        $finding = new Finding(
            location: new Location($file, 1),
            subject: MetricSubject::declaration(DeclarationPath::of($symbol, $file, DeclarationOrdinal::fromRank(0))),
            symbolPath: $symbol,
            ruleName: 'complexity.ccn',
            code: 'complexity.ccn',
            message: "CaféK\xFF is complex — measured",
            severity: Severity::Error,
        );
        $report = ReportBuilder::create()->addFinding($finding)->filesAnalyzed(1)->build();
        /** @var FormatterRegistryInterface $registry */
        $registry = (new ContainerFactory())->create()->get(FormatterRegistryInterface::class);
        $body = $registry->get($format)->format($report, new FormatterContext(useColor: false, class: "App\CaféK\xFF", detailLimit: 1))->body;
        $published = ProseText::publish($body, GlyphMode::Ascii);
        self::assertTrue(mb_check_encoding($published->body, 'UTF-8'));
        self::assertStringContainsString('Café', $published->body);
        self::assertStringContainsString('%FF', $published->body);
        self::assertSame(1, $published->escapedStrings);
        foreach (array_keys(AsciiGlyphs::REPLACEMENTS) as $glyph) {
            self::assertStringNotContainsString($glyph, $published->body);
        }
    }
}
