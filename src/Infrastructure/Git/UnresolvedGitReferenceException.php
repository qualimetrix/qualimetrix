<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use InvalidArgumentException;

/**
 * A user-supplied git revision that cannot be resolved to a commit.
 *
 * `$gitReport` is git's own stderr, relayed verbatim and never parsed, in
 * whatever language the environment gives git.
 *
 * Two refusals carry no quote, and the message has to read correctly for
 * both: an empty revision, refused before git is asked, and — should it ever
 * happen — a git that failed with nothing on stderr. No measured input
 * produces the second, which is exactly why suppressing the `git:` tail is
 * written as a condition on the text rather than on which caller arrived.
 */
final class UnresolvedGitReferenceException extends InvalidArgumentException
{
    public function __construct(public readonly string $reference, ?string $gitReport = null)
    {
        parent::__construct(\sprintf(
            'Git reference "%s" does not resolve to a commit.%s',
            $reference,
            $gitReport === null || $gitReport === '' ? '' : ' git: ' . $gitReport,
        ));
    }
}
