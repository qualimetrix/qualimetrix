<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\RemovedCodeNamespaces;
use Qualimetrix\Core\Path\AbsolutePath;

/**
 * What a real authored removal of a selected run entry took out of the run: the
 * selector evidence (metadata only) and the namespaces its PHP files declare.
 *
 * The descent visits every regular `.php` file under the entry, because the
 * selector verdict needs one witness while the namespaces need all of them,
 * until the walk's read budget is spent; from then on it stops at the first
 * PHP file, as the verdict needs no more.
 */
final readonly class RemovedRunPhpEvidence
{
    public function __construct(private EntryInspectorInterface $inspector, private RemovedNamespaceBudget $budget) {}

    /** @return array{?string, RemovedCodeNamespaces} selector evidence (`php-file`, `unlistable` or null) and declared namespaces */
    public function search(AbsolutePath $path, EntryKind $kind): array
    {
        if ($kind === EntryKind::RegularFile) {
            return $this->isPhp($path) ? ['php-file', $this->scanned($path)] : [null, RemovedCodeNamespaces::none()];
        }
        if ($kind !== EntryKind::Directory && $kind !== EntryKind::DirectoryLink) {
            return $kind === EntryKind::StatFailed ? ['unlistable', RemovedCodeNamespaces::unknown()] : [null, RemovedCodeNamespaces::none()];
        }

        return $this->searchDirectory($path);
    }

    /** @return array{?string, RemovedCodeNamespaces} */
    private function searchDirectory(AbsolutePath $path): array
    {
        $children = $this->inspector->list($path->value());
        if ($children === null) {
            return ['unlistable', RemovedCodeNamespaces::unknown()];
        }
        $phpFile = false;
        $unlistable = false;
        $namespaces = RemovedCodeNamespaces::none();
        foreach ($children as $name) {
            $child = AbsolutePath::fromString($path->value() . '/' . $name);
            $childKind = $this->inspector->inspect($child->value());
            if ($childKind === EntryKind::DirectoryLink) {
                continue;
            }
            [$result, $declared] = $this->search($child, $childKind);
            $phpFile = $phpFile || $result === 'php-file';
            $unlistable = $unlistable || $result === 'unlistable';
            $namespaces = $namespaces->merge($declared);
            if ($phpFile && $this->budget->exhausted()) {
                break;
            }
        }

        return [$phpFile ? 'php-file' : ($unlistable ? 'unlistable' : null), $namespaces];
    }

    private function scanned(AbsolutePath $path): RemovedCodeNamespaces
    {
        if (!$this->budget->take()) {
            return RemovedCodeNamespaces::unknown();
        }
        $declared = RemovedNamespaceScan::declaredIn($path);

        return $declared === null ? RemovedCodeNamespaces::unknown() : RemovedCodeNamespaces::known($declared);
    }

    private function isPhp(AbsolutePath $path): bool
    {
        return str_ends_with($path->value(), '.php');
    }
}
