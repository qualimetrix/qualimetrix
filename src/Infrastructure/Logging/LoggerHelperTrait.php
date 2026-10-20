<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging;

use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Qualimetrix\Core\SourceText\SourceBytes;
use stdClass;
use Stringable;

/**
 * Shared helpers for PSR-3 logger implementations.
 *
 * Provides message interpolation per PSR-3 spec, level filtering and the
 * JSON encoding both loggers use for context.
 */
trait LoggerHelperTrait
{
    /**
     * Interpolates context values into message placeholders per PSR-3 spec.
     *
     * Replaces `{key}` tokens in the message with corresponding context values.
     * Only scalar values and Stringable objects are interpolated, and only for
     * a placeholder the message carries: converting every context value would
     * make a non-finite float raise a PHP warning for a key nobody asked to see.
     *
     * @param array<string, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        if ($context === [] || !str_contains($message, '{')) {
            return $message;
        }

        $replace = [];
        foreach ($context as $key => $val) {
            $placeholder = '{' . $key . '}';
            $text = str_contains($message, $placeholder) ? self::placeholderText($val) : null;
            if ($text !== null) {
                $replace[$placeholder] = $text;
            }
        }

        return $replace === [] ? $message : strtr($message, $replace);
    }

    /** A scalar or Stringable as text; null for what PSR-3 leaves in the context only. */
    private static function placeholderText(mixed $value): ?string
    {
        if (\is_float($value) && !is_finite($value)) {
            return is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF');
        }

        return \is_string($value) || \is_int($value) || \is_float($value) || $value instanceof Stringable
            ? (string) $value
            : null;
    }

    /**
     * Determines if a message at the given level meets the minimum threshold.
     *
     * @throws InvalidArgumentException for a level outside PSR-3's eight
     */
    private function meetsMinLevel(string $level, string $minLevel): bool
    {
        return self::rank($level) >= self::rank($minLevel);
    }

    /**
     * PSR-3 requires a level outside its eight to be refused; ranking it
     * below every threshold instead dropped the message without a word.
     *
     * @throws InvalidArgumentException
     */
    private static function rank(string $level): int
    {
        return match ($level) {
            LogLevel::DEBUG => 0,
            LogLevel::INFO => 1,
            LogLevel::NOTICE => 2,
            LogLevel::WARNING => 3,
            LogLevel::ERROR => 4,
            LogLevel::CRITICAL => 5,
            LogLevel::ALERT => 6,
            LogLevel::EMERGENCY => 7,
            default => throw new InvalidArgumentException(\sprintf('Unknown log level "%s".', $level)),
        };
    }

    /**
     * A Linux path may contain invalid UTF-8; percent-encoding preserves its
     * bytes. What byte encoding cannot save — `INF`, `NAN` — returns null,
     * and the caller says the context was lost instead of writing nothing.
     * Repair accepts arrays and stdClass containers with UTF-8 keys, and
     * escapes string values only. Other objects or malformed keys lose the
     * context with its native error. The native guard may execute a custom
     * JSON callback a second time; repair never executes it again.
     */
    private static function encodeJson(mixed $value, ?string &$error = null): ?string
    {
        $error = null;
        $flags = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;
        $json = json_encode($value, $flags);
        if ($json === false) {
            $error = json_last_error_msg();
        }
        if ($json === false && json_last_error() === \JSON_ERROR_UTF8) {
            // A malformed string can mask recursion in the same context.
            // Let the native encoder refuse that before recursively escaping.
            if (json_encode($value, $flags | \JSON_INVALID_UTF8_SUBSTITUTE) === false) {
                $error = json_last_error_msg();

                return null;
            }
            $hasUnsupportedValue = false;
            $escaped = self::escapeStrings($value, $hasUnsupportedValue);
            if ($hasUnsupportedValue) {
                return null;
            }
            $json = json_encode($escaped, $flags);
            $error = $json === false ? json_last_error_msg() : null;
        }

        return $json === false ? null : $json;
    }

    private static function escapeStrings(mixed $value, bool &$hasUnsupportedValue): mixed
    {
        if (\is_string($value)) {
            return SourceBytes::escapeInvalid($value);
        }
        if (\is_object($value)) {
            if ($value::class !== stdClass::class) {
                $hasUnsupportedValue = true;

                return null;
            }

            return (object) self::escapeStrings((array) $value, $hasUnsupportedValue);
        }
        if (!\is_array($value)) {
            return $value;
        }

        $escaped = [];
        foreach ($value as $key => $item) {
            if (\is_string($key) && !SourceBytes::isUtf8($key)) {
                $hasUnsupportedValue = true;

                break;
            }
            $escaped[$key] = self::escapeStrings($item, $hasUnsupportedValue);
            if ($hasUnsupportedValue) {
                break;
            }
        }

        return $escaped;
    }
}
