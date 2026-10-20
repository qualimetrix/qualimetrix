<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitDefinitionFacts;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitMethodSource;
use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitUseFacts;
use Qualimetrix\Core\Symbol\TraitComposition\TraitComposition;

/** Composes read trait bodies into one class's cohesion populations. */
final class CohesionTraitComposition
{
    public static function key(string $name): string
    {
        return ClassNameSpelling::fold($name);
    }

    /**
     * @param array<string, list<LcomClassData>> $traits
     *
     * @return array{LcomClassData, TccLccClassData, int}
     */
    public static function compose(LcomClassData $own, TccLccClassData $ownTcc, array $traits): array
    {
        [$effective, $unreadCount] = self::composeMethods($own, $traits);

        return [$effective, self::buildTccData($own, $ownTcc, $effective), $unreadCount];
    }

    /**
     * @param array<string, list<LcomClassData>> $traits
     *
     * @return array{LcomClassData, int}
     */
    private static function composeMethods(LcomClassData $own, array $traits): array
    {
        $effective = clone $own;
        $composition = TraitComposition::compose($own->declaration()->methodNames(), $own->declaration()->traitUses(), static function (string $name) use ($traits): ?TraitDefinitionFacts {
            $definitions = $traits[self::key($name)] ?? [];

            return \count($definitions) === 1 ? $definitions[0]->declaration()->traitDefinitionFacts() : null;
        });
        foreach ($composition->methods as $exposed => $source) {
            $definitions = $traits[self::key($source->declaringTrait)] ?? [];
            if (\count($definitions) === 1) {
                $effective->importMethod($exposed, $definitions[0], $source->originalMethod, $source->visibility);
            }
        }
        if (self::usedTraitHasInstanceProperties($own->declaration()->traitUses(), $traits, [])) {
            $effective->declaration()->incrementInstancePropertyCount();
        }

        return [$effective, \count($composition->unread)];
    }

    private static function buildTccData(LcomClassData $own, TccLccClassData $ownTcc, LcomClassData $effective): TccLccClassData
    {
        $tcc = new TccLccClassData($own->namespace, $own->className, $own->line, $own->startFilePos);
        $tcc->incrementPropertyCount($ownTcc->getPropertyCount() + ($effective->declaration()->instancePropertyCount() > $own->declaration()->instancePropertyCount() ? 1 : 0));
        foreach ($effective->declaration()->methods() as $source) {
            if (!self::tracksForTcc($effective, $source)) {
                continue;
            }
            $method = $source->originalMethod;
            $tcc->addMethod($method);
            foreach (array_unique([...$ownTcc->getPropertiesAccessedBy($method), ...CohesionMethodReachability::properties($effective, $method)]) as $property) {
                $tcc->addPropertyAccess($method, $property);
            }
        }

        return $tcc;
    }

    private static function tracksForTcc(LcomClassData $effective, TraitMethodSource $source): bool
    {
        $method = $source->originalMethod;

        return $source->visibility === 'public' && !$source->isAbstract && $effective->hasGraphMethod($method) && !$effective->isStaticMethod($method);
    }

    /**
     * @param list<TraitUseFacts> $uses
     * @param array<string, list<LcomClassData>> $traits
     * @param array<string, true> $visited
     */
    private static function usedTraitHasInstanceProperties(array $uses, array $traits, array $visited): bool
    {
        foreach ($uses as $use) {
            foreach ($use->traits as $name) {
                $key = self::key($name);
                if (isset($visited[$key])) {
                    continue;
                }
                $visited[$key] = true;
                $definitions = $traits[$key] ?? [];
                if (\count($definitions) !== 1) {
                    continue;
                }
                if ($definitions[0]->declaration()->instancePropertyCount() > 0 || self::usedTraitHasInstanceProperties($definitions[0]->declaration()->traitUses(), $traits, $visited)) {
                    return true;
                }
            }
        }

        return false;
    }
}
