<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Configuration;

/**
 * What one run's paths are, measured against the project they belong to.
 *
 * Three answers, because "the paths cover the project" has two ways of being
 * true and they are not the same fact. `Covered`: the manifest declares the
 * project's code and the paths reach all of it. `Unknown`: the manifest
 * declares nothing readable, so there is no project beyond what the user
 * named, and the paths are it. `Narrowed`: the manifest declares code the paths
 * do not reach — the one state in which a statement that something is absent
 * from the project cannot be made from this run.
 *
 * Collapsing `Unknown` into either neighbour loses a channel. Read as
 * `Narrowed`, every project without a manifest silenced its layer typos,
 * stale excludes and stale suppressions for good. Read as `Covered`, a report
 * could not say that a whole-project verdict rested on the paths alone rather
 * than on a declared project.
 */
enum ProjectScopeState: string
{
    case Covered = 'covered';
    case Narrowed = 'narrowed';
    case Unknown = 'unknown';

    /** Whether a channel that speaks about the whole project may speak on this run. */
    public function coversProjectScope(): bool
    {
        return $this !== self::Narrowed;
    }
}
