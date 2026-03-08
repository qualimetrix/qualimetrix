<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter;

use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Report;

/**
 * Exports all collected metrics as JSON (not just violations).
 *
 * Unlike JsonFormatter which outputs violations, this formatter outputs
 * raw metric data for every analyzed symbol — useful for custom dashboards,
 * trend analysis, and third-party integrations.
 */
final class MetricsJsonFormatter implements FormatterInterface
{
    private const VERSION = '1.0.0';
    private const PACKAGE = 'aimd';

    public function format(Report $report, FormatterContext $context): string
    {
        $symbols = [];

        if ($report->metrics !== null) {
            $symbolTypes = [
                SymbolType::File,
                SymbolType::Namespace_,
                SymbolType::Class_,
                SymbolType::Method,
                SymbolType::Function_,
            ];

            foreach ($symbolTypes as $type) {
                foreach ($report->metrics->all($type) as $symbolInfo) {
                    $bag = $report->metrics->get($symbolInfo->symbolPath);
                    $metricsArray = array_filter(
                        $bag->all(),
                        static fn(int|float $v): bool => \is_int($v) || is_finite($v),
                    );

                    if ($metricsArray === []) {
                        continue;
                    }

                    $symbols[] = [
                        'type' => $type->value,
                        'name' => $symbolInfo->symbolPath->toString(),
                        'file' => $symbolInfo->file,
                        'line' => $symbolInfo->line,
                        'metrics' => $metricsArray,
                    ];
                }
            }
        }

        $data = [
            'version' => self::VERSION,
            'package' => self::PACKAGE,
            'timestamp' => gmdate('c'),
            'symbols' => $symbols,
            'summary' => [
                'filesAnalyzed' => $report->filesAnalyzed,
                'filesSkipped' => $report->filesSkipped,
                'duration' => round($report->duration, 3),
                'violations' => $report->getTotalViolations(),
                'errors' => $report->errorCount,
                'warnings' => $report->warningCount,
            ],
        ];

        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    public function getName(): string
    {
        return 'metrics-json';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::None;
    }
}
