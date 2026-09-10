<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;

/**
 * Parses and validates the {@code architecture.coverage-gap} scalar.
 *
 * Accepts {@code null} (defaults to {@see CoverageMode::Ignore}) or a
 * case-insensitive string of {@code 'ignore'}, {@code 'warn'}, {@code 'error'}.
 */
final class CoverageValidator
{
    private const array ACCEPTED = ['error', 'ignore', 'warn'];

    public function validate(mixed $coverageRaw): CoverageMode
    {
        if ($coverageRaw === null) {
            return CoverageMode::Ignore;
        }

        if (!\is_string($coverageRaw)) {
            throw ConfigurationRefusal::atResolvedKey(
                RefusedPosition::closed(['architecture', 'coverage-gap'], get_debug_type($coverageRaw), self::ACCEPTED),
                \sprintf(
                    "architecture.coverage-gap: must be one of 'ignore', 'warn', 'error' (got %s).",
                    get_debug_type($coverageRaw),
                ),
            );
        }

        try {
            return CoverageMode::fromString($coverageRaw);
        } catch (InvalidArgumentException $e) {
            throw ConfigurationRefusal::atResolvedKey(
                RefusedPosition::closed(['architecture', 'coverage-gap'], $coverageRaw, self::ACCEPTED),
                \sprintf(
                    "architecture.coverage-gap: must be one of 'ignore', 'warn', 'error' (got '%s').",
                    $coverageRaw,
                ),
                previous: $e,
            );
        }
    }
}
