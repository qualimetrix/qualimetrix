<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Metric;

/**
 * Marker interface for collectors that can be safely instantiated in parallel workers.
 *
 * Parallel workers cannot use DI — collectors are instantiated via `new $className()`.
 * Only collectors implementing this interface will be used in parallel mode.
 * Collectors that require constructor dependencies should NOT implement this interface;
 * they will automatically fall back to sequential execution.
 *
 * Requirements for implementing classes:
 * - Must have no required constructor parameters
 * - Must not depend on external services (database, HTTP, etc.)
 * - All state must be self-contained and resettable via reset()
 */
interface ParallelSafeCollectorInterface {}
