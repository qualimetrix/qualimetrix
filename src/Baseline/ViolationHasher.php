<?php

declare(strict_types=1);

namespace AiMessDetector\Baseline;

use AiMessDetector\Core\Violation\Violation;

/**
 * Generates stable hashes for violations to track them in baseline.
 *
 * Hash strategy: hash(rule + class + method_name + violationCode)
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
        $data = implode('|', [
            $violation->ruleName,
            $violation->symbolPath->namespace ?? '',
            $violation->symbolPath->type ?? '',
            $violation->symbolPath->member ?? '',
            $violation->violationCode,
        ]);

        // Use xxh3 if available (faster), otherwise sha256
        $algorithms = hash_algos();
        if (\in_array('xxh3', $algorithms, true)) {
            return substr(hash('xxh3', $data), 0, 16);
        }

        return substr(hash('sha256', $data), 0, 16);
    }
}
