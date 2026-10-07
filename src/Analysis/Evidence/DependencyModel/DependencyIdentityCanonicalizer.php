<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ExternalClassSpellingInterface;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MixedSpelling;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Folds PHP class identities while preserving exact declaration paths. */
final readonly class DependencyIdentityCanonicalizer
{
    public function __construct(private ExternalClassSpellingInterface $externalClassSpelling) {}

    /**
     * @param list<Dependency> $dependencies
     * @param list<ClassLikeDeclaration> $declarations
     */
    public function canonicalize(array $dependencies, array $declarations): CanonicalGraphInput
    {
        $declaredSpellings = $this->declaredSpellings($declarations);
        [$canonicalByFold, $mixed] = $this->canonicalDeclarations($declaredSpellings);
        [$canonicalByFold, $mixed] = $this->canonicalExternalClasses(
            $dependencies,
            $declaredSpellings,
            $canonicalByFold,
            $mixed,
        );

        $canonicalDeclarations = array_map(
            static function (ClassLikeDeclaration $declaration) use ($canonicalByFold): ClassLikeDeclaration {
                $spelling = $declaration->logical->symbolPath->toString();
                $canonical = $canonicalByFold[ClassNameSpelling::fold($spelling)];

                return $spelling === $canonical
                    ? $declaration
                    : $declaration->withLogicalClass(new LogicalClassPath(SymbolPath::fromClassFqn($canonical)));
            },
            $declarations,
        );

        usort($mixed, static fn(MixedSpelling $left, MixedSpelling $right): int => [$left->kind, $left->canonical] <=> [$right->kind, $right->canonical]);

        return new CanonicalGraphInput(
            $this->canonicalDependencies($dependencies, $canonicalByFold),
            $canonicalDeclarations,
            $mixed,
        );
    }

    /**
     * @param list<ClassLikeDeclaration> $declarations
     *
     * @return array<string, array<string, true>>
     */
    private function declaredSpellings(array $declarations): array
    {
        $declaredSpellings = [];
        foreach ($declarations as $declaration) {
            $spelling = $declaration->logical->symbolPath->toString();
            $declaredSpellings[ClassNameSpelling::fold($spelling)][$spelling] = true;
        }

        return $declaredSpellings;
    }

    /**
     * @param array<string, array<string, true>> $declaredSpellings
     *
     * @return array{array<string, string>, list<MixedSpelling>}
     */
    private function canonicalDeclarations(array $declaredSpellings): array
    {
        $canonicalByFold = [];
        $mixed = [];
        foreach ($declaredSpellings as $folded => $spellings) {
            $names = array_keys($spellings);
            sort($names, \SORT_STRING);
            $canonicalByFold[$folded] = $names[0];
            if (\count($names) > 1) {
                $mixed[] = new MixedSpelling('class', $names, $names[0]);
            }
        }

        return [$canonicalByFold, $mixed];
    }

    /**
     * @param list<Dependency> $dependencies
     * @param array<string, array<string, true>> $declaredSpellings
     * @param array<string, string> $canonicalByFold
     * @param list<MixedSpelling> $mixed
     *
     * @return array{array<string, string>, list<MixedSpelling>}
     */
    private function canonicalExternalClasses(
        array $dependencies,
        array $declaredSpellings,
        array $canonicalByFold,
        array $mixed,
    ): array {
        $externalSpellings = [];
        foreach ($dependencies as $dependency) {
            foreach ([$dependency->sourceLogical(), $dependency->targetLogical()] as $endpoint) {
                $spelling = $endpoint->toString();
                $folded = ClassNameSpelling::fold($spelling);
                if (!isset($declaredSpellings[$folded])) {
                    $externalSpellings[$folded][$spelling] = true;
                }
            }
        }

        foreach ($externalSpellings as $folded => $spellings) {
            $names = array_keys($spellings);
            sort($names, \SORT_STRING);
            $canonical = $this->installedSpelling($names) ?? $names[0];
            $canonicalByFold[$folded] = $canonical;
            if (\count($names) > 1) {
                $mixed[] = new MixedSpelling('external', $names, $canonical);
            }
        }

        return [$canonicalByFold, $mixed];
    }

    /** @param list<string> $spellings */
    private function installedSpelling(array $spellings): ?string
    {
        foreach ($spellings as $spelling) {
            $declared = $this->externalClassSpelling->declaredSpelling($spelling);
            if ($declared !== null) {
                return $declared;
            }
        }

        return null;
    }

    /**
     * @param list<Dependency> $dependencies
     * @param array<string, string> $canonicalByFold
     *
     * @return list<Dependency>
     */
    private function canonicalDependencies(array $dependencies, array $canonicalByFold): array
    {
        $canonicalDependencies = [];
        foreach ($dependencies as $dependency) {
            $sourceSpelling = $dependency->sourceLogical()->toString();
            $targetSpelling = $dependency->targetLogical()->toString();
            $source = $canonicalByFold[ClassNameSpelling::fold($sourceSpelling)];
            $target = $canonicalByFold[ClassNameSpelling::fold($targetSpelling)];
            if ($source === $target) {
                continue;
            }
            $canonicalDependencies[] = $sourceSpelling === $source && $targetSpelling === $target
                ? $dependency
                : $dependency->withLogicalEndpoints(
                    new LogicalClassPath(SymbolPath::fromClassFqn($source)),
                    new LogicalClassPath(SymbolPath::fromClassFqn($target)),
                );
        }

        return $canonicalDependencies;
    }
}
