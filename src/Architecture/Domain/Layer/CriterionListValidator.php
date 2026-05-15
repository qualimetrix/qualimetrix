<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Layer;

use InvalidArgumentException;

/**
 * Internal helper shared by {@see MembershipSpec} and {@see ExcludeSpec} for
 * per-kind validation of a criterion list. Stateless; package-internal.
 *
 * The two specs carry identical criterion lists (five {@code list<string>}
 * fields) with identical invariants (every entry must be a non-empty
 * string). Centralising the check here avoids the structural duplication
 * detector flagging the two near-identical {@code validateList} methods
 * the specs previously carried in parallel.
 *
 * Error messages identify the spec class via {@code $specLabel}
 * ({@code "MembershipSpec"} / {@code "ExcludeSpec"}) so callers see the same
 * "MembershipSpec patterns[2] must not be empty" wording the specs emit
 * directly.
 *
 * @internal Consumed by {@see MembershipSpec} and {@see ExcludeSpec}.
 */
final class CriterionListValidator
{
    /**
     * @param list<mixed> $values
     *
     * @throws InvalidArgumentException When any entry is a non-string or
     *                                  an empty string.
     */
    public static function validate(string $specLabel, string $kind, array $values): void
    {
        foreach ($values as $index => $value) {
            if (!\is_string($value)) {
                throw new InvalidArgumentException(\sprintf(
                    '%s %s[%d] must be a string, %s given.',
                    $specLabel,
                    $kind,
                    $index,
                    get_debug_type($value),
                ));
            }

            if ($value === '') {
                throw new InvalidArgumentException(\sprintf(
                    '%s %s[%d] must not be empty.',
                    $specLabel,
                    $kind,
                    $index,
                ));
            }
        }
    }
}
