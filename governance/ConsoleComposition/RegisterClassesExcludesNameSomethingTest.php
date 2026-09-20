<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ConsoleComposition;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every `registerClasses()` exclude names a file that exists.
 *
 * An exclude argument is how a configurator says "this directory holds classes
 * the container must not build". When a rename leaves one behind, nothing says
 * so. Measured on this tree: the extra classes are registered, Symfony defers
 * the autowiring failure to instantiation, and `RemoveUnusedDefinitionsPass`
 * drops the private definition before anything asks for it — so every output
 * format, the whole DI suite and `composer check` stay green while the
 * exclusion silently does nothing.
 *
 * Two excludes were already dead before the change that added this control,
 * and they are its argument: `Reporting/Health/{HealthScore.php,`
 * `WorstOffender.php,DecompositionItem.php}` named no file under
 * `Reporting/Health`, and `Analysis/Configuration/Pipeline/Stage/*Interface.php`
 * named no file either — that second one this control found on its first run.
 * The interface exclude was redundant besides: `registerClasses()` makes an
 * abstract autoconfiguration entry of an interface, never a service, whether
 * or not an exclude names it.
 *
 * A third, `Reporting/Formatter/Support/**`, was *live* until that same change
 * retired the directory it named. It is the occasion for this control rather
 * than evidence for it, and the distinction matters: an exclude that dies with
 * the commit that empties its directory is the case a reviewer sees, and the
 * two above are the case nobody does.
 *
 * The assertion is deliberately weak — a brace member must expand to at least
 * one existing path — because that is the part that goes stale on a rename.
 * Whether an excluded class *should* be excluded stays a review question.
 *
 * What this scan does not see, so that a later reader does not mistake it for
 * more than it is. It reads a `registerClasses(` call whose closing `);` is on
 * its own line, takes every `$this->srcDir . '...'` argument after the first as
 * an exclusion, and joins the string literals of one argument, so a brace list
 * spread over concatenated lines is expanded rather than truncated. Invisible
 * to it: a call written on one line, and a resource or exclusion passed as an
 * array or a variable. A path whose braces do not balance after joining is
 * refused rather than skipped — that was how the concatenated Baseline
 * exclusion passed as one live member while none of its twenty-one names was
 * checked. The population it does reach is asserted to be non-empty and
 * printed by `itReportsThePopulationItExamined()`, so a collapse of the sample
 * is visible rather than inferred.
 */
final class RegisterClassesExcludesNameSomethingTest extends TestCase
{
    /**
     * Exclude members the sweep reached when this floor was taken, over 24
     * `registerClasses()` calls. Twenty-one of them are the names inside the
     * Baseline exclusion, which reached the sweep only once concatenated
     * arguments were joined.
     */
    private const int EXPECTED_MEMBERS = 26;

    #[Test]
    public function itRefusesAnExcludeMemberThatNamesNoFile(): void
    {
        $root = \dirname(__DIR__, 2);
        $configurators = $root . '/src/Infrastructure/DependencyInjection/Configurator';

        $examined = 0;
        $dead = [];
        $unreadable = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $configurators,
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($files as $file) {
            \assert($file instanceof SplFileInfo);

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($root) + 1);
            $source = (string) file_get_contents($file->getPathname());

            foreach (self::excludeArgumentsOf($source) as $argument) {
                if (substr_count($argument, '{') !== substr_count($argument, '}')) {
                    // A truncated brace list is the dangerous shape: glob()
                    // resolves `.../Baseline/{` to the directory itself, so the
                    // member reads as live and none of the names inside it is
                    // ever checked.
                    $unreadable[] = $relative . ': ' . $argument;

                    continue;
                }

                foreach (self::braceMembersOf($argument) as $member) {
                    ++$examined;

                    if (glob($root . '/src' . $member, \GLOB_BRACE) !== []) {
                        continue;
                    }

                    $dead[] = $relative . ': ' . $member;
                }
            }
        }

        self::assertGreaterThan(
            0,
            $examined,
            'The scan found no exclude member at all, so a green result here would be about nothing.',
        );

        self::assertSame(
            [],
            $unreadable,
            'This exclude could not be read as a path, so its members went unchecked rather than checked.',
        );

        self::assertSame(
            [],
            $dead,
            'An exclude that names no file excludes nothing, and nothing else reports it.',
        );
    }

    /**
     * The size of the population the sweep reached, as a visible number rather
     * than an inference from a green result.
     */
    #[Test]
    public function itReportsThePopulationItExamined(): void
    {
        $root = \dirname(__DIR__, 2);
        $calls = 0;
        $members = 0;

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root . '/src/Infrastructure/DependencyInjection/Configurator',
            FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($files as $file) {
            \assert($file instanceof SplFileInfo);

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $found = preg_match_all('/registerClasses\(/', $source);
            $calls += $found === false ? 0 : $found;

            foreach (self::excludeArgumentsOf($source) as $argument) {
                $members += \count(self::braceMembersOf($argument));
            }
        }

        // A floor rather than a ceiling: a new exclusion is a normal change and
        // must not redden this, while a shrinking sample is either a deliberate
        // removal to re-take or the literal shape changing underneath the sweep.
        // Both deserve a reader.
        self::assertGreaterThanOrEqual(self::EXPECTED_MEMBERS, $members, \sprintf(
            'Of %d registerClasses() call(s) the sweep reached %d exclude member(s), below the %d it '
            . 'reached when this floor was taken. Either an exclusion was removed — say so by moving '
            . 'the floor — or the literal shape changed and the other case is now green about less '
            . 'than it was.',
            $calls,
            $members,
            self::EXPECTED_MEMBERS,
        ));
    }

    /**
     * The `$this->srcDir . '...'` arguments of every `registerClasses()` call,
     * minus the first one, which is the resource being scanned rather than an
     * exclusion.
     *
     * One argument may concatenate several string literals; they are joined,
     * because a brace list written across lines is one path, and reading only
     * its first fragment yields a truncated pattern that `glob()` happily
     * resolves to the enclosing directory.
     *
     * @return list<string>
     */
    private static function excludeArgumentsOf(string $source): array
    {
        if (preg_match_all('/registerClasses\((?<args>.*?)\n\s*\);/s', $source, $calls) === false) {
            return [];
        }

        $excludes = [];

        foreach ($calls['args'] as $arguments) {
            $paths = [];

            foreach (self::argumentsOf($arguments) as $argument) {
                if (!str_contains($argument, '$this->srcDir')) {
                    continue;
                }

                if (preg_match_all("/'([^']*)'/", $argument, $literals) === false) {
                    continue;
                }

                $paths[] = implode('', $literals[1]);
            }

            // The first srcDir argument is the resource glob; the rest exclude.
            array_shift($paths);

            foreach ($paths as $path) {
                $excludes[] = $path;
            }
        }

        return $excludes;
    }

    /**
     * One entry per top-level argument of an argument list.
     *
     * Splitting on commas inside string literals would cut a brace list into
     * pieces, so quoted regions are skipped.
     *
     * @return list<string>
     */
    private static function argumentsOf(string $arguments): array
    {
        $split = [];
        $current = '';
        $quoted = false;

        foreach (str_split($arguments) as $character) {
            if ($character === "'") {
                $quoted = !$quoted;
            }

            if ($character === ',' && !$quoted) {
                $split[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $split[] = $current;

        return $split;
    }

    /**
     * One path per brace alternative, so a single dead name inside a live brace
     * is reported instead of hiding behind its siblings.
     *
     * @return list<string>
     */
    private static function braceMembersOf(string $path): array
    {
        if (preg_match('/^(?<prefix>[^{]*)\{(?<members>[^}]*)\}(?<suffix>.*)$/', $path, $m) !== 1) {
            return [$path];
        }

        $expanded = [];

        foreach (explode(',', $m['members']) as $member) {
            $expanded[] = $m['prefix'] . $member . $m['suffix'];
        }

        return $expanded;
    }
}
