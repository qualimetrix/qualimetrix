<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git\Exception;

use InvalidArgumentException;

/** A user-supplied git revision that cannot be resolved to a commit. */
final class UnresolvedGitReferenceException extends InvalidArgumentException
{
    public function __construct(public readonly string $reference)
    {
        parent::__construct(\sprintf('Git reference "%s" does not resolve to a commit.', $reference));
    }
}
