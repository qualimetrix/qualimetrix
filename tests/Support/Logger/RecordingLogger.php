<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Support\Logger;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 logger that captures every log record in memory for assertions.
 *
 * Each record is exposed as a `['level' => string, 'message' => string,
 * 'context' => array]` shape. Used by configuration and infrastructure
 * tests that need to verify what was emitted to the user-facing logger.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
