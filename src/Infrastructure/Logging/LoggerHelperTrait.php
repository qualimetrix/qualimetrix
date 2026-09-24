<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging;

use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
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
     * `JSON_INVALID_UTF8_SUBSTITUTE`: a file path on a Linux file system can
     * carry bytes that are not UTF-8, and substituting them keeps the value
     * readable. What substitution cannot save — `INF`, `NAN` — returns null,
     * and the caller says the context was lost instead of writing nothing.
     */
    private static function encodeJson(mixed $value): ?string
    {
        $json = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? null : $json;
    }
}
