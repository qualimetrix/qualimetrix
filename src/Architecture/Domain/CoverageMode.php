<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain;

use InvalidArgumentException;

/**
 * Selects what to do with dependency edges whose source or target class does
 * not belong to any declared architecture layer.
 *
 * - {@see CoverageMode::Ignore} (default): out-of-layer edges are silently skipped.
 * - {@see CoverageMode::Warn}: produce an informational diagnostic per analysis run.
 * - {@see CoverageMode::Error}: produce an error-severity diagnostic per analysis run.
 *
 * The enum carries the user-facing string representation as its case value so that
 * configuration can round-trip without a separate mapping table.
 *
 * Lives in the Core domain so that {@see \Qualimetrix\Core\Rule\AnalysisContext}
 * and rules (which cannot depend on Configuration) can reference it directly.
 */
enum CoverageMode: string
{
    case Ignore = 'ignore';
    case Warn = 'warn';
    case Error = 'error';

    /**
     * Resolves a case-insensitive string to a case.
     *
     * Kept free of cross-domain exception types so that the enum stays in the
     * Core domain. Configuration-layer factories translate the
     * InvalidArgumentException into a domain-appropriate exception with a
     * YAML-path hint.
     *
     * @throws InvalidArgumentException If the string does not name a known case.
     */
    public static function fromString(string $value): self
    {
        $normalized = strtolower($value);

        foreach (self::cases() as $case) {
            if ($case->value === $normalized) {
                return $case;
            }
        }

        $allowed = implode(', ', array_map(static fn(self $c): string => "'{$c->value}'", self::cases()));

        throw new InvalidArgumentException(\sprintf(
            'Unknown coverage mode "%s"; expected one of %s.',
            $value,
            $allowed,
        ));
    }
}
