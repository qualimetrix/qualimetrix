<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging;

use JsonSerializable;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Qualimetrix\Core\SourceText\SourceBytes;
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
     * A context needing UTF-8 repair that contains JsonSerializable is
     * refused: the native guard may execute its callback a second time,
     * but repair never executes it again.
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
            $unsupported = false;
            $escaped = self::escapeStrings($value, $unsupported);
            if ($unsupported) {
                return null;
            }
            $json = json_encode($escaped, $flags);
            $error = $json === false ? json_last_error_msg() : null;
        }

        return $json === false ? null : $json;
    }

    private static function escapeStrings(mixed $value, bool &$unsupported): mixed
    {
        if (\is_string($value)) {
            return SourceBytes::escapeInvalid($value);
        }
        if ($value instanceof JsonSerializable) {
            $unsupported = true;

            return null;
        }
        if (\is_object($value)) {
            $properties = array_filter(get_mangled_object_vars($value), static fn(string $key): bool => !str_contains($key, "\0"), \ARRAY_FILTER_USE_KEY);

            return (object) self::escapeStrings($properties, $unsupported);
        }
        if (!\is_array($value)) {
            return $value;
        }

        $escaped = [];
        foreach ($value as $key => $item) {
            $escaped[\is_string($key) ? SourceBytes::escapeInvalid($key) : $key] = self::escapeStrings($item, $unsupported);
            if ($unsupported) {
                break;
            }
        }

        return $escaped;
    }
}
