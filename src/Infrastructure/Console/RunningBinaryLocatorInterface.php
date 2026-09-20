<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

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

    /**
     * The same path, spelled for a user to copy into a shell.
     *
     * A hint has to name *some* binary, and the three spellings a reader
     * might be shown — `bin/qmx`, `vendor/bin/qmx`, `./qmx.phar` — are wrong
     * for two audiences each. The path this process was started from is right
     * for all of them.
     */
    public function hint(): string;
}
