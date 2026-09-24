<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use Closure;
use Psr\Log\LoggerInterface;
use Qualimetrix\Core\Path\AbsolutePath;

/**
 * One `git diff --name-status -z` listing read into changed files, with the
 * rows {@see GitClient} could not hand on grouped by why and reported.
 */
final class NameStatusListing
{
    /**
     * The measured fact, not a diagnosis of it. A project root inside a git
     * subdirectory is the common cause and not the only one, so the causes are
     * listed rather than asserted.
     */
    private const string SKIPPED_OUTSIDE_ROOT =
        'whose path did not resolve inside the project root — the project root may be a subdirectory of the git tree, '
        . 'or the path may point outside it through a link';

    private const string SKIPPED_UNREPRESENTABLE =
        'whose name holds a backslash, which this build\'s relative-path model rewrites as a directory separator '
        . '(the file is real; the name cannot be carried through unchanged)';

    /**
     * `U` is the one refused status with a cause the user can act on: finish
     * the merge. An unmerged entry is not a version of a file — the index
     * holds two or three of them at once — so there is nothing here to hand
     * downstream as "the changed file".
     */
    private const string SKIPPED_UNMERGED =
        'left unmerged in the index by a conflict git has not been told is resolved — '
        . 'such an entry names no single version to analyse';

    /**
     * The catch-all, and deliberately a format string: the letter is the whole
     * of what is known, so it is quoted back rather than interpreted. `X` and
     * any letter a later git adds arrive here.
     */
    private const string SKIPPED_UNSUPPORTED_STATUS =
        'reported by git under status "%s", which this build does not know how to carry';

    private const string SOURCE_UNREPRESENTABLE =
        'whose source name holds a backslash and cannot be carried through this build\'s relative-path model. '
        . 'The file itself is analysed; only the name it was moved from is lost';

    /** @var array<string, list<string>> */
    private array $skipped = [];

    /** @var list<string> */
    private array $lostSources = [];

    private function __construct() {}

    /**
     * Turns the NUL-separated `git diff --name-status -z` stream into files.
     *
     * The stream is walked by {@see NameStatusRecord::parseStream()}; what
     * happens here is the project's own judgement on each record. Paths
     * returned by git are git-toplevel-relative and are translated to
     * project-relative form via {@see ChangedFile::fromGitOutput()}.
     *
     * A record this run cannot carry does not disappear: it is collected under
     * the reason it was dropped for, and each reason becomes one PSR-3
     * `warning` at the end of parsing. There is no silent path out of this
     * loop — a status letter the enum does not name is quoted back in its own
     * reason, so a later git letter is reported rather than lost.
     *
     * @param Closure(): AbsolutePath $gitToplevel asked at most once, and only for a record that needs it
     *
     * @return list<ChangedFile>
     */
    public static function changedFiles(string $output, AbsolutePath $projectRoot, Closure $gitToplevel, LoggerInterface $logger): array
    {
        $listing = new self();
        $files = $listing->read($output, $projectRoot, $gitToplevel);
        $listing->reportSkipped($logger);
        $listing->reportLostSources($logger);

        return $files;
    }

    /**
     * @param Closure(): AbsolutePath $gitToplevel
     *
     * @return list<ChangedFile>
     */
    private function read(string $output, AbsolutePath $projectRoot, Closure $gitToplevel): array
    {
        $toplevel = null;
        $files = [];

        foreach (NameStatusRecord::parseStream($output) as $record) {
            $status = ChangeStatus::tryFrom($record->status);

            if ($status === null) {
                $this->skipped[self::refusedStatusReason($record->status)][] = $record->rawPath;

                continue;
            }

            if (!ChangedFile::isRepresentableGitPath($record->rawPath)) {
                $this->skipped[self::SKIPPED_UNREPRESENTABLE][] = $record->rawPath;

                continue;
            }

            if ($record->rawOldPath !== null && !ChangedFile::isRepresentableGitPath($record->rawOldPath)) {
                $this->lostSources[] = $record->rawOldPath;
            }

            $toplevel ??= $gitToplevel();
            $changed = ChangedFile::fromGitOutput(
                $record->rawPath,
                $status,
                $record->rawOldPath,
                $toplevel,
                $projectRoot,
            );

            if ($changed === null) {
                $this->skipped[self::SKIPPED_OUTSIDE_ROOT][] = $record->rawPath;

                continue;
            }

            $files[] = $changed;
        }

        return array_values(array_unique($files, \SORT_REGULAR));
    }

    /**
     * Why a status letter the enum does not name was refused.
     *
     * `U` gets its own sentence because its cause is actionable and its shape
     * is understood; everything else is named by its letter alone, which is
     * all this build knows about it.
     */
    private static function refusedStatusReason(string $status): string
    {
        return $status === 'U'
            ? self::SKIPPED_UNMERGED
            : \sprintf(self::SKIPPED_UNSUPPORTED_STATUS, $status);
    }

    /**
     * One warning per reason, naming the measured fact first.
     *
     * The old single message named a subdirectory project root as *the* cause
     * of a path that would not relativize. It is one cause of several, and a
     * message that states a diagnosis the code did not measure sends the
     * reader looking in the wrong place.
     */
    private function reportSkipped(LoggerInterface $logger): void
    {
        foreach ($this->skipped as $reason => $paths) {
            $logger->warning(\sprintf(
                'Skipped %d changed file(s) %s. Raw git paths: %s',
                \count($paths),
                $reason,
                implode(', ', $paths),
            ));
        }
    }

    /**
     * The rename whose source name was lost, reported apart from the drops.
     *
     * Nothing downstream reads `oldPath` today, so this costs the run nothing
     * — which is the reason to say it rather than not to. The same condition
     * on the *new* name drops the record and is reported as a drop; saying
     * nothing here would make the two outcomes of one check look like one.
     */
    private function reportLostSources(LoggerInterface $logger): void
    {
        $paths = $this->lostSources;

        if ($paths === []) {
            return;
        }

        $logger->warning(\sprintf(
            'Kept %d changed file(s) %s. Raw git paths: %s',
            \count($paths),
            self::SOURCE_UNREPRESENTABLE,
            implode(', ', $paths),
        ));
    }
}
