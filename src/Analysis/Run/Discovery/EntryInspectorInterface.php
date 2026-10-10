<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

interface EntryInspectorInterface
{
    public function inspect(string $path): EntryKind;

    /** @return ?list<string> Names in the directory, or null when listing fails. */
    public function list(string $directory): ?array;
}
