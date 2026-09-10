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

    /**
     * Whether the manifest declares production code through an autoload
     * mechanism this product does not read.
     *
     * `extractAutoloadPaths()` returns PSR-4 roots and nothing else, so its
     * result cannot distinguish "the manifest declares only these" from "the
     * manifest declares these and a `classmap` besides". A caller measuring a
     * run against the project needs the difference: in the second case its
     * denominator is short by however much the unread section declares, and a
     * verdict computed from it would call a partial run whole.
     *
     * Only production sections count. `autoload-dev` is test code, which is
     * outside the denominator by design, and `exclude-from-classmap` removes
     * code rather than declaring it. An empty or malformed section declares
     * nothing and is not a declaration either.
     */
    public function declaresUnreadableProductionAutoload(string $composerJsonPath): bool;
}
