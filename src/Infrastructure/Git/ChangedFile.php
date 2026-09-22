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
     *
     * A source name this build cannot carry unchanged lands as `null` too, and
     * that one is a loss rather than a judgement about scope. The two are told
     * apart in {@see GitClient::parseNameStatus()}, which reports the second;
     * from inside this method they are the same absent value.
     */
    public static function fromGitOutput(
        string $rawGitPath,
        ChangeStatus $status,
        ?string $rawOldGitPath,
        AbsolutePath $gitToplevel,
        AbsolutePath $projectRoot,
    ): ?self {
        if (!self::isRepresentableGitPath($rawGitPath)) {
            return null;
        }

        $path = PathFactory::gitRelative($rawGitPath, $gitToplevel, $projectRoot);

        if ($path === null) {
            return null;
        }

        $oldPath = null;
        if ($rawOldGitPath !== null && self::isRepresentableGitPath($rawOldGitPath)) {
            // Out-of-project old path is acceptable for cross-boundary rename/copy:
            // the change is still relevant to the project (new path is inside).
            $oldPath = PathFactory::gitRelative($rawOldGitPath, $gitToplevel, $projectRoot);
        }

        return new self($path, $status, $oldPath);
    }

    /**
     * Whether a raw git path survives this build's path model unchanged.
     *
     * POSIX allows every byte but `/` and NUL in a name, and `git diff -z`
     * hands those bytes over faithfully. {@see \Qualimetrix\Core\Path\RelativePath}
     * does not carry all of them: it rewrites `\` as a directory separator, so
     * the one file `a\b.php` is stored as the two segments `a/b.php`.
     *
     * Discovery rewrites it identically, so the two sides do agree — measured:
     * without this check the findings are published, under `a/b.php`. That is
     * the reason to refuse rather than a reason not to. The stored value is a
     * key: baselines, suppression maps and every reader of the report index by
     * it, and `a/b.php` is a name that file does not have and that a real
     * `a/b.php` already owns, so the two would silently share one identity.
     *
     * Refusing the row is containment, not a repair: it keeps the corrupted
     * key out of the answer and leaves the caller something to say. The repair
     * is in the path model, which is not this module's — until then a plain
     * run still publishes such a file under the rewritten name, and only the
     * git boundary declines to take part.
     */
    public static function isRepresentableGitPath(string $rawGitPath): bool
    {
        return !str_contains($rawGitPath, '\\');
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
