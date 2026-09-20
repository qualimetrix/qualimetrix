<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Hook;

/**
 * Where the qmx binary running this process lives on disk.
 *
 * A port rather than a read of `$_SERVER` inside the command, because under
 * `CommandTester` that read answers with phpunit's own path: a test would go
 * green over a hook carrying a path no consumer could ever have, and the
 * branch that refuses when there is no path would have no way to be reached.
 */
interface RunningBinaryLocatorInterface
{
    /**
     * @return string|null absolute path, or null when the process cannot name
     *                     the file it was started from
     */
    public function path(): ?string;
}
