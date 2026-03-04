<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Exception;

use Exception;
use Throwable;

final class ParseException extends Exception
{
    public function __construct(
        public readonly string $filePath,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('Failed to parse %s: %s', $filePath, $message),
            0,
            $previous,
        );
    }
}
