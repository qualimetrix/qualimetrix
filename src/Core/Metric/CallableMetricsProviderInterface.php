<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Metric;

use Qualimetrix\Core\Path\RelativePath;

/**
 * Contract for collectors that provide callable-level metrics.
 */
interface CallableMetricsProviderInterface
{
    /**
     * Returns callable metrics collected during AST traversal.
     *
     * @return list<CallableWithMetrics>
     */
    public function getCallablesWithMetrics(RelativePath $file): array;
}
