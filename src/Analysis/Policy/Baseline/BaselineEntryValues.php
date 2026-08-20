<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/**
 * The decoded value half of one baseline entry.
 *
 * Identity and channel applicability remain the parser's responsibility;
 * this subject owns only the strict JSON shapes for count, magnitudes, and
 * mode before {@see BaselineEntry} enforces their cross-field invariants.
 */
final readonly class BaselineEntryValues
{
    /**
     * @param ?list<int|float> $magnitudes
     */
    private function __construct(
        public int $count,
        public ?array $magnitudes,
        public ?BaselineEntryMode $mode,
    ) {}

    /**
     * `count` and `magnitudes` are never independently meaningful — a
     * magnitude-shaped entry's count is its magnitude list's length, by
     * {@see BaselineEntry}'s own invariant — so the writer stops serializing
     * `count` once `magnitudes` is present, and this decoder derives it back
     * rather than reading a field that could disagree with the list. Allowing
     * both fields to appear side by side would resurrect exactly the
     * redundancy being removed, so a file that still writes `count` next to
     * `magnitudes` is rejected as malformed rather than silently accepted.
     *
     * @param array<mixed, mixed> $raw
     *
     * @throws BaselineEntryRejection
     */
    public static function decode(array $raw): self
    {
        $magnitudes = self::readMagnitudes($raw);

        if ($magnitudes !== null) {
            if (\array_key_exists('count', $raw)) {
                throw new BaselineEntryRejection(
                    InertEntryReason::Malformed,
                    '"count" must not be present alongside "magnitudes"; it is derived from the magnitude list',
                );
            }

            return new self(\count($magnitudes), $magnitudes, self::readMode($raw));
        }

        return new self(
            self::readRequiredInt($raw, 'count', '"count" must be an integer'),
            null,
            self::readMode($raw),
        );
    }

    /**
     * @param array<mixed, mixed> $object
     *
     * @throws BaselineEntryRejection
     */
    private static function readRequiredInt(array $object, string $field, string $label): int
    {
        $value = $object[$field] ?? null;
        if (!\is_int($value)) {
            throw new BaselineEntryRejection(InertEntryReason::Malformed, $label);
        }

        return $value;
    }

    /**
     * @param array<mixed, mixed> $object
     *
     * @throws BaselineEntryRejection
     *
     * @return ?list<mixed>
     */
    private static function readOptionalList(array $object, string $field, string $label): ?array
    {
        $value = $object[$field] ?? null;
        if ($value === null) {
            return null;
        }

        if (!\is_array($value) || !array_is_list($value)) {
            throw new BaselineEntryRejection(InertEntryReason::Malformed, $label);
        }

        return $value;
    }

    /**
     * @param array<mixed, mixed> $raw
     *
     * @throws BaselineEntryRejection
     *
     * @return ?list<int|float>
     */
    private static function readMagnitudes(array $raw): ?array
    {
        $values = self::readOptionalList($raw, 'magnitudes', '"magnitudes" must be a JSON array');
        if ($values === null) {
            return null;
        }

        $invalidKey = array_find_key($values, static fn(mixed $value): bool => !\is_int($value) && !\is_float($value));
        if ($invalidKey !== null) {
            throw new BaselineEntryRejection(
                InertEntryReason::Malformed,
                \sprintf('"magnitudes" must hold numbers, found %s', self::describe($values[$invalidKey])),
            );
        }

        return $values;
    }

    /**
     * @param array<mixed, mixed> $raw
     *
     * @throws BaselineEntryRejection
     */
    private static function readMode(array $raw): ?BaselineEntryMode
    {
        $rawMode = $raw['mode'] ?? null;
        if ($rawMode === null) {
            return null;
        }

        $mode = \is_string($rawMode) ? BaselineEntryMode::tryFrom($rawMode) : null;
        if ($mode === null) {
            throw new BaselineEntryRejection(
                InertEntryReason::UnrecognizedMode,
                \sprintf('"mode" is not a recognized mode: %s', self::describe($rawMode)),
            );
        }

        return $mode;
    }

    private static function describe(mixed $value): string
    {
        return \is_string($value) ? '"' . $value . '"' : get_debug_type($value);
    }
}
