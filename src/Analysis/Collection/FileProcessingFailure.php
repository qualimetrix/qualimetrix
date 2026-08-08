<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection;

/** The typed reason a file did not produce collected data. */
final readonly class FileProcessingFailure
{
    public function __construct(
        public FileProcessingFailureKind $kind,
        public string $message,
    ) {}
}
