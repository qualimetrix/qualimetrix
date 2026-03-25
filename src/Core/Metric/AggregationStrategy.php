<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Metric;

/**
 * Defines how metrics are aggregated when rolling up from child to parent symbols.
 */
enum AggregationStrategy: string
{
    /** Sum of all values (e.g., total LOC, total CCN) */
    case Sum = 'sum';

    /** Arithmetic mean of values (e.g., average CCN per method) */
    case Average = 'avg';

    /** Maximum value (e.g., highest CCN in a class) */
    case Max = 'max';

    /** Minimum value (e.g., lowest CCN in a class) */
    case Min = 'min';

    /** Count of elements (e.g., number of methods) */
    case Count = 'count';

    /** 95th percentile of values (e.g., CBO excluding extreme outliers) */
    case Percentile95 = 'p95';

    /** 5th percentile of values (e.g., worst ~5% of MI scores) */
    case Percentile5 = 'p5';
}
