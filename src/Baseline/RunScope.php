<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;

/**
 * The path set a run analysed, in the portable form a baseline file records
 * as its `scope` (§6 of the baseline-ceiling plan), together with the
 * coverage predicate the scope guard of §5.7 is built on.
 *
 * **One type, because portability and coverage are one rule.** The two used
 * to live apart: each side of the guard derived the portable form itself and
 * a separate predicate compared the results. A path *equal to* the project
 * root has no relative form, so both sides recorded it as an absolute machine
 * path — which put `/Users/<you>/...` into a tracked file (against the
 * repository's own rule on absolute home paths) and then made the widest
 * possible run, `bin/qmx baseline:generate baseline.json .`, read as
 * *narrower* than a run over `src`. Deriving the form and comparing it in one
 * place is what keeps the two answers from disagreeing.
 *
 * **The guard's direction.** `baseline:update` and `baseline:cleanup` refuse
 * to run when the current run does not cover the file's recorded scope,
 * overridable with `--force`. The hazard is one-directional: a run *narrower*
 * than the recorded scope makes every identity outside it look absent, so
 * `cleanup` would offer to delete the rest of the file as stale and `update`
 * would silently leave it untouched. A *wider* run is harmless — it simply
 * measures more than the file remembers. `check` only reports a mismatch; it
 * never fails on one.
 *
 * **Coverage is by whole path segment, not by string prefix.** `src` covers
 * `src/Foo` (a full path component was named) but does not cover `srcfoo` (a
 * bare substring match), and `src` is not covered by `src/Foo` (a child does
 * not stand in for its parent). Two paths cover unconditionally: `.`, the
 * project root, covers every project-relative path, and `/`, the filesystem
 * root, covers everything at all.
 */
final readonly class RunScope
{
    /** The project root's portable form — the widest scope a run can record. */
    public const string PROJECT_ROOT = '.';

    /**
     * @param list<string> $paths portable, normalised
     */
    private function __construct(
        private array $paths,
    ) {}

    /**
     * The scope a run records: project-relative where possible, normalised.
     * A path equal to the project root records as ".", never as an absolute
     * machine path.
     *
     * A path genuinely *outside* the project root has no relative form and is
     * kept as given — the analysed tree really is elsewhere, and inventing a
     * relative form for it would misrepresent what was measured. That is the
     * one case in which an absolute path can still reach a baseline file, and
     * it is a case the user created deliberately by naming a path outside the
     * project.
     *
     * @param list<AbsolutePath> $paths the paths the run analysed
     */
    public static function record(array $paths, AbsolutePath $projectRoot): self
    {
        $portable = [];

        foreach ($paths as $path) {
            if ($path->value() === $projectRoot->value()) {
                // The project root relativizes to nothing, not to a relative
                // path — see AbsolutePath::tryRelativizeTo(). Naming it "."
                // is what keeps the widest possible run out of the absolute
                // fallback below.
                $portable[] = self::PROJECT_ROOT;

                continue;
            }

            $relative = PathFactory::tryProjectRelative($path->value(), $projectRoot);
            $portable[] = $relative?->value() ?? $path->value();
        }

        return new self(Baseline::normalizeScope($portable));
    }

    /**
     * @param list<string> $paths already portable and normalised
     */
    public static function fromRecorded(array $paths): self
    {
        return new self($paths);
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    /**
     * @param list<string> $recordedScope
     */
    public function covers(array $recordedScope): bool
    {
        return $this->uncoveredPaths($recordedScope) === [];
    }

    /**
     * The recorded paths this run does not cover.
     *
     * Returned rather than a bare bool because the refusal message a command
     * prints names exactly what is missing (§5.7).
     *
     * @param list<string> $recordedScope
     *
     * @return list<string>
     */
    public function uncoveredPaths(array $recordedScope): array
    {
        $uncovered = [];

        foreach ($recordedScope as $recorded) {
            if (!$this->isCovered($recorded)) {
                $uncovered[] = $recorded;
            }
        }

        return $uncovered;
    }

    private function isCovered(string $recorded): bool
    {
        foreach ($this->paths as $run) {
            if (self::pathCovers($run, $recorded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$candidate` covers `$path` — equal to it, or its ancestor by
     * a whole path segment.
     */
    private static function pathCovers(string $candidate, string $path): bool
    {
        if ($candidate === $path) {
            return true;
        }

        if ($candidate === '/') {
            // The filesystem root covers every path. Baseline::normalizeScope()
            // keeps "/" as a path in its own right rather than collapsing it
            // to "", so it must be handled before the segment check below,
            // which would otherwise look for the path to start with "//".
            return true;
        }

        if ($candidate === self::PROJECT_ROOT) {
            // "." is the root of the project tree: every project-relative
            // path lies under it. An absolute path does not — it may name a
            // directory outside the project entirely — so it is left to the
            // segment check.
            return !str_starts_with($path, '/');
        }

        return str_starts_with($path, $candidate . '/');
    }
}
