<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Reporting\Unit\Formatter\Html;

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

    /**
     * The data is substituted into the template together with the scripts; a
     * value spelled like a later placeholder must stay a value.
     */
    #[Test]
    public function itKeepsAValueSpelledLikeATemplatePlaceholderInTheData(): void
    {
        $report = ReportBuilder::create()->filesAnalyzed(1)->filesSkipped(0)->duration(0.1)->build();

        $output = $this->formatter->format($report, new FormatterContext(options: ['project-name' => '__APP_JS__ and __D3_JS__']));

        self::assertSame('__APP_JS__ and __D3_JS__', self::payload($output)['project']['name']);
    }

    #[Test]
    public function itNamesTheReportAfterTheAnalysedProjectNotAfterTheRunningTool(): void
    {
        $root = sys_get_temp_dir() . '/qmx-html-name-' . bin2hex(random_bytes(8));
        mkdir($root);
        file_put_contents($root . '/composer.json', '{"name": "corpus/reporting-review"}');
        $bare = $root . '/no-manifest-here';
        mkdir($bare);

        try {
            $report = ReportBuilder::create()->filesAnalyzed(1)->filesSkipped(0)->duration(0.1)->build();

            $named = self::payload($this->formatter->format($report, new FormatterContext(basePath: $root)));
            $unnamed = self::payload($this->formatter->format($report, new FormatterContext(basePath: $bare)));
            $explicit = self::payload($this->formatter->format($report, new FormatterContext(basePath: $root, options: ['project-name' => 'Chosen'])));
        } finally {
            unlink($root . '/composer.json');
            rmdir($bare);
            rmdir($root);
        }

        self::assertSame('corpus/reporting-review', $named['project']['name']);
        self::assertSame('no-manifest-here', $unnamed['project']['name']);
        self::assertSame('Chosen', $explicit['project']['name']);
    }

    /** @return array<string, mixed> */
    private static function payload(string $html): array
    {
        self::assertSame(1, preg_match('~<script type="application/json" id="report-data">(.*?)</script>~s', $html, $match));

        return json_decode($match[1], true, 512, \JSON_THROW_ON_ERROR);
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

    /**
     * The shipped page must carry the element `html-report/src/main.js`'s
     * `renderFooter()` looks up (`document.getElementById('report-footer')`);
     * without it the footer silently renders nothing, however correct the
     * embedded `report-data` payload is. `itProducesValidHtml()` covers the
     * static wrapper markup, but not this specific id, which is the one
     * `main.js` and this template must agree on.
     */
    #[Test]
    public function itCarriesTheFooterElementTheFooterScriptLooksUp(): void
    {
        $report = ReportBuilder::create()
            ->filesAnalyzed(1)
            ->filesSkipped(0)
            ->duration(0.1)
            ->build();

        $output = $this->formatter->format($report, new FormatterContext());

        self::assertStringContainsString('id="report-footer"', $output);
    }
}
