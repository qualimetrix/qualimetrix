<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Json;

/**
 * Sanitizes numeric values for JSON encoding.
 *
 * Converts non-finite float values (NaN, INF, -INF) to null so that
 * json_encode() does not throw or produce invalid JSON.
 */
final class JsonSanitizer
{
    /**
     * Sanitizes a float for JSON encoding (NaN/INF -> null).
     */
    public function sanitizeFloat(float $value): ?float
    {
        return is_finite($value) ? $value : null;
    }

    /**
     * Sanitizes a numeric value for JSON encoding (NaN/INF -> null).
     */
    public function sanitizeNumeric(int|float|null $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        if (\is_float($value) && !is_finite($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Sanitizes an array of float values for JSON encoding.
     *
     * @param array<string, int|float> $values
     *
     * @return array<string, int|float|null>
     */
    public function sanitizeFloatArray(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[$key] = $this->sanitizeNumeric($value);
        }

        return $result;
    }
}
