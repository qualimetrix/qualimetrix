<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Exception;

use Exception;
use Qualimetrix\Core\Path\AbsolutePath;
use Throwable;

final class ParseException extends Exception
{
    public function __construct(
        public readonly AbsolutePath $filePath,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('Failed to parse %s: %s', $filePath->value(), $message),
            0,
            $previous,
        );
    }
}
