<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Represents a file changed in git.
 *
 * Paths are project-relative. The git boundary translation from git-toplevel-relative
 * (raw `git diff` output) to project-relative happens eagerly in {@see fromGitOutput()};
 * see ADR 0015 D5.
 */
final readonly class ChangedFile
{
    /**
     * @internal Use {@see fromGitOutput()} in production code. Direct construction is
     *           reserved for tests that build fixtures with pre-validated VOs.
     */
    public function __construct(
        public RelativePath $path,
        public ChangeStatus $status,
        public ?RelativePath $oldPath = null,
    ) {}

    /**
     * Builds a {@see ChangedFile} from one raw row of `git diff --name-status` output.
     *
     * The raw git path is git-toplevel-relative; it is resolved against `$gitToplevel`
     * to an absolute path and then relativized against `$projectRoot`. Returns `null`
     * when the **new** path lies outside the project root — typically when the
     * project root is a subdirectory of the git tree.
     *
     * For renames and copies the **old** path is allowed to lie outside the project
     * root: a file moved from `lib/Old.php` (outside project) to `project/New.php`
     * (inside project) is a legitimate addition to the project's scope, and the
     * inbound entry should still surface. When that happens `oldPath` is set to
     * `null` (the source isn't visible from inside the project) while the status
     * letter is preserved so downstream consumers can render the original action.
     */
    public static function fromGitOutput(
        string $rawGitPath,
        ChangeStatus $status,
        ?string $rawOldGitPath,
        AbsolutePath $gitToplevel,
        AbsolutePath $projectRoot,
    ): ?self {
        $path = PathFactory::gitRelative($rawGitPath, $gitToplevel, $projectRoot);

        if ($path === null) {
            return null;
        }

        $oldPath = null;
        if ($rawOldGitPath !== null) {
            // Out-of-project old path is acceptable for cross-boundary rename/copy:
            // the change is still relevant to the project (new path is inside).
            $oldPath = PathFactory::gitRelative($rawOldGitPath, $gitToplevel, $projectRoot);
        }

        return new self($path, $status, $oldPath);
    }

    /**
     * Returns true if this is a PHP file.
     */
    public function isPhp(): bool
    {
        return $this->path->extension() === 'php';
    }

    /**
     * Returns true if this file was deleted.
     */
    public function isDeleted(): bool
    {
        return $this->status === ChangeStatus::Deleted;
    }
}
