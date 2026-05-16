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
 * Lives in {@code Qualimetrix\Architecture\Domain} (the vertical slice's
 * pure-domain layer per ADR 0010) so it stays free of Configuration and
 * Rules dependencies — both reach it through the slice's own surfaces.
 */
enum CoverageMode: string
{
    case Ignore = 'ignore';
    case Warn = 'warn';
    case Error = 'error';

    /**
     * Resolves a case-insensitive string to a case.
     *
     * Kept free of cross-domain exception types so the enum stays in the
     * slice's Domain layer. The slice's Configuration component translates
     * the InvalidArgumentException into a YAML-path-hinting exception.
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
