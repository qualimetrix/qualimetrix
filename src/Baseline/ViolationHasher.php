<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use Qualimetrix\Core\Violation\Violation;

/**
 * Generates stable hashes for violations to track them in baseline.
 *
 * Hash strategy: hash(rule + class + method_name + violationCode)
 *
 * For dependency-based rules (when `dependencyTarget` is set on the Violation),
 * the target symbol and dependency type are appended to the payload so that
 * baselines can distinguish per-use-site edges between the same source and
 * different targets/types. When both new fields are null the payload is
 * identical to the legacy one, preserving existing baselines byte-for-byte.
 *
 * Does NOT include:
 * - line (line drift when adding code above)
 * - method parameters (renaming parameters should not invalidate baseline)
 * - message (rewording should not invalidate baseline)
 * - severity (may change when threshold changes)
 */
final readonly class ViolationHasher
{
    /**
     * Generates stable hash for violation.
     */
    public function hash(Violation $violation): string
    {
        $segments = [
            $violation->ruleName,
            $violation->symbolPath->namespace ?? '',
            $violation->symbolPath->type ?? '',
            $violation->symbolPath->member ?? '',
            $violation->violationCode,
        ];

        // Dependency-based rules: extend payload with target + type so that
        // per-use-site edges with the same source but different targets/types
        // produce distinct hashes. When dependencyTarget is null, the payload
        // is left untouched to preserve backward-compatible baselines.
        if ($violation->dependencyTarget !== null) {
            $segments[] = $violation->dependencyTarget->toCanonical();
            $segments[] = $violation->dependencyType !== null ? $violation->dependencyType->value : '';
        }

        $data = implode('|', $segments);

        // Use xxh3 if available (faster), otherwise sha256
        $algorithms = hash_algos();
        if (\in_array('xxh3', $algorithms, true)) {
            return substr(hash('xxh3', $data), 0, 16);
        }

        return substr(hash('sha256', $data), 0, 16);
    }
}
