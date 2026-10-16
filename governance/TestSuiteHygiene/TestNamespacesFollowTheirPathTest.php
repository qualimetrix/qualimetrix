<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\TestSuiteHygiene;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every test class must declare the namespace implied by its PSR-4 dev root
 * and directory. Fixture files are analyser input and are outside this scope.
 */
final class TestNamespacesFollowTheirPathTest extends TestCase
{
    #[Test]
    public function itFindsNoTestFileWhoseNamespaceDisagreesWithItsPath(): void
    {
        $declarations = [];
        foreach (TestTree::testFiles() as $path) {
            $declarations[$path] = TestTree::declarationsIn($path)['namespaces'];
        }

        $mismatches = self::mismatchesIn(TestTree::autoloadDevRoots(), $declarations);
        self::assertSame([], $mismatches, "Test namespaces must match their PSR-4 paths:\n" . implode("\n", $mismatches));
    }

    #[Test]
    public function itRefusesEachWayOnTheDeclarationsItIsGiven(): void
    {
        $roots = ['Acme\Tests' => 'probe', 'Acme\Tests\Deep' => 'probe/deep'];

        self::assertSame('Acme\Tests', self::expectedNamespace($roots, 'probe/OneTest.php'));
        self::assertSame('Acme\Tests\Unit\Sub', self::expectedNamespace($roots, 'probe/Unit/Sub/TwoTest.php'));
        self::assertSame('Acme\Tests\Deep\Unit', self::expectedNamespace($roots, 'probe/deep/Unit/ThreeTest.php'));
        self::assertNull(self::expectedNamespace($roots, 'elsewhere/FourTest.php'));

        self::assertSame([
            'probe/Unit/GlobalTest.php declares (global namespace), expected Acme\Tests\Unit',
            'probe/Unit/TwiceTest.php declares Acme\Tests\Unit + Acme\Other, expected Acme\Tests\Unit',
            'probe/Unit/WrongTest.php declares Acme\Tests\Wrong, expected Acme\Tests\Unit',
        ], self::mismatchesIn($roots, [
            'probe/Unit/RightTest.php' => ['Acme\Tests\Unit'],
            'probe/Unit/WrongTest.php' => ['Acme\Tests\Wrong'],
            'probe/Unit/GlobalTest.php' => [],
            'probe/Unit/TwiceTest.php' => ['Acme\Tests\Unit', 'Acme\Other'],
        ]));
    }

    #[Test]
    public function itJudgesEveryTestFileInTheTree(): void
    {
        self::assertGreaterThan(500, \count(TestTree::testFiles()));
    }

    /**
     * @param array<string, string> $roots PSR-4 prefix => project-relative directory
     * @param array<string, list<string>> $declarations path => declared namespaces
     *
     * @return list<string>
     */
    private static function mismatchesIn(array $roots, array $declarations): array
    {
        $mismatches = [];
        foreach ($declarations as $path => $namespaces) {
            $expected = self::expectedNamespace($roots, $path);
            if ($expected === null) {
                throw new LogicException($path . ' lies under no PSR-4 dev root');
            }

            $declared = match (\count($namespaces)) {
                0 => '(global namespace)',
                1 => $namespaces[0],
                default => implode(' + ', $namespaces),
            };
            if ($declared !== $expected) {
                $mismatches[] = \sprintf('%s declares %s, expected %s', $path, $declared, $expected);
            }
        }

        sort($mismatches);

        return $mismatches;
    }

    /** @param array<string, string> $roots PSR-4 prefix => project-relative directory */
    private static function expectedNamespace(array $roots, string $path): ?string
    {
        $bestRoot = null;
        $bestPrefix = null;
        foreach ($roots as $prefix => $root) {
            if (str_starts_with($path, $root . '/') && ($bestRoot === null || \strlen($root) > \strlen($bestRoot))) {
                $bestRoot = $root;
                $bestPrefix = $prefix;
            }
        }

        if ($bestRoot === null || $bestPrefix === null) {
            return null;
        }

        $directory = \dirname(substr($path, \strlen($bestRoot) + 1));

        return $directory === '.' ? $bestPrefix : $bestPrefix . '\\' . str_replace('/', '\\', $directory);
    }
}
