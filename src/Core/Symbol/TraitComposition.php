<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

final class TraitComposition
{
    /**
     * @param list<string> $ownMethods
     * @param list<TraitUseFacts> $uses
     * @param callable(string): (TraitDefinitionFacts|null) $traitFacts
     */
    public static function compose(array $ownMethods, array $uses, callable $traitFacts): TraitCompositionResult
    {
        $scope = new TraitCompositionScope($traitFacts);

        return new TraitCompositionResult($scope->composeMethods($ownMethods, $uses), $scope->unread());
    }
}
