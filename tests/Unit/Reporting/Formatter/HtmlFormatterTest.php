<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Reporting\Formatter;

use AiMessDetector\Reporting\Debt\DebtCalculator;
use AiMessDetector\Reporting\Debt\RemediationTimeRegistry;
use AiMessDetector\Reporting\Formatter\Html\HtmlFormatter;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Health\MetricHintProvider;
use AiMessDetector\Reporting\ReportBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlFormatter::class)]
final class HtmlFormatterTest extends TestCase
{
    private HtmlFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new HtmlFormatter(
            new DebtCalculator(new RemediationTimeRegistry()),
            new MetricHintProvider(),
        );
    }

    public function testGetNameReturnsHtml(): void
    {
        self::assertSame('html', $this->formatter->getName());
    }

    public function testGetDefaultGroupByReturnsNone(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    public function testFormatProducesValidHtml(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(10)
            ->filesSkipped(0)
            ->duration(0.5)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertStringContainsString('<!DOCTYPE html>', $output);
        self::assertStringContainsString('<html lang="en">', $output);
        self::assertStringContainsString('</html>', $output);
        self::assertStringContainsString('id="report-data"', $output);
    }

    public function testFormatEmbedsCssInline(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        // CSS should be inlined (no __CSS__ placeholder)
        self::assertStringNotContainsString('__CSS__', $output);
        self::assertStringContainsString('--bg-primary', $output);
    }

    public function testFormatEmbedsJsInline(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        // JS should be inlined (no placeholders)
        self::assertStringNotContainsString('__D3_JS__', $output);
        self::assertStringNotContainsString('__APP_JS__', $output);
    }

    public function testFormatEmbedsJsonData(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(5)
            ->filesSkipped(1)
            ->duration(0.3)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        // JSON data should be embedded (no __DATA__ placeholder)
        self::assertStringNotContainsString('__DATA__', $output);
        // Should contain project metadata
        self::assertStringContainsString('"project"', $output);
        self::assertStringContainsString('"tree"', $output);
    }

    public function testFormatUsesJsonHexTag(): void
    {
        // The tree node name contains </script> which could break the HTML
        // JSON_HEX_TAG must escape < and > to prevent XSS
        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        // The project name "<project>" uses angle brackets, so JSON_HEX_TAG
        // must escape them. The literal string "<project>" should NOT appear
        // inside the JSON script block.
        self::assertStringContainsString('\u003Cproject\u003E', $output);
        self::assertStringNotContainsString('"<project>"', $output);
    }

    public function testFormatPartialAnalysis(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(3)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(partialAnalysis: true);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('"partialAnalysis":true', $output);
    }

    public function testFormatWithNullMetrics(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(0)
            ->filesSkipped(0)
            ->duration(0.0)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        // Should produce valid HTML with minimal data
        self::assertStringContainsString('<!DOCTYPE html>', $output);
        self::assertStringContainsString('"totalViolations":0', $output);
    }

    public function testFormatEmbedsHintsData(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertStringContainsString('"hints"', $output);
        self::assertStringContainsString('"metricHints"', $output);
        self::assertStringContainsString('"healthDecomposition"', $output);
    }
}
