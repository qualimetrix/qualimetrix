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
     * @param array<mixed, mixed> $raw
     *
     * @throws BaselineEntryRejection
     */
    public static function decode(array $raw): self
    {
        return new self(
            self::readRequiredInt($raw, 'count', '"count" must be an integer'),
            self::readMagnitudes($raw),
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
