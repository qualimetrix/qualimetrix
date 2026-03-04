<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Git;

/**
 * Represents a file changed in git.
 *
 * Contains the file path, change status, and optionally the old path (for renames).
 */
final readonly class ChangedFile
{
    public function __construct(
        public string $path,
        public ChangeStatus $status,
        public ?string $oldPath = null,
    ) {}

    /**
     * Returns true if this is a PHP file.
     */
    public function isPhp(): bool
    {
        return str_ends_with($this->path, '.php');
    }

    /**
     * Returns true if this file was deleted.
     */
    public function isDeleted(): bool
    {
        return $this->status === ChangeStatus::Deleted;
    }
}
