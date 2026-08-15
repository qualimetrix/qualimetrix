<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git\Exception;

use InvalidArgumentException;

/**
 * A `--report=git:*` request made outside any git repository.
 *
 * Raised instead of a generic git command failure so the CLI can classify it
 * as an input error (exit 3) and name the actual problem for the user.
 */
final class NotAGitRepositoryException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct(
            'Not inside a git repository: --report=git:* requires the analyzed paths to be inside a git repository.',
        );
    }
}
