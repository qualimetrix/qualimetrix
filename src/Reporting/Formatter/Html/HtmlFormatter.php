<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter\Html;

use AiMessDetector\Reporting\Debt\DebtCalculator;
use AiMessDetector\Reporting\Formatter\FormatterInterface;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Health\MetricHintProvider;
use AiMessDetector\Reporting\Report;
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
        $data = $builder->build($report, $context, $context->partialAnalysis);
        $data['hints'] = $this->hintProvider->exportForHtml();

        $json = json_encode(
            $data,
            \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );

        $templateDir = \dirname(__DIR__, 2) . '/Template';

        $html = $this->readFile($templateDir . '/report.html');
        $css = $this->readFile($templateDir . '/report.css');
        $d3Js = $this->readFile($templateDir . '/dist/d3.min.js');
        $appJs = $this->readFile($templateDir . '/dist/report.min.js');

        return str_replace(
            ['__CSS__', '__DATA__', '__D3_JS__', '__APP_JS__'],
            [$css, $json, $d3Js, $appJs],
            $html,
        );
    }

    public function getName(): string
    {
        return 'health';
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
