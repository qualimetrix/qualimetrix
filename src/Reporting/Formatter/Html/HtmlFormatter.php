<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Html;

use Qualimetrix\Reporting\Formatter\FormatOptionKeysInterface;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\PublishedUtf8;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Health\HealthHintProjector;
use Qualimetrix\Reporting\Report;
use RuntimeException;

/**
 * Generates a self-contained interactive HTML report with D3.js treemap visualization.
 *
 * The report embeds all CSS, JS, and data as a single HTML file for offline viewing.
 */
final class HtmlFormatter implements FormatterInterface, FormatOptionKeysInterface
{
    public function __construct(
        private readonly HtmlTreeBuilder $treeBuilder,
        private readonly HealthHintProjector $hintProjector,
    ) {}

    public function format(Report $report, FormatterContext $context): string
    {
        $data = $this->treeBuilder->build($report, $context, $context->scopedReporting);
        $data['hints'] = $this->hintProjector->project();
        $data['coverage'] = $report->coverage?->toArray();

        $repairs = 0;
        $json = PublishedUtf8::encodeJsonObject(
            $data,
            \JSON_HEX_TAG | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            $repairs,
        );

        $templateDir = \dirname(__DIR__, 4) . '/html-report';

        $html = $this->readFile($templateDir . '/report.html');
        $css = $this->readFile($templateDir . '/report.css');
        $d3Js = $this->readFile($templateDir . '/dist/d3.min.js');
        $appJs = $this->readFile($templateDir . '/dist/report.min.js');

        // One pass: str_replace() would rescan the inserted data for the
        // placeholders after it, and a symbol named `__APP_JS__` would pull the
        // whole viewer bundle into the JSON.
        $rendered = strtr($html, [
            '__CSS__' => $css,
            '__DATA__' => $json,
            '__D3_JS__' => $d3Js,
            '__APP_JS__' => $appJs,
        ]);

        if ($report->coverage !== null && !$report->coverage->isComplete()) {
            $banner = \sprintf(
                '<div role="alert" data-qmx-coverage="incomplete" style="padding:12px;background:#7f1d1d;color:#fff">Analysis incomplete: %d of %d discovered PHP file(s) failed. Policy results are not authoritative.</div>',
                $report->coverage->failed,
                $report->coverage->discovered,
            );
            $rendered = str_replace('<body>', '<body>' . $banner, $rendered);
        }

        if ($report->outOfScope !== null && $report->outOfScope->total() > 0) {
            $rendered = str_replace('<body>', '<body>' . \sprintf(
                '<div role="status" data-qmx-drill-down="out-of-scope" style="padding:12px;background:#78350f;color:#fff">%s</div>',
                htmlspecialchars($report->outOfScope->describe(), \ENT_QUOTES),
            ), $rendered);
        }

        $projectScope = $report->projectScope?->describe();
        if ($projectScope !== null) {
            $rendered = str_replace('<body>', '<body>' . \sprintf(
                '<div role="status" data-qmx-project-scope="%s" style="padding:12px;background:#78350f;color:#fff">%s</div>',
                htmlspecialchars($report->projectScope->state, \ENT_QUOTES),
                htmlspecialchars($projectScope, \ENT_QUOTES),
            ), $rendered);
        }

        if ($repairs > 0) {
            $rendered = str_replace('<body>', '<body>' . \sprintf(
                '<div role="alert" data-qmx-publication="invalid-utf8" style="padding:12px;background:#78350f;color:#fff">%s</div>',
                htmlspecialchars(PublishedUtf8::describe($repairs), \ENT_QUOTES),
            ), $rendered);
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

    /** Read by {@see HtmlTreeBuilder} on this formatter's behalf. */
    public function formatOptionKeys(): array
    {
        return ['project-name'];
    }

    private function readFile(string $path): string
    {
        if (!file_exists($path)) {
            throw new RuntimeException(\sprintf(
                'Template file not found: %s. Run "cd html-report && npm run build" to generate dist/ files.',
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
