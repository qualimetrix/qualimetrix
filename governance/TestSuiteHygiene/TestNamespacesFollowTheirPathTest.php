<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\TestSuiteHygiene;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A test file's namespace says where the file is.
 *
 * `expected = psr4_prefix + relative directory, / -> \`. When the two disagree
 * the class is not autoloadable at all — `composer dump-autoload -o` prints
 * "does not comply with psr-4 … Skipping" for every one of them — and every tool
 * that goes from a name to a file, or from a file to a name, is reading a map
 * the filesystem does not agree with. The one in this group that pays for it
 * directly is {@see TestFilesAreExecutedTest}, which has to parse each file for
 * what it declares precisely because the name cannot be derived from the path.
 *
 * **Scope is `*Test.php` under the PSR-4 dev roots.** The non-test files that
 * break the same rule all sit under a `Fixtures/` directory: they are analyser
 * input — sample projects whose foreign namespaces are the thing being measured
 * — so making them comply would destroy what they are for. They are excluded by
 * being fixtures, not by being awkward.
 *
 * **The known violations ship as {@see NamespacePathAllowList}, a tracked file
 * of its own.** Two refusals, and the second matters as much as the first: a
 * violation the list does not carry, and a row in the list that no longer
 * describes a violation. A stale row is the same defect as a missed one — a
 * statement about the tree that stopped being true while still being believed.
 *
 * Emptying the list is deliberately not this guard's job: it is a rename per
 * file, and the check has to exist before the renames can be proved to have
 * finished.
 */
final class TestNamespacesFollowTheirPathTest extends TestCase
{
    #[Test]
    public function itFindsNoTestFileWhoseNamespaceDisagreesWithItsPath(): void
    {
        $roots = TestTree::autoloadDevRoots();
        $unexpected = [];

        foreach (array_diff_assoc(NamespacePathAllowList::measure(), NamespacePathAllowList::load()) as $path => $declared) {
            $unexpected[] = \sprintf(
                '%s declares %s, and its path says %s',
                $path,
                $declared,
                NamespacePathAllowList::expectedNamespace($roots, $path) ?? '(no PSR-4 root)',
            );
        }

        self::assertSame([], $unexpected, \sprintf(
            "%d test file(s) declare a namespace their path does not support, so nothing can autoload them.\n"
            . "Rename the namespace to match the directory. Do not add a row to %s by hand:\n%s",
            \count($unexpected),
            NamespacePathAllowList::PATH,
            implode("\n", $unexpected),
        ));
    }

    #[Test]
    public function itCarriesNoStaleAllowListEntry(): void
    {
        $stale = [];

        foreach (array_diff_assoc(NamespacePathAllowList::load(), NamespacePathAllowList::measure()) as $path => $declared) {
            $stale[] = \sprintf('%s is allowed to declare %s, and no longer does', $path, $declared);
        }

        self::assertSame([], $stale, \sprintf(
            "%d row(s) in %s describe a violation that is not there any more.\n"
            . "Delete them: a row that describes nothing hides the next one that would:\n%s",
            \count($stale),
            NamespacePathAllowList::PATH,
            implode("\n", $stale),
        ));
    }

    /**
     * Proves the rule refuses at all, in both directions, on declarations it is
     * handed: a scan that read no file would find no violation either, and stay
     * green for the wrong reason.
     */
    #[Test]
    public function itRefusesEachWayOnTheDeclarationsItIsGiven(): void
    {
        $roots = ['Acme\Tests' => 'probe', 'Acme\Tests\Deep' => 'probe/deep'];

        self::assertSame('Acme\Tests', NamespacePathAllowList::expectedNamespace($roots, 'probe/OneTest.php'));
        self::assertSame('Acme\Tests\Unit\Sub', NamespacePathAllowList::expectedNamespace($roots, 'probe/Unit/Sub/TwoTest.php'));
        // The longest root wins, the way the autoloader resolves a nested one.
        self::assertSame('Acme\Tests\Deep\Unit', NamespacePathAllowList::expectedNamespace($roots, 'probe/deep/Unit/ThreeTest.php'));
        self::assertNull(NamespacePathAllowList::expectedNamespace($roots, 'elsewhere/FourTest.php'));

        self::assertSame(
            [
                'probe/Unit/GlobalTest.php' => '(global namespace)',
                'probe/Unit/TwiceTest.php' => 'Acme\Tests\Unit + Acme\Other',
                'probe/Unit/WrongTest.php' => 'Acme\Tests\Wrong',
            ],
            NamespacePathAllowList::violationsIn($roots, [
                'probe/Unit/RightTest.php' => ['Acme\Tests\Unit'],
                'probe/Unit/WrongTest.php' => ['Acme\Tests\Wrong'],
                'probe/Unit/GlobalTest.php' => [],
                'probe/Unit/TwiceTest.php' => ['Acme\Tests\Unit', 'Acme\Other'],
            ]),
        );
    }

    /**
     * Names what was judged and what was allowed, in the two directions they
     * move in. The judged corpus is a floor: a file may not leave the scan
     * unnoticed. The allow-list is held under the ceiling the list itself
     * carries, which the derive command may only ever lower — so the list can
     * shrink, and cannot grow without someone editing that number by hand.
     */
    #[Test]
    public function itJudgesEveryTestFileInTheTree(): void
    {
        $judged = TestTree::testFiles();
        $allowed = NamespacePathAllowList::load();

        self::assertGreaterThan(500, \count($judged));
        self::assertLessThanOrEqual(NamespacePathAllowList::ceiling(), \count($allowed));
        self::assertSame([], array_values(array_diff(array_keys($allowed), $judged)));
    }
}
