<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationException;

/**
 * Parses and validates the {@code architecture.coverage} scalar.
 *
 * Accepts {@code null} (defaults to {@see CoverageMode::Ignore}) or a
 * case-insensitive string of {@code 'ignore'}, {@code 'warn'}, {@code 'error'}.
 */
final class CoverageValidator
{
    private const string CONFIG_PATH = 'architecture';

    public function validate(mixed $coverageRaw): CoverageMode
    {
        if ($coverageRaw === null) {
            return CoverageMode::Ignore;
        }

        if (!\is_string($coverageRaw)) {
            throw new ArchitectureConfigurationException(
                self::CONFIG_PATH,
                \sprintf(
                    "architecture.coverage: must be one of 'ignore', 'warn', 'error' (got %s).",
                    get_debug_type($coverageRaw),
                ),
            );
        }

        try {
            return CoverageMode::fromString($coverageRaw);
        } catch (InvalidArgumentException $e) {
            throw new ArchitectureConfigurationException(
                self::CONFIG_PATH,
                \sprintf(
                    "architecture.coverage: must be one of 'ignore', 'warn', 'error' (got '%s').",
                    $coverageRaw,
                ),
                $e,
            );
        }
    }
}
