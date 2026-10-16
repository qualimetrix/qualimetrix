<?php

declare(strict_types=1);

namespace Qualimetrix\Core\SourceText;

use LogicException;

/** Byte-preserving UTF-8 publication and identity components. */
final class SourceBytes
{
    public static function isUtf8(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8');
    }

    /**
     * Reversible identity encoding; rawurldecode() restores every source byte.
     *
     * @param string $reserved ASCII separators owned by the enclosing identity grammar
     */
    public static function escape(string $value, string $reserved = ''): string
    {
        if (!mb_check_encoding($reserved, 'ASCII')) {
            throw new LogicException('Reserved source-byte separators must be ASCII.');
        }

        $replacements = ['%' => '%25'];
        foreach (str_split($reserved) as $byte) {
            $replacements[$byte] = \sprintf('%%%02X', \ord($byte));
        }

        return self::escapeInvalidBytes(strtr($value, $replacements));
    }

    /** Valid publication strings, including literal percent signs, stay unchanged. */
    public static function escapeInvalid(string $value): string
    {
        return self::isUtf8($value) ? $value : self::escape($value);
    }

    /** Escapes only invalid bytes; literal percent signs remain prose. */
    public static function escapeInvalidBytes(string $value): string
    {
        if (self::isUtf8($value)) {
            return $value;
        }

        $result = '';
        $length = \strlen($value);
        for ($offset = 0; $offset < $length;) {
            $width = self::validPrefixWidth($value, $offset, $length);
            if ($width === 0) {
                $result .= rawurlencode($value[$offset]);
                ++$offset;
            } else {
                $result .= substr($value, $offset, $width);
                $offset += $width;
            }
        }

        return $result;
    }

    /**
     * Keeps valid hash inputs unchanged and separates invalid input from literals.
     *
     * @return string|array{'%': string}
     */
    public static function framed(string $value): string|array
    {
        return self::isUtf8($value) ? $value : ['%' => self::escape($value)];
    }

    private static function validPrefixWidth(string $value, int $offset, int $length): int
    {
        $limit = min(4, $length - $offset);
        for ($width = 1; $width <= $limit; ++$width) {
            if (self::isUtf8(substr($value, $offset, $width))) {
                return $width;
            }
        }

        return 0;
    }
}
