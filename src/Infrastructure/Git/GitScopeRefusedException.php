<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

use InvalidArgumentException;

/**
 * A `--report=git:*` scope git will not answer for.
 *
 * Sibling of {@see UnresolvedGitReferenceException}, which names one revision.
 * This one names the whole scope, for the two shapes where no single revision
 * is at fault: a range that is not a range, and a scope whose revisions all
 * resolve while the command asking for its changes still fails.
 *
 * Both are input refusals — exit 3 — because the README's decision is that a
 * bad scope is an input refusal whatever went wrong behind it. Before this
 * class they reached the user as a Symfony Process dump under "Internal
 * error", which says the tool is broken rather than the scope.
 */
final class GitScopeRefusedException extends InvalidArgumentException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * A range git would read as more than two endpoints, e.g. `a..b..c`.
     *
     * Refused here rather than left to git because every part of it resolves,
     * so per-revision validation passes and only the diff fails.
     */
    public static function notATwoEndpointRange(string $scope, int $endpoints): self
    {
        return new self(\sprintf(
            'Git scope "%s" is not a range: a range has exactly two endpoints, this one has %d.',
            $scope,
            $endpoints,
        ));
    }

    /**
     * Git refused to list the changes of an already-validated scope.
     *
     * `$gitReport` is git's own stderr, relayed verbatim and never parsed, in
     * whatever language the environment gives git. It is allowed to be empty:
     * no measured input produces a git failure without text, which is why the
     * tail is suppressed on the text rather than on which caller arrived.
     */
    public static function commandFailed(string $scope, string $gitReport): self
    {
        return new self(\sprintf(
            'Git scope "%s" could not be listed.%s',
            $scope,
            $gitReport === '' ? '' : ' git: ' . $gitReport,
        ));
    }
}
