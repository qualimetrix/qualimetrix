<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Discovery;

interface ComposerAutoloadPathReaderInterface
{
    /** @return list<string> */
    public function extractAutoloadPaths(string $composerJsonPath, bool $includeDev = true): array;

    /**
     * The PSR-4 map itself: namespace prefix as `composer.json` spells it,
     * mapped to the directories it is served from.
     *
     * `extractAutoloadPaths()` answers "which directories", which cannot tell
     * a caller *whose* code a directory holds. A caller judging a configured
     * namespace value needs the other half of the pair — which prefix lives
     * where — and reading `composer.json` a second time in its own parser
     * would be a second answer to the same question.
     *
     * `autoload-dev` is always included: a caller placing a namespace has to
     * see the test roots too, or a value naming test code reads as homeless.
     *
     * @return array<string, list<string>> prefix (trailing `\` as written) to paths relative to composer.json
     */
    public function extractPsr4Roots(string $composerJsonPath): array;
}
