<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use RuntimeException;

/**
 * A `computed_metrics` configuration that cannot be resolved or validated.
 *
 * Raised for every user-supplied formula problem — syntax, level coverage,
 * circular references and unknown metric references — so the CLI can classify
 * them as input/configuration errors (exit 3) rather than internal crashes.
 */
final class ComputedMetricConfigurationException extends RuntimeException {}
