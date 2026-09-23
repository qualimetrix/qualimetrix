<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Discovery;

interface ComposerAutoloadPathReaderInterface
{
    /**
     * The production PSR-4 roots — the `autoload` section's.
     *
     * @return list<string>
     */
    public function extractAutoloadPaths(string $composerJsonPath): array;

    /**
     * The `autoload-dev` PSR-4 roots, apart from the production ones: whether
     * a run treats test code as the project is its configuration's decision,
     * so the two lists reach it separately.
     *
     * @return list<string>
     */
    public function extractAutoloadDevPaths(string $composerJsonPath): array;

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
     * Every path the manifest's **production** autoload declares, in the
     * spelling `composer.json` uses — `psr-4` and `psr-0` roots, `classmap`
     * entries and `files` entries alike.
     *
     * `extractAutoloadPaths()` answers with PSR-4 roots only, which is the
     * right answer for choosing default analysis paths and the wrong one for
     * measuring a run against the project: a manifest declaring production
     * code through `classmap`, `psr-0` or `files` would yield a denominator
     * short by however much those sections hold.
     *
     * A `classmap` entry may name a file and a `files` entry always does.
     * That is no obstacle to the comparison this feeds — a caller asks
     * whether the analysed paths contain the target, and containment answers
     * the same way for a file as for a directory.
     *
     * `null` means the manifest declares no production autoload this product
     * can read at all: it is absent, it does not parse, it has no `autoload`
     * section, or every production section in it is empty or malformed.
     * Only production sections count — `autoload-dev` is test code, outside
     * the denominator by design, and `exclude-from-classmap` removes code
     * rather than declaring it.
     *
     * @return ?list<string> paths relative to composer.json, or null when nothing production was declared
     */
    public function productionAutoloadTargets(string $composerJsonPath): ?array;

    /**
     * Every path the manifest's `autoload-dev` declares, read exactly as
     * {@see self::productionAutoloadTargets()} reads `autoload` — for a run
     * whose configuration counts test code as part of the project.
     *
     * @return ?list<string> paths relative to composer.json, or null when `autoload-dev` declares nothing readable
     */
    public function developmentAutoloadTargets(string $composerJsonPath): ?array;
}
