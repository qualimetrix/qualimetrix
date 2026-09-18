<?php

declare(strict_types=1);

namespace QmxTautologyControls\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxTautologyControls\Control;
use QmxTautologyControls\Controls;
use QmxTautologyControls\Suite;

/**
 * What the bench itself can get wrong, checked without paying for a clone.
 *
 * Both failures below are silent at run time in the direction that matters. A
 * mutation whose fragment no longer occurs stops planting anything, and the
 * control then reads as "the repair held" — the harness does refuse it, but
 * only after ninety seconds and only if somebody runs it. A declared case name
 * that no class carries is the same story from the other end. Reading both off
 * the tree in a second is what keeps them from waiting for the next full run.
 */
final class TautologyControlDeclarationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $scripts = \dirname(__DIR__, 3) . '/scripts';

        require_once $scripts . '/finding-gate-controls/Shell.php';
        require_once $scripts . '/finding-gate-controls/Scratch.php';
        require_once $scripts . '/finding-gate-controls/Mutation.php';

        foreach (['Control', 'Controls', 'Suite', 'Outcome', 'Report'] as $part) {
            require_once $scripts . '/tautology-controls/' . $part . '.php';
        }
    }

    #[Test]
    public function itDeclaresExactlyOnePositiveControlAndNoRepeatedId(): void
    {
        $ids = array_map(static fn(Control $control): string => $control->id, Controls::all());
        $positive = array_filter(Controls::all(), static fn(Control $control): bool => $control->isPositive());

        self::assertCount(1, $positive, 'The unmutated run is what tells a red table from a broken clone.');
        self::assertSame($ids, array_values(array_unique($ids)));
    }

    /**
     * Each control edits exactly one production file, and the fragment it
     * replaces is there exactly once.
     */
    #[Test]
    public function itPlantsEachBreakageInProductionCodeThatStillLooksLikeThat(): void
    {
        $root = \dirname(__DIR__, 3);
        $wrong = [];

        foreach (Controls::all() as $control) {
            if ($control->isPositive()) {
                continue;
            }

            foreach ($control->mutation->relativePaths() as $path) {
                if (!str_starts_with($path, 'src/')) {
                    $wrong[] = \sprintf('%s edits %s, which is not production code', $control->id, $path);
                }

                if (!is_file($root . '/' . $path)) {
                    $wrong[] = \sprintf('%s edits %s, which does not exist', $control->id, $path);

                    continue;
                }

                $source = (string) file_get_contents($root . '/' . $path);

                foreach (array_keys($control->fragments) as $fragment) {
                    $occurrences = substr_count($source, $fragment);

                    if ($occurrences !== 1) {
                        $wrong[] = \sprintf(
                            '%s expects exactly one occurrence of %s in %s, found %d — the product moved, so this'
                            . ' control would plant nothing and read as "the repair held"',
                            $control->id,
                            var_export($fragment, true),
                            $path,
                            $occurrences,
                        );
                    }
                }
            }
        }

        self::assertSame([], $wrong, implode("\n", $wrong));
    }

    /**
     * Every repaired case names a class and a method one of the population
     * files declares.
     *
     * Read out of the files rather than through the autoloader: one of them
     * declares a namespace its path does not support — a known, tracked
     * violation — so it is not autoloadable at all, and a check built on
     * `class_exists()` would report it as a renamed case forever.
     *
     * The bench compares exact PHPUnit case names, so a rename here is a
     * declaration that can never go red, which the harness reports only once
     * somebody has waited for a full run.
     */
    #[Test]
    public function itNamesOnlyCasesThatExist(): void
    {
        $declared = self::methodsDeclaredByThePopulation();
        $missing = [];

        foreach (Controls::repaired() as $case) {
            [$class, $method] = explode('::', $case, 2);
            $method = preg_replace('/ with data set ".*"$/', '', $method) ?? $method;

            if (!isset($declared[$class])) {
                $missing[] = \sprintf('%s: no population file declares that class', $case);

                continue;
            }

            if (!\in_array($method, $declared[$class], true)) {
                $missing[] = \sprintf('%s: the class declares no such method', $case);
            }
        }

        self::assertSame([], $missing, implode("\n", $missing));
    }

    /**
     * Dotted class name, as PHPUnit writes it into JUnit, => its methods.
     *
     * @return array<string, list<string>>
     */
    private static function methodsDeclaredByThePopulation(): array
    {
        $root = \dirname(__DIR__, 3);
        $declared = [];

        foreach (Suite::FILES as $file) {
            $source = (string) file_get_contents($root . '/' . $file);

            if (
                preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1
                || preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $class) !== 1
            ) {
                continue;
            }

            preg_match_all('/public function (\w+)\(/', $source, $methods);

            $declared[str_replace('\\', '.', trim($namespace[1])) . '.' . $class[1]] = $methods[1];
        }

        return $declared;
    }

    #[Test]
    public function itRunsEveryFileTheRepairedCasesLiveIn(): void
    {
        $root = \dirname(__DIR__, 3);
        $absent = [];

        foreach (Suite::FILES as $file) {
            if (!is_file($root . '/' . $file)) {
                $absent[] = $file;
            }
        }

        self::assertSame([], $absent, 'A population file that is not on disk makes the whole run narrower in silence.');
        self::assertNotSame([], Controls::repaired());
    }
}
