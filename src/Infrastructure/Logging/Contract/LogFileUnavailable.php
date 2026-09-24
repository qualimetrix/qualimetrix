<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Logging\Contract;

use RuntimeException;

/**
 * The log file a run was given cannot be written: its directory cannot be
 * created, or the file cannot be opened for appending.
 *
 * The path came from the user, so the caller that knows which option carried
 * it answers with a refusal about that option; this type only says what went
 * wrong with the path, in a clause that follows it (`$reason`).
 */
final class LogFileUnavailable extends RuntimeException
{
    public function __construct(
        public readonly string $path,
        public readonly string $reason,
    ) {
        parent::__construct(\sprintf('Log file "%s", %s.', $path, $reason));
    }
}
