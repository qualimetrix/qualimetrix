<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol\TraitComposition;

use Closure;

use Qualimetrix\Core\Symbol\ClassNameSpelling;

use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitDefinitionFacts;

use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitMethodSource;

use Qualimetrix\Core\Symbol\TraitComposition\Contract\TraitUseFacts;

/**
 * @internal
 *
 * @phpstan-type TraitReadResult array{methods: array<string, TraitMethodSource>, complete: bool}
 */
final class TraitCompositionScope
{
    /** @var Closure(string): (TraitDefinitionFacts|null) */
    private readonly Closure $traitFacts;

    /** @var array<string, string> */
    private array $active = [];

    /** @var array<string, true> */
    private array $cyclic = [];

    /** @var array<string, string> */
    private array $unread = [];

    /** @var array<string, TraitReadResult> */
    private array $read = [];

    /** @param callable(string): (TraitDefinitionFacts|null) $traitFacts */
    public function __construct(callable $traitFacts)
    {
        $this->traitFacts = Closure::fromCallable($traitFacts);
    }

    /**
     * @param list<string> $ownMethods
     * @param list<TraitUseFacts> $uses
     *
     * @return array<string, TraitMethodSource>
     */
    public function composeMethods(array $ownMethods, array $uses): array
    {
        return $this->composeUses($ownMethods, $uses)['methods'];
    }

    /** @return list<string> */
    public function unread(): array
    {
        return array_values($this->unread);
    }

    /**
     * @param list<string> $ownMethods
     * @param list<TraitUseFacts> $uses
     *
     * @return TraitReadResult
     */
    private function composeUses(array $ownMethods, array $uses): array
    {
        $methods = [];
        $incomplete = [];
        foreach ($uses as $use) {
            foreach ($use->traits as $trait) {
                $identity = ClassNameSpelling::fold($trait);
                $facts = $this->readTrait($trait);
                $methods[$identity] = $facts['methods'];
                if (!$facts['complete']) {
                    $incomplete[$identity] = true;
                }
            }
        }

        $pool = new TraitMethodPool($methods, $incomplete);

        return ['methods' => $pool->compose($ownMethods, $uses), 'complete' => $incomplete === []];
    }

    /** @return TraitReadResult */
    private function readTrait(string $trait): array
    {
        $identity = ClassNameSpelling::fold($trait);
        if (isset($this->active[$identity])) {
            $this->recordCycle($identity);

            return ['methods' => [], 'complete' => false];
        }
        if (isset($this->read[$identity])) {
            return $this->read[$identity];
        }
        $definition = ($this->traitFacts)($trait);
        if ($definition === null) {
            $this->unread[$identity] = $trait;

            return $this->read[$identity] = ['methods' => [], 'complete' => false];
        }

        $this->active[$identity] = $trait;
        try {
            $result = $this->composeUses(array_keys($definition->ownMethods), $definition->uses);
            foreach ($definition->ownMethods as $method => $source) {
                $result['methods'][ClassNameSpelling::fold($method)] = $source;
            }
        } finally {
            unset($this->active[$identity]);
        }
        if (isset($this->cyclic[$identity])) {
            $result = ['methods' => [], 'complete' => false];
        }

        return $this->read[$identity] = $result;
    }

    private function recordCycle(string $identity): void
    {
        $insideCycle = false;
        foreach ($this->active as $activeIdentity => $trait) {
            $insideCycle = $insideCycle || $activeIdentity === $identity;
            if ($insideCycle) {
                $this->cyclic[$activeIdentity] = true;
                $this->unread[$activeIdentity] = $trait;
            }
        }
    }
}
