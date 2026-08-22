<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Reporting\Formatter\Html\HtmlFormatter;
use Qualimetrix\Reporting\Formatter\Html\HtmlTreeBuilder;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Health\HealthHintProjector;
use Qualimetrix\Reporting\ReportBuilder;
use Qualimetrix\Tests\Analysis\Evidence\Prioritization\Support\StubRemediationMinutes;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;

#[CoversClass(HtmlFormatter::class)]
final class HtmlFormatterTest extends TestCase
{
    private HtmlFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new HtmlFormatter(
            new HtmlTreeBuilder(
                new DebtCalculator(new RemediationTimeRegistry(StubChannelDeclarationRegistry::alwaysHigherMagnitude(), StubRemediationMinutes::withRealValues())),
                self::createStub(ComputedMetricDefinitionCatalogInterface::class),
            ),
            new HealthHintProjector(new HealthMetricCatalog()),
        );
    }

    #[Test]
    public function itReturnsHtmlAsName(): void
    {
        self::assertSame('html', $this->formatter->getName());
    }

    #[Test]
    public function itReturnsNoneAsDefaultGroupBy(): void
    {
        self::assertSame(GroupBy::None, $this->formatter->getDefaultGroupBy());
    }

    #[Test]
    public function itProducesValidHtml(): void
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

    #[Test]
    public function itEmbedsCssInline(): void
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

    #[Test]
    public function itEmbedsJsInline(): void
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

    #[Test]
    public function itEmbedsJsonData(): void
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

    #[Test]
    public function itUsesJsonHexTagEncoding(): void
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

    #[Test]
    public function itEncodesScopedReportingFlag(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(3)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $context = new FormatterContext(scopedReporting: true);
        $output = $this->formatter->format($report, $context);

        self::assertStringContainsString('"scopedReporting":true', $output);
    }

    #[Test]
    public function itFormatsWithNullMetrics(): void
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

    #[Test]
    public function itEmbedsHintsData(): void
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
