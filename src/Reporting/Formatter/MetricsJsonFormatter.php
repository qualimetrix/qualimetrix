<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Version;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;

/**
 * Exports all collected metrics as JSON (not just findings).
 *
 * Unlike JsonFormatter which outputs findings, this formatter outputs
 * raw metric data for every analyzed symbol — useful for custom dashboards,
 * trend analysis, and third-party integrations.
 */
final class MetricsJsonFormatter implements FormatterInterface
{
    private const VERSION = '1.0.0';
    private const PACKAGE = 'qmx';

    /**
     * The levels this export walks, in publication order.
     *
     * @var list<SymbolLevel>
     */
    private const array LEVELS = [
        SymbolLevel::File,
        SymbolLevel::Project,
        SymbolLevel::Namespace_,
        SymbolLevel::Class_,
        SymbolLevel::Callable,
    ];

    /**
     * The declaration kinds this export publishes, in publication order.
     *
     * This format is about declarations, not levels: every entry carries the
     * kind of the symbol it describes, and one level can hold more than one
     * kind — a callable is a method or a global function. The order is part of
     * the published document, so a level's symbols are grouped by kind in this
     * order rather than in the order the repository happens to hold them.
     *
     * @var list<SymbolType>
     */
    private const array DECLARATION_KINDS = [
        SymbolType::File,
        SymbolType::Project,
        SymbolType::Namespace_,
        SymbolType::Class_,
        SymbolType::Method,
        SymbolType::Function_,
    ];

    public function format(Report $report, FormatterContext $context): string
    {
        $symbols = [];

        if ($report->metrics !== null) {
            foreach (self::LEVELS as $level) {
                foreach (self::byDeclarationKind($report->metrics->all($level)) as $symbolInfo) {
                    $bag = $report->metrics->get($symbolInfo->symbolPath);
                    // Filter out internal derived-metric keys (contain ':')
                    $rawMetrics = array_filter(
                        $bag->all(),
                        static fn(string $key): bool => !str_contains($key, ':'),
                        \ARRAY_FILTER_USE_KEY,
                    );

                    // Replace non-finite values (NAN/INF from edge-case calculations) with null for JSON compatibility
                    $metricsArray = array_map(
                        static fn(int|float $v): int|float|null => \is_int($v) || is_finite($v) ? $v : null,
                        $rawMetrics,
                    );

                    if ($metricsArray === []) {
                        continue;
                    }

                    $symbols[] = [
                        'type' => $symbolInfo->symbolPath->getType()->value,
                        'name' => $symbolInfo->symbolPath->toString(),
                        'file' => $symbolInfo->file?->value() ?? '',
                        'line' => $symbolInfo->line,
                        'metrics' => $metricsArray,
                    ];
                }
            }
        }

        $data = [
            'version' => self::VERSION,
            'toolVersion' => Version::get(),
            'package' => self::PACKAGE,
            'timestamp' => gmdate('c'),
            'symbols' => $symbols,
            'coverage' => $report->coverage?->toArray(),
            'summary' => [
                'filesAnalyzed' => $report->filesAnalyzed,
                'filesSkipped' => $report->filesSkipped,
                'duration' => round($report->duration, 3),
                'violations' => $report->getTotalFindings(),
                'errors' => $report->errorCount,
                'warnings' => $report->warningCount,
                'info' => $report->infoCount,
            ],
        ];

        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    public function getName(): string
    {
        return 'metrics';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::None;
    }

    /**
     * @param iterable<SymbolInfo> $symbols
     *
     * @return list<SymbolInfo>
     */
    private static function byDeclarationKind(iterable $symbols): array
    {
        $buckets = [];
        foreach ($symbols as $symbolInfo) {
            $buckets[$symbolInfo->symbolPath->getType()->value][] = $symbolInfo;
        }

        $ordered = [];
        foreach (self::DECLARATION_KINDS as $kind) {
            foreach ($buckets[$kind->value] ?? [] as $symbolInfo) {
                $ordered[] = $symbolInfo;
            }

            unset($buckets[$kind->value]);
        }

        // A kind missing from the publication order would otherwise be
        // dropped from the export without a trace, and the export would still
        // look well-formed. Adding a SymbolType case must extend the order
        // above, not silently shrink the document.
        if ($buckets !== []) {
            throw new LogicException(\sprintf(
                'MetricsJsonFormatter has no publication position for declaration kind(s) %s; extend %s::DECLARATION_KINDS.',
                implode(', ', array_keys($buckets)),
                self::class,
            ));
        }

        return $ordered;
    }
}
