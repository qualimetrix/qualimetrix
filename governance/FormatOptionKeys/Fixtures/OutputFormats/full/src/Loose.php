<?php

declare(strict_types=1);

/**
 * Deliberately outside every namespace: this is what makes `--group-by=namespace`
 * publish its `<global>` key.
 */
final class Loose
{
    public function go(): void
    {
        var_dump(2);
    }
}
