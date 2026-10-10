<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Core\Symbol\TraitComposition;
use Qualimetrix\Core\Symbol\TraitCompositionResult;
use Qualimetrix\Core\Symbol\TraitDefinitionFacts;

/** Composes exposed RFC members and their original body and property sources. */
final class RfcEffectiveMembers
{
    /** @var array<string, array<string, array{source: RfcDeclarationFacts, member: string}>> */
    private array $effective = [];

    /** @var array<string, bool> */
    private array $unreadTraits = [];

    public function __construct(private readonly RfcDeclarationIndex $index) {}

    /** @return array<string, array{source: RfcDeclarationFacts, member: string}> */
    public function methods(RfcDeclarationFacts $facts): array
    {
        $key = $facts->declaration->toCanonical();
        if (isset($this->effective[$key])) {
            return $this->effective[$key];
        }
        $methods = [];
        foreach (array_keys($facts->methods) as $member) {
            $methods[$member] = ['source' => $facts, 'member' => $member];
        }
        $composition = $this->compose($facts);
        $this->unreadTraits[$key] = $composition->unread !== [];
        foreach ($composition->methods as $exposed => $source) {
            $trait = $this->index->unique($source->declaringTrait);
            if ($trait !== null) {
                $methods[$exposed] = ['source' => $trait, 'member' => strtolower($source->originalMethod)];
            }
        }

        foreach ($this->hooks($facts, []) as $member => $target) {
            $methods['hook:' . $member] = $target;
        }

        return $this->effective[$key] = $methods;
    }

    private function compose(RfcDeclarationFacts $facts): TraitCompositionResult
    {
        return TraitComposition::compose(array_keys($facts->methods), $facts->uses, function (string $name): ?TraitDefinitionFacts {
            $trait = $this->index->unique($name);

            return $trait?->kind === 'Stmt_Trait' ? $trait->traitFacts() : null;
        });
    }

    /** @param array<string, true> $active
     * @return array<string, array{source: RfcDeclarationFacts, member: string}>
     */
    private function hooks(RfcDeclarationFacts $facts, array $active): array
    {
        $key = $facts->declaration->toCanonical();
        if (isset($active[$key])) {
            return [];
        }
        $active[$key] = true;
        $hooks = [];
        foreach (array_keys($facts->hooks) as $member) {
            $hooks[$member] = ['source' => $facts, 'member' => $member];
        }
        foreach ($this->propertySources($facts, $active) as $property => $sources) {
            if (\count($sources) !== 1) {
                continue;
            }
            $source = reset($sources);
            foreach (array_keys($source->hooks) as $member) {
                if (explode('::', $member, 2)[0] === $property) {
                    $hooks[$member] = ['source' => $source, 'member' => $member];
                }
            }
        }

        return $hooks;
    }

    /** @param array<string, true> $active
     * @return array<string, array<string, RfcDeclarationFacts>>
     */
    public function propertySources(RfcDeclarationFacts $facts, array $active): array
    {
        $sources = [];
        foreach ($facts->uses as $use) {
            foreach ($use->traits as $name) {
                $trait = $this->index->unique($name);
                if ($trait?->kind !== 'Stmt_Trait') {
                    continue;
                }
                $key = $trait->declaration->toCanonical();
                if (isset($active[$key])) {
                    continue;
                }
                $this->includeProperties($sources, $this->traitProperties($trait, $active), $facts);
            }
        }

        return $sources;
    }

    /** @param array<string, array<string, RfcDeclarationFacts>> $sources
     * @param array<string, array<string, RfcDeclarationFacts>> $nested
     */
    private function includeProperties(array &$sources, array $nested, RfcDeclarationFacts $facts): void
    {
        foreach ($nested as $property => $candidates) {
            if (!isset($facts->propertyNames[$property])) {
                $sources[$property] = ($sources[$property] ?? []) + $candidates;
            }
        }
    }

    /** @param array<string, true> $active
     * @return array<string, array<string, RfcDeclarationFacts>>
     */
    private function traitProperties(RfcDeclarationFacts $trait, array $active): array
    {
        $key = $trait->declaration->toCanonical();
        $nested = $this->propertySources($trait, [...$active, $key => true]);
        foreach (array_keys($trait->propertyNames) as $property) {
            $nested[$property] = [$key => $trait];
        }

        return $nested;
    }

    public function hasUnreadTrait(RfcDeclarationFacts $facts): bool
    {
        $this->methods($facts);

        return $this->unreadTraits[$facts->declaration->toCanonical()];
    }
}
