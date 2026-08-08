<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Html;

use Qualimetrix\Reporting\Debt\DebtCalculator;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Health\MetricHintProvider;
use Qualimetrix\Reporting\Report;
use RuntimeException;

/**
 * Generates a self-contained interactive HTML report with D3.js treemap visualization.
 *
 * The report embeds all CSS, JS, and data as a single HTML file for offline viewing.
 */
final class HtmlFormatter implements FormatterInterface
{
    public function __construct(
        private readonly DebtCalculator $debtCalculator,
        private readonly MetricHintProvider $hintProvider,
    ) {}

    public function format(Report $report, FormatterContext $context): string
    {
        $builder = new HtmlTreeBuilder($this->debtCalculator);
        $data = $builder->build($report, $context, $context->scopedReporting);
        $data['hints'] = $this->hintProvider->exportForHtml();
        $data['coverage'] = $report->coverage?->toArray();

        $json = json_encode(
            $data,
            \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );

        $templateDir = \dirname(__DIR__, 2) . '/Template';

        $html = $this->readFile($templateDir . '/report.html');
        $css = $this->readFile($templateDir . '/report.css');
        $d3Js = $this->readFile($templateDir . '/dist/d3.min.js');
        $appJs = $this->readFile($templateDir . '/dist/report.min.js');

        $rendered = str_replace(
            ['__CSS__', '__DATA__', '__D3_JS__', '__APP_JS__'],
            [$css, $json, $d3Js, $appJs],
            $html,
        );

        if ($report->coverage !== null && !$report->coverage->isComplete()) {
            $banner = \sprintf(
                '<div role="alert" data-qmx-coverage="incomplete" style="padding:12px;background:#7f1d1d;color:#fff">Analysis incomplete: %d of %d discovered PHP file(s) failed. Policy results are not authoritative.</div>',
                $report->coverage->failed,
                $report->coverage->discovered,
            );
            $rendered = str_replace('<body>', '<body>' . $banner, $rendered);
        }

        return $rendered;
    }

    public function getName(): string
    {
        return 'html';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::None;
    }

    private function readFile(string $path): string
    {
        if (!file_exists($path)) {
            throw new RuntimeException(\sprintf(
                'Template file not found: %s. Run "cd src/Reporting/Template && npm run build" to generate dist/ files.',
                $path,
            ));
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException(\sprintf('Failed to read template file: %s', $path));
        }

        return $content;
    }
}
