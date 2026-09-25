<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;

/**
 * The `computed_metrics:` section: a map keyed by metric name, merged metric
 * by metric and, inside one metric, key by key. A lower layer's metric is
 * removed only by writing `enabled: false` over it.
 */
final readonly class ComputedMetricsSection implements DocumentSectionSchemaInterface
{
    public const string KEY = 'computed_metrics';

    public function key(): string
    {
        return self::KEY;
    }

    public function schema(): NodeSchema
    {
        return NodeSchema::namedMap(ComputedMetricEntryKeys::entrySchema());
    }
}
