<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Discovery;

interface ComposerAutoloadPathReaderInterface
{
    /**
     * The PSR-4 map itself: namespace prefix as `composer.json` spells it,
     * mapped to the directories it is served from.
     *
     * The target lists below answer "which paths", which cannot tell a
     * caller *whose* code a path holds. A caller judging a configured
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
     * entries and `files` entries alike, a `classmap` wildcard expanded to
     * the directories it matches.
     *
     * One answer with two readers: the paths a run with no `paths` analyses,
     * and the denominator a run is judged against when asked whether it
     * covered the project. A narrower list for either — PSR-4 roots only,
     * say — makes a run over the defaults warn about its own paths and
     * silences every channel that speaks only on a whole-project run.
     *
     * A `classmap` entry may name a file and a `files` entry always does.
     * Neither reader minds: discovery analyses a named file, and the
     * denominator asks whether an analysed path contains the target, which
     * answers the same way for a file as for a directory.
     *
     * `null` means the manifest declares no production autoload this product
     * can read at all: it is absent, it does not parse, it has no `autoload`
     * section, or every production section in it is empty or malformed.
     * `exclude-from-classmap` removes code rather than declaring it, so it
     * adds nothing here.
     *
     * @return ?list<string> paths relative to composer.json, or null when nothing production was declared
     */
    public function productionAutoloadTargets(string $composerJsonPath): ?array;

    /**
     * Every path the manifest's `autoload-dev` declares, read exactly as
     * {@see self::productionAutoloadTargets()} reads `autoload`. Apart from
     * it, because whether test code is part of the project is the run
     * configuration's decision, and both of that answer's readers take it
     * from the same pair of lists.
     *
     * @return ?list<string> paths relative to composer.json, or null when `autoload-dev` declares nothing readable
     */
    public function developmentAutoloadTargets(string $composerJsonPath): ?array;
}
