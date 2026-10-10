<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Design\Unit\Inheritance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\ExternalAncestry;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\InheritanceDepthResolver;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\InheritanceOutcome;
use Qualimetrix\Analysis\Evidence\Design\Inheritance\InheritanceResolution;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;
use Qualimetrix\Tests\Analysis\Evidence\Design\Support\FixedParentSource;

#[CoversClass(InheritanceDepthResolver::class)]
#[CoversClass(InheritanceResolution::class)]
final class InheritanceDepthResolverTest extends TestCase
{
    private static function throwableReach(?bool $truth): \Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach
    {
        return match ($truth) {
            true => \Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::Yes,
            false => \Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::No,
            null => \Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::Unknown,
        };
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function builtinCases(): iterable
    {
        foreach (['Exception' => 1, 'Error' => 1, 'LogicException' => 2, 'RuntimeException' => 2, 'ErrorException' => 2, 'ArgumentCountError' => 3] as $parent => $depth) {
            yield $parent => [$parent, $depth, true];
            yield $parent . ' folded' => [strtolower($parent), $depth, true];
        }
    }

    #[Test]
    #[DataProvider('builtinCases')]
    public function itIncludesEveryBuiltinAncestorWithoutComposer(string $parent, int $depth, bool $truth): void
    {
        [$resolver, $declarations] = $this->resolver([['App\Child', $parent]]);
        $answer = $resolver->depthOf($declarations[0]);
        self::assertSame($depth, $answer->depth);
        self::assertSame(InheritanceOutcome::Exact, $answer->outcome);
        self::assertSame(self::throwableReach($truth), $answer->reachesThrowable);
    }

    /** @return iterable<string, array{?string, ?string, ?int, InheritanceOutcome, ?bool}> */
    public static function duplicateCases(): iterable
    {
        $cases = [
            'root and Throwable' => [null, 'RuntimeException', 3, InheritanceOutcome::Exact, null],
            'root and unread' => [null, 'Vendor\Missing', 2, InheritanceOutcome::Floor, null],
            'all true' => ['Exception', 'RuntimeException', 3, InheritanceOutcome::Exact, true],
            'all false' => ['App\Root', null, 2, InheritanceOutcome::Exact, false],
            'true and false' => ['Exception', 'App\Root', 2, InheritanceOutcome::Exact, null],
            'true and unknown' => ['Exception', 'Vendor\Missing', 2, InheritanceOutcome::Floor, null],
            'false and unknown' => ['App\Root', 'Vendor\Missing', 2, InheritanceOutcome::Floor, null],
            'finite and cyclic' => [null, 'App\Dup', null, InheritanceOutcome::Loop, null],
        ];
        foreach ($cases as $name => [$first, $second, $depth, $outcome, $truth]) {
            yield $name . ' forward' => [$first, $second, $depth, $outcome, $truth];
            yield $name . ' reverse' => [$second, $first, $depth, $outcome, $truth];
        }
    }

    #[Test]
    #[DataProvider('duplicateCases')]
    public function itMergesEveryParentBranchIncludingDegreeZeroRoots(?string $first, ?string $second, ?int $depth, InheritanceOutcome $outcome, ?bool $truth): void
    {
        [$resolver, $declarations] = $this->resolver([
            ['App\Root', null], ['App\Dup', $first], ['App\Dup', $second], ['App\Child', 'App\Dup'],
        ]);
        $answer = $resolver->depthOf($declarations[3]);
        self::assertSame($depth, $answer->depth);
        self::assertSame($outcome, $answer->outcome);
        self::assertSame(self::throwableReach($truth), $answer->reachesThrowable);
        self::assertSame($answer, $resolver->depthOf($declarations[3]));
    }

    #[Test]
    public function itKeepsCompletedMemoSeparateFromCanonicalActivePaths(): void
    {
        foreach ([false, true] as $reverse) {
            [$resolver, $declarations] = $this->resolver([['App\A', 'app\b'], ['App\B', 'APP\A'], ['App\Below', 'App\A']]);
            foreach ($reverse ? array_reverse($declarations) : $declarations as $declaration) {
                $answer = $resolver->depthOf($declaration);
                self::assertNull($answer->depth);
                self::assertSame(InheritanceOutcome::Loop, $answer->outcome);
                self::assertSame(\Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::Unknown, $answer->reachesThrowable);
                self::assertSame($answer, $resolver->depthOf($declaration));
            }
        }
    }

    #[Test]
    public function itKeepsExactSameFileOrdinalAnswersDistinct(): void
    {
        [$resolver, $declarations] = $this->resolver([['App\Dup', null], ['App\Dup', 'RuntimeException'], ['App\Child', 'App\Dup']]);
        self::assertSame(0, $resolver->depthOf($declarations[0])->depth);
        self::assertSame(2, $resolver->depthOf($declarations[1])->depth);
        self::assertSame(\Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::No, $resolver->depthOf($declarations[0])->reachesThrowable);
        self::assertSame(\Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::Yes, $resolver->depthOf($declarations[1])->reachesThrowable);
        self::assertSame(\Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::Unknown, $resolver->depthOf($declarations[2])->reachesThrowable);
    }

    #[Test]
    public function itDoesNotMistakeProjectExceptionAndErrorNamesForBuiltins(): void
    {
        [$resolver, $declarations] = $this->resolver([['App\Exception', null], ['App\Error', 'App\Exception'], ['App\Child', 'App\Error']]);
        foreach ($declarations as $i => $declaration) {
            self::assertSame($i, $resolver->depthOf($declaration)->depth);
            self::assertSame(\Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::No, $resolver->depthOf($declaration)->reachesThrowable);
        }
    }

    #[Test]
    public function itKeepsProvenThrowableWhenALaterTailIsUnread(): void
    {
        [$resolver, $declarations] = $this->resolver([['App\Known', 'Vendor\Unread', 'Throwable'], ['App\Below', 'App\Known']]);
        foreach ($declarations as $i => $declaration) {
            $answer = $resolver->depthOf($declaration);
            self::assertSame($i + 1, $answer->depth);
            self::assertSame(InheritanceOutcome::Floor, $answer->outcome);
            self::assertSame(\Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::Yes, $answer->reachesThrowable);
        }
    }

    #[Test]
    public function itPropagatesAnExternalLoopAndReadsTheFullVendorBuiltinTail(): void
    {
        [$resolver, $declarations] = $this->resolver([['App\Loop', 'Vendor\A'], ['App\Below', 'App\Loop'], ['App\Failure', 'Vendor\Failure']], new FixedParentSource([
            'Vendor\A' => 'Vendor\B', 'Vendor\B' => 'vendor\a', 'Vendor\Failure' => 'ArgumentCountError',
        ]));
        self::assertNull($resolver->depthOf($declarations[0])->depth);
        self::assertNull($resolver->depthOf($declarations[1])->depth);
        self::assertSame(InheritanceOutcome::Loop, $resolver->depthOf($declarations[1])->outcome);
        self::assertSame(4, $resolver->depthOf($declarations[2])->depth);
        self::assertSame(\Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::Yes, $resolver->depthOf($declarations[2])->reachesThrowable);
    }

    #[Test]
    public function itMergesEveryAnalysedAlternativeAfterAnExternalBridge(): void
    {
        foreach ([[null, 'RuntimeException'], ['RuntimeException', null]] as $parents) {
            [$resolver, $declarations] = $this->resolver([
                ['App\A', $parents[0]], ['App\A', $parents[1]], ['App\C', 'Vendor\B'],
            ], new FixedParentSource(['Vendor\B' => 'App\A', 'App\A' => null]));
            $answer = $resolver->depthOf($declarations[2]);
            self::assertSame(4, $answer->depth);
            self::assertSame(InheritanceOutcome::Exact, $answer->outcome);
            self::assertSame(\Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::Unknown, $answer->reachesThrowable);
            foreach ($parents as $i => $parent) {
                self::assertSame($parent === null ? 0 : 2, $resolver->depthOf($declarations[$i])->depth);
                self::assertSame($parent !== null ? \Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::Yes : \Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::No, $resolver->depthOf($declarations[$i])->reachesThrowable);
            }
        }
    }

    #[Test]
    public function itPropagatesACycleAcrossAnExternalBridge(): void
    {
        foreach ([[null, 'App\C'], ['App\C', null]] as $parents) {
            [$resolver, $declarations] = $this->resolver([
                ['App\A', $parents[0]], ['App\A', $parents[1]], ['App\C', 'Vendor\B'], ['App\Below', 'App\C'],
            ], new FixedParentSource(['Vendor\B' => 'App\A', 'App\A' => null]));
            foreach ([2, 3] as $i) {
                $answer = $resolver->depthOf($declarations[$i]);
                self::assertSame(InheritanceOutcome::Loop, $answer->outcome);
                self::assertNull($answer->depth);
                self::assertSame(\Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::Unknown, $answer->reachesThrowable);
            }
            $root = $resolver->depthOf($declarations[array_search(null, $parents, true)]);
            self::assertSame(0, $root->depth);
            self::assertSame(\Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::No, $root->reachesThrowable);
        }
    }

    #[Test]
    public function itKeepsExternalBridgeAnswersIndependentOfMemoAndQueryOrder(): void
    {
        foreach ([[null, 'App\C'], ['App\C', null]] as $parents) {
            foreach ([[0, 1, 2], [1, 0, 2], [2, 1, 0]] as $order) {
                [$resolver, $declarations] = $this->resolver([
                    ['App\A', $parents[0]], ['App\A', $parents[1]], ['App\C', 'Vendor\B'],
                ], new FixedParentSource(['Vendor\B' => 'app\a', 'app\a' => null]));
                foreach ($order as $i) {
                    $resolver->depthOf($declarations[$i]);
                }
                $answer = $resolver->depthOf($declarations[2]);
                self::assertSame(InheritanceOutcome::Loop, $answer->outcome);
                self::assertNull($answer->depth);
                self::assertSame(\Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::Unknown, $answer->reachesThrowable);
                self::assertSame($answer, $resolver->depthOf($declarations[2]));
                self::assertSame(0, $resolver->depthOf($declarations[array_search(null, $parents, true)])->depth);
            }
        }
    }

    #[Test]
    public function itPreservesFloorsAndUnanimousClassifiersAcrossAnExternalBridge(): void
    {
        $cases = [
            [null, 'Vendor\Unread', 3, InheritanceOutcome::Floor, null],
            ['Exception', 'RuntimeException', 4, InheritanceOutcome::Exact, true],
            ['App\Root', null, 3, InheritanceOutcome::Exact, false],
        ];
        foreach ($cases as [$first, $second, $depth, $outcome, $truth]) {
            foreach ([[$first, $second], [$second, $first]] as $parents) {
                [$resolver, $declarations] = $this->resolver([
                    ['App\A', $parents[0]], ['App\A', $parents[1]], ['App\C', 'Vendor\B'], ['App\Root', null],
                ], new FixedParentSource(['Vendor\B' => 'App\A', 'App\A' => null]));
                $answer = $resolver->depthOf($declarations[2]);
                self::assertSame($depth, $answer->depth);
                self::assertSame($outcome, $answer->outcome);
                self::assertSame(self::throwableReach($truth), $answer->reachesThrowable);
            }
        }
    }

    #[Test]
    public function itKeepsProvenThrowableAcrossARejoinedIncompleteTail(): void
    {
        foreach (['Vendor\Unread' => InheritanceOutcome::Floor, 'App\C' => InheritanceOutcome::Loop] as $parent => $outcome) {
            [$resolver, $declarations] = $this->resolver([
                ['App\A', null], ['App\A', $parent], ['App\C', 'Vendor\B', 'Throwable'],
            ], new FixedParentSource(['Vendor\B' => 'App\A', 'App\A' => null]));
            $answer = $resolver->depthOf($declarations[2]);
            self::assertSame($outcome, $answer->outcome);
            self::assertSame($outcome === InheritanceOutcome::Loop ? null : 3, $answer->depth);
            self::assertSame(\Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::Yes, $answer->reachesThrowable);
        }
    }

    #[Test]
    public function itResolvesKnownGraphEvidenceAfterSixtyFourExternalVisits(): void
    {
        foreach ([63, 64, 65] as $length) {
            $parents = [];
            for ($i = 0; $i < $length; ++$i) {
                $parents['Vendor\C' . $i] = $i + 1 === $length ? 'App\A' : 'Vendor\C' . ($i + 1);
            }
            [$resolver, $declarations] = $this->resolver([
                ['App\A', null], ['App\A', 'RuntimeException'], ['App\Child', 'Vendor\C0'],
            ], new FixedParentSource($parents));
            $answer = $resolver->depthOf($declarations[2]);
            self::assertSame($length <= 64 ? $length + 3 : 65, $answer->depth);
            self::assertSame($length <= 64 ? InheritanceOutcome::Exact : InheritanceOutcome::Floor, $answer->outcome);
            self::assertSame(\Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::Unknown, $answer->reachesThrowable);
        }
    }

    #[Test]
    public function itKeepsBuiltinPrefixThrowableWhenTheGraphTailIsIncomplete(): void
    {
        foreach (['Vendor\Unread' => InheritanceOutcome::Floor, 'App\C' => InheritanceOutcome::Loop] as $parent => $outcome) {
            [$resolver, $declarations] = $this->resolver([
                ['Error', $parent], ['App\C', 'ArgumentCountError'],
            ]);
            $answer = $resolver->depthOf($declarations[1]);
            self::assertSame($outcome, $answer->outcome);
            self::assertSame($outcome === InheritanceOutcome::Loop ? null : 4, $answer->depth);
            self::assertSame(\Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::Yes, $answer->reachesThrowable);
        }
    }

    #[Test]
    public function itUsesOneActivePathAcrossRepeatedExternalGraphReturns(): void
    {
        foreach (['RuntimeException' => InheritanceOutcome::Exact, 'App\C' => InheritanceOutcome::Loop] as $parent => $outcome) {
            foreach ([[null, $parent], [$parent, null]] as $parents) {
                [$resolver, $declarations] = $this->resolver([
                    ['App\A', $parents[0]], ['App\A', $parents[1]], ['App\C', 'Vendor\B'], ['App\D', 'Vendor\E'],
                ], new FixedParentSource(['Vendor\B' => '\APP\d', 'Vendor\E' => 'app\a', 'APP\d' => null, 'app\a' => null]));
                $answer = $resolver->depthOf($declarations[2]);
                self::assertSame($outcome, $answer->outcome);
                self::assertSame($outcome === InheritanceOutcome::Loop ? null : 6, $answer->depth);
                self::assertSame(\Qualimetrix\Analysis\Evidence\Design\Inheritance\ThrowableReach::Unknown, $answer->reachesThrowable);
            }
        }
    }

    /**
     * @param list<array{string, ?string, string?: string}> $rows
     *
     * @return array{InheritanceDepthResolver, list<DeclarationPath>}
     */
    private function resolver(array $rows, ?FixedParentSource $parents = null): array
    {
        $facts = [];
        $dependencies = [];
        $declarations = [];
        foreach ($rows as $ordinal => $row) {
            [$name, $parent] = $row;
            $declaration = DeclarationPath::of(SymbolPath::fromClassFqn($name), RelativePath::fromString('classes.php'), DeclarationOrdinal::fromRank($ordinal));
            $declarations[] = $declaration;
            $facts[] = ClassLikeDeclaration::of($declaration, ClassType::Class_, false, false);
            foreach ([$parent, $row[2] ?? null] as $position => $target) {
                if ($target === null) {
                    continue;
                }
                $dependencies[] = Dependency::ofClassLike($declaration, new LogicalClassPath(SymbolPath::fromClassFqn($target)), $position === 0 ? DependencyType::Extends : DependencyType::Implements, new Location($declaration->file, $ordinal + 1), false, false);
            }
        }
        $graph = AdjacencyGraphBuilder::builder()->build($dependencies, $facts)->graph;

        return [InheritanceDepthResolver::fromGraph($graph, new ExternalAncestry($parents ?? FixedParentSource::unconfigured())), $declarations];
    }
}
