<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging;

use Psr\Log\LogLevel;
use Stringable;

/**
 * Shared helpers for PSR-3 logger implementations.
 *
 * Provides message interpolation per PSR-3 spec and level filtering.
 */
trait LoggerHelperTrait
{
    /**
     * Interpolates context values into message placeholders per PSR-3 spec.
     *
     * Replaces `{key}` tokens in the message with corresponding context values.
     * Only scalar values and Stringable objects are interpolated.
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
            if (\is_string($val) || is_numeric($val) || ($val instanceof Stringable)) {
                $replace['{' . $key . '}'] = (string) $val;
            }
        }

        return $replace === [] ? $message : strtr($message, $replace);
    }

    /**
     * Determines if a message at the given level meets the minimum threshold.
     */
    private function meetsMinLevel(string $level, string $minLevel): bool
    {
        /** @var array<string, int> */
        static $priorities = [
            LogLevel::DEBUG => 0,
            LogLevel::INFO => 1,
            LogLevel::NOTICE => 2,
            LogLevel::WARNING => 3,
            LogLevel::ERROR => 4,
            LogLevel::CRITICAL => 5,
            LogLevel::ALERT => 6,
            LogLevel::EMERGENCY => 7,
        ];

        return ($priorities[$level] ?? 0) >= ($priorities[$minLevel] ?? 0);
    }
}
