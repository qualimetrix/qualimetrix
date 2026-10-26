<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

/**
 * How many removed or generated PHP files one discovery walk may read for
 * their namespaces.
 *
 * A project excluding a dependency tree would otherwise read and tokenize all
 * of it. Past the limit the walk stops reading and the namespaces become
 * unknown, which withholds the claims they would have allowed.
 */
final class RemovedNamespaceBudget
{
    public const int LIMIT = 2000;

    private int $used = 0;

    private bool $exhausted = false;

    public function __construct(private readonly int $limit = self::LIMIT) {}

    /** Takes one file read; false, for this and every later file, once the limit is spent. */
    public function take(): bool
    {
        if ($this->used >= $this->limit) {
            $this->exhausted = true;

            return false;
        }
        ++$this->used;

        return true;
    }

    public function exhausted(): bool
    {
        return $this->exhausted;
    }
}
