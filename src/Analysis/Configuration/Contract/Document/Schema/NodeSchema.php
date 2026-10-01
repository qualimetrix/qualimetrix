<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;

/**
 * What one node of the configuration document may hold and how the layers
 * that wrote it combine.
 *
 * Map keys are declared in one canonical spelling — lowercase words joined by
 * `_` or `-` — and an author may write any of the three spellings
 * {@see ConfigKeySpelling::acceptedSpellings()} gives; any other spelling of
 * the same words is refused with the canonical one offered.
 *
 * An empty collection reads by the node's declaration, because a format may
 * not tell `{}` from `[]` (YAML parsed into PHP does not): on a map it is a
 * written empty map and changes nothing, on a replaced list it is an empty
 * list that replaces, on an accumulated set it adds nothing.
 */
final readonly class NodeSchema
{
    /** @param Closure(ResolvedValueInterface, list<string>): void|IntegerJudgement|null $layerJudge */
    private function __construct(
        public MergePolicy $policy,
        public NodeScalarFacts $scalar,
        public NodeMapFacts $map,
        public ?NodeCollectionFacts $collection,
        public NodeWording $wording,
        public Closure|IntegerJudgement|null $layerJudge,
    ) {}

    public static function scalar(ScalarForm ...$forms): self
    {
        return new self(MergePolicy::LastWriterWins, new NodeScalarFacts(array_values($forms)), new NodeMapFacts(KeyDictionary::none()), null, new NodeWording(), null);
    }

    /** @param array<string, self> $fields canonical key => schema */
    public static function map(array $fields, Shorthand ...$shorthands): self
    {
        return new self(MergePolicy::DeepMerge, new NodeScalarFacts(), new NodeMapFacts(KeyDictionary::of($fields, array_values($shorthands))), null, new NodeWording(), null);
    }

    public static function list(self $element): self
    {
        return new self(MergePolicy::Replace, new NodeScalarFacts(), new NodeMapFacts(KeyDictionary::none()), new NodeCollectionFacts($element), new NodeWording(), null);
    }

    public static function set(self $element): self
    {
        return new self(MergePolicy::Accumulate, new NodeScalarFacts(), new NodeMapFacts(KeyDictionary::none()), new NodeCollectionFacts($element), new NodeWording(), null);
    }

    public static function namedMap(self $entry, ?NameVocabulary $names = null): self
    {
        return new self(MergePolicy::ByName, new NodeScalarFacts(), new NodeMapFacts(KeyDictionary::none(), names: $names, entry: $entry), null, new NodeWording(), null);
    }

    /** @param Closure(string): ?self $entryForName */
    public static function namedMapOf(Closure $entryForName, NameVocabulary $names): self
    {
        return new self(MergePolicy::ByName, new NodeScalarFacts(), new NodeMapFacts(KeyDictionary::none(), names: $names, namedEntrySchema: $entryForName), null, new NodeWording(), null);
    }

    public static function opaque(): self
    {
        return new self(MergePolicy::PerLayer, new NodeScalarFacts(), new NodeMapFacts(KeyDictionary::none()), null, new NodeWording(), null);
    }

    public function announcingEmptyOverride(string $notice): self
    {
        if ($this->policy !== MergePolicy::Replace) {
            throw new LogicException('Only a replaced list can announce an empty override.');
        }
        return $this->recompose(wording: $this->wording->with(emptyOverrideNotice: $notice));
    }

    public function withHint(string $hint): self
    {
        return $this->recompose(wording: $this->wording->with(hint: $hint));
    }

    /** @param Closure(ResolvedValueInterface, list<string>): void|IntegerJudgement $judge */
    public function judgedInEachLayer(Closure|IntegerJudgement $judge): self
    {
        if ($judge instanceof IntegerJudgement && ($this->policy !== MergePolicy::LastWriterWins || $this->scalar->forms !== [ScalarForm::Integer])) {
            throw new LogicException('An integer judgement requires a last-writer-wins integer scalar.');
        }
        return $this->recompose(layerJudge: $judge);
    }

    public function bareFor(string $field): self
    {
        if ($this->policy !== MergePolicy::DeepMerge) {
            throw new LogicException('A bare map value requires a declared boolean field.');
        }
        return $this->recompose(map: $this->map->withBareField($field));
    }

    public function admittingBareElement(): self
    {
        if ($this->policy !== MergePolicy::Replace && $this->policy !== MergePolicy::Accumulate) {
            throw new LogicException('Only a list can admit a bare element.');
        }
        $collection = $this->collection ?? throw new LogicException('A list needs an element schema.');
        return $this->recompose(collection: new NodeCollectionFacts($collection->element, BareElementPolicy::SingleAllowed));
    }

    /** @param array<string, string> $sentenceByRetiredKey */
    public function retiring(array $sentenceByRetiredKey): self
    {
        if ($this->policy !== MergePolicy::DeepMerge) {
            throw new LogicException('Only a map can retire keys.');
        }
        return $this->recompose(map: $this->map->withRetiredKeys($sentenceByRetiredKey));
    }

    public function atLeast(int|float $minimum): self
    {
        if ($this->policy !== MergePolicy::LastWriterWins) {
            throw new LogicException('A numeric floor requires a numeric scalar.');
        }
        return $this->recompose(scalar: $this->scalar->atLeast($minimum));
    }

    public function words(SchemaWordSet $words): self
    {
        if ($this->policy !== MergePolicy::LastWriterWins) {
            throw new LogicException('A word vocabulary requires a string scalar and at least one word.');
        }
        return $this->recompose(scalar: $this->scalar->words($words));
    }

    public function nonEmpty(): self
    {
        if ($this->policy !== MergePolicy::LastWriterWins) {
            throw new LogicException('Non-empty text requires a string scalar.');
        }
        return $this->recompose(scalar: $this->scalar->nonEmpty());
    }

    public function describe(): string
    {
        return match ($this->policy) {
            MergePolicy::LastWriterWins => $this->scalar->describe(),
            MergePolicy::DeepMerge => $this->map->describe(),
            MergePolicy::Replace, MergePolicy::Accumulate => $this->collection?->describe() ?? 'a list',
            MergePolicy::ByName => 'a map of named entries',
            MergePolicy::PerLayer => 'a value carried per layer',
        };
    }

    private function recompose(
        ?NodeScalarFacts $scalar = null,
        ?NodeMapFacts $map = null,
        ?NodeCollectionFacts $collection = null,
        ?NodeWording $wording = null,
        Closure|IntegerJudgement|null $layerJudge = null,
    ): self {
        return new self($this->policy, $scalar ?? $this->scalar, $map ?? $this->map, $collection ?? $this->collection, $wording ?? $this->wording, $layerJudge ?? $this->layerJudge);
    }
}
