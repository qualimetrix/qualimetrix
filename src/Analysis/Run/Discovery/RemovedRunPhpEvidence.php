<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Core\Path\AbsolutePath;

/** Metadata evidence from a real authored removal of a selected run entry. */
final readonly class RemovedRunPhpEvidence
{
    public function __construct(private EntryInspectorInterface $inspector) {}

    public function search(AbsolutePath $path, EntryKind $kind): ?string
    {
        if ($kind === EntryKind::RegularFile) {
            return $this->isPhp($path) ? 'php-file' : null;
        }
        if ($kind !== EntryKind::Directory && $kind !== EntryKind::DirectoryLink) {
            return $kind === EntryKind::StatFailed ? 'unlistable' : null;
        }

        return $this->searchDirectory($path);
    }

    private function searchDirectory(AbsolutePath $path): ?string
    {
        $children = $this->inspector->list($path->value());
        if ($children === null) {
            return 'unlistable';
        }
        $unlistable = false;
        foreach ($children as $name) {
            $child = AbsolutePath::fromString($path->value() . '/' . $name);
            $childKind = $this->inspector->inspect($child->value());
            if ($childKind === EntryKind::DirectoryLink) {
                continue;
            }
            $result = $this->search($child, $childKind);
            if ($result === 'php-file') {
                return $result;
            }
            $unlistable = $unlistable || $result === 'unlistable';
        }

        return $unlistable ? 'unlistable' : null;
    }

    private function isPhp(AbsolutePath $path): bool
    {
        return str_ends_with($path->value(), '.php');
    }
}
