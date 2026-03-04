<?php

declare(strict_types=1);

namespace AiMessDetector\Baseline;

use AiMessDetector\Core\Violation\Violation;

/**
 * Generates stable hashes for violations to track them in baseline.
 *
 * Hash strategy: hash(rule + class + method_name + normalized_message)
 *
 * Does NOT include:
 * - line (line drift when adding code above)
 * - method parameters (renaming parameters should not invalidate baseline)
 * - actual numeric values in message (15 → 20)
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
            $this->normalizeMessage($violation->message),
        ]);

        // Use xxh3 if available (faster), otherwise sha256
        if (\function_exists('hash')) {
            $algorithms = hash_algos();
            if (\in_array('xxh3', $algorithms, true)) {
                return substr(hash('xxh3', $data), 0, 8);
            }
        }

        return substr(hash('sha256', $data), 0, 8);
    }

    /**
     * Removes numeric values from message for stability.
     *
     * Example: "Complexity 15 exceeds 10" => "Complexity  exceeds "
     */
    private function normalizeMessage(string $message): string
    {
        return preg_replace('/\d+/', '', $message) ?? $message;
    }
}
