<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Discovery;

interface ComposerAutoloadPathReaderInterface
{
    /** @return list<string> */
    public function extractAutoloadPaths(string $composerJsonPath, bool $includeDev = true): array;
}
