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
    /**
     * @param list<ScalarForm> $scalarForms
     * @param Closure(ResolvedValueInterface, list<string>): void|IntegerJudgement|null $layerJudge
     * @param ?Closure(string): ?self $namedEntrySchema
     * @param array<string, string> $retiredKeys
     * @param list<string> $choices
     */
    private function __construct(
        public MergePolicy $policy,
        private array $scalarForms = [],
        private ?KeyDictionary $keys = null,
        private ?self $element = null,
        private ?NameVocabulary $names = null,
        private NodeWording $wording = new NodeWording(),
        private Closure|IntegerJudgement|null $layerJudge = null,
        private ?Closure $namedEntrySchema = null,
        private ?string $bareField = null,
        private bool $bareElement = false,
        private array $retiredKeys = [],
        private int|float|null $minimum = null,
        private array $choices = [],
        private bool $foldCase = false,
        private bool $nonEmpty = false,
    ) {}

    /** A scalar leaf written as one of `$forms`; no form accepts any scalar. */
    public static function scalar(ScalarForm ...$forms): self
    {
        return new self(MergePolicy::LastWriterWins, scalarForms: array_values($forms));
    }

    /**
     * A map with a fixed dictionary of keys, merged key by key.
     *
     * @param array<string, self> $fields canonical key => schema
     */
    public static function map(array $fields, Shorthand ...$shorthands): self
    {
        return new self(MergePolicy::DeepMerge, keys: KeyDictionary::of($fields, array_values($shorthands)));
    }

    /** A list whose last writer replaces it whole. */
    public static function list(self $element): self
    {
        return new self(MergePolicy::Replace, element: $element);
    }

    /** A replaced list of strings; any other element is refused with its index. */
    public static function stringList(): self
    {
        return self::list(self::scalar(ScalarForm::String));
    }

    /** A list every layer adds to; equal elements collapse into the first. */
    public static function set(self $element): self
    {
        return new self(MergePolicy::Accumulate, element: $element);
    }

    /**
     * A map keyed by names rather than schema keys, merged entry by entry.
     *
     * @param ?NameVocabulary $names null accepts any name
     */
    public static function namedMap(self $entry, ?NameVocabulary $names = null): self
    {
        return new self(MergePolicy::ByName, element: $entry, names: $names);
    }

    /** @param Closure(string): ?self $entryForName */
    public static function namedMapOf(Closure $entryForName, NameVocabulary $names): self
    {
        return new self(MergePolicy::ByName, names: $names, namedEntrySchema: $entryForName);
    }

    /** A subtree the engine carries per layer without reading it. */
    public static function opaque(): self
    {
        return new self(MergePolicy::PerLayer);
    }

    /**
     * On a replaced list: a diagnostic when a written empty list replaces a
     * non-empty one from a lower layer — `only_rules: []` lifting a preset's
     * filter is legal and still worth saying.
     */
    public function announcingEmptyOverride(string $notice): self
    {
        if ($this->policy !== MergePolicy::Replace) {
            throw new LogicException('Only a replaced list can announce an empty override.');
        }

        return $this->with(wording: $this->wording->with(emptyOverrideNotice: $notice));
    }

    /**
     * A sentence the engine adds when it refuses the form of a value written
     * here — what the author most likely meant, where the form alone does not
     * say it: an unquoted `2024` read as a number where a path is due.
     */
    public function withHint(string $hint): self
    {
        return $this->with(wording: $this->wording->with(hint: $hint));
    }

    /**
     * A form the owner judges on every layer's written value of this node, in
     * phase 1 before any merge — for what the engine carries unread below it —
     * so a lower layer's mistake is refused even where a higher layer replaces
     * the value. The judge receives the value as that one layer wrote it and
     * the node's canonical path, and refuses by throwing.
     *
     * @param Closure(ResolvedValueInterface, list<string>): void|IntegerJudgement $judge
     */
    public function judgedInEachLayer(Closure|IntegerJudgement $judge): self
    {
        if ($judge instanceof IntegerJudgement && ($this->policy !== MergePolicy::LastWriterWins || $this->scalarForms !== [ScalarForm::Integer])) {
            throw new LogicException('An integer judgement requires a last-writer-wins integer scalar.');
        }

        return $this->with(layerJudge: $judge);
    }

    /** A boolean scalar in place of this map writes its declared field. */
    public function bareFor(string $field): self
    {
        $target = $this->fields()[$field] ?? null;
        if ($this->policy !== MergePolicy::DeepMerge || $target?->scalarForms() !== [ScalarForm::Boolean]) {
            throw new LogicException('A bare map value requires a declared boolean field.');
        }

        return $this->with(bareField: $field);
    }

    /** A scalar at this list node writes a one-element list. */
    public function admittingBareElement(): self
    {
        if ($this->policy !== MergePolicy::Replace && $this->policy !== MergePolicy::Accumulate) {
            throw new LogicException('Only a list can admit a bare element.');
        }

        return $this->with(bareElement: true);
    }

    /** @param array<string, string> $sentenceByRetiredKey */
    public function retiring(array $sentenceByRetiredKey): self
    {
        if ($this->policy !== MergePolicy::DeepMerge) {
            throw new LogicException('Only a map can retire keys.');
        }
        foreach ($sentenceByRetiredKey as $key => $sentence) {
            KeyDictionary::assertCanonical($key);
            if (\in_array($key, $this->keys()->keys(), true)) {
                throw new LogicException(\sprintf('Retired key "%s" is still declared.', $key));
            }
            if ($sentence === '') {
                throw new LogicException('A retired key needs replacement wording.');
            }
        }

        return $this->with(retiredKeys: $sentenceByRetiredKey);
    }

    public function atLeast(int|float $minimum): self
    {
        if ($this->policy !== MergePolicy::LastWriterWins || (!\in_array(ScalarForm::Integer, $this->scalarForms, true) && !\in_array(ScalarForm::Number, $this->scalarForms, true))) {
            throw new LogicException('A numeric floor requires a numeric scalar.');
        }

        return $this->with(minimum: $minimum);
    }

    /** @param non-empty-list<string> $choices */
    public function oneOf(array $choices, bool $foldCase = false): self
    {
        if ($this->policy !== MergePolicy::LastWriterWins || !\in_array(ScalarForm::String, $this->scalarForms, true) || $choices === []) {
            throw new LogicException('A word vocabulary requires a string scalar and at least one word.');
        }

        return $this->with(choices: array_values($choices), foldCase: $foldCase);
    }

    public function nonEmpty(): self
    {
        if ($this->policy !== MergePolicy::LastWriterWins || !\in_array(ScalarForm::String, $this->scalarForms, true)) {
            throw new LogicException('Non-empty text requires a string scalar.');
        }

        return $this->with(nonEmpty: true);
    }

    public function describe(): string
    {
        return match ($this->policy) {
            MergePolicy::LastWriterWins => $this->describeScalar(),
            MergePolicy::DeepMerge => $this->bareField === null ? 'a map' : \sprintf('a map or a boolean for "%s"', $this->bareField),
            MergePolicy::Replace, MergePolicy::Accumulate => $this->bareElement ? 'a list or one element' : 'a list',
            MergePolicy::ByName => 'a map of named entries',
            MergePolicy::PerLayer => 'a value carried per layer',
        };
    }

    private function describeScalar(): string
    {
        $form = $this->scalarForms === [] ? 'a scalar' : implode(' or ', array_map(static fn(ScalarForm $form): string => $form->value, $this->scalarForms));
        if ($this->minimum !== null) {
            $form .= \sprintf(' at least %s', $this->minimum);
        }
        if ($this->nonEmpty) {
            $form = 'non-empty ' . $form;
        }
        if ($this->choices !== []) {
            $form .= \sprintf(' (one of %s%s)', implode(', ', $this->choices), $this->foldCase ? ', case-insensitive' : '');
        }

        return $form;
    }

    public function entryForName(string $name): ?self
    {
        return $this->namedEntrySchema === null ? $this->element : ($this->namedEntrySchema)($name);
    }

    public function bareField(): ?string
    {
        return $this->bareField;
    }
    public function admitsBareElement(): bool
    {
        return $this->bareElement;
    }
    /** @return array<string, string> */
    public function retiredKeys(): array
    {
        return $this->retiredKeys;
    }
    public function minimum(): int|float|null
    {
        return $this->minimum;
    }
    /** @return list<string> */
    public function choices(): array
    {
        return $this->choices;
    }
    public function foldsCase(): bool
    {
        return $this->foldCase;
    }
    public function requiresNonEmpty(): bool
    {
        return $this->nonEmpty;
    }

    /**
     * @param ?array<string, string> $retiredKeys
     * @param ?list<string> $choices
     */
    private function with(
        ?NodeWording $wording = null,
        Closure|IntegerJudgement|null $layerJudge = null,
        ?string $bareField = null,
        ?bool $bareElement = null,
        ?array $retiredKeys = null,
        int|float|null $minimum = null,
        ?array $choices = null,
        ?bool $foldCase = null,
        ?bool $nonEmpty = null,
    ): self {
        return new self(
            $this->policy,
            $this->scalarForms,
            $this->keys,
            $this->element,
            $this->names,
            $wording ?? $this->wording,
            $layerJudge ?? $this->layerJudge,
            $this->namedEntrySchema,
            $bareField ?? $this->bareField,
            $bareElement ?? $this->bareElement,
            $retiredKeys ?? $this->retiredKeys,
            $minimum ?? $this->minimum,
            $choices ?? $this->choices,
            $foldCase ?? $this->foldCase,
            $nonEmpty ?? $this->nonEmpty,
        );
    }

    /**
     * The owner's judgement of each layer's value; null when the node declares none.
     *
     * @return Closure(ResolvedValueInterface, list<string>): void|IntegerJudgement|null
     */
    public function layerJudge(): Closure|IntegerJudgement|null
    {
        return $this->layerJudge;
    }

    /** @return list<ScalarForm> */
    public function scalarForms(): array
    {
        return $this->scalarForms;
    }

    /** The keys of a map; none for any other node. */
    public function keys(): KeyDictionary
    {
        return $this->keys ?? KeyDictionary::none();
    }

    /** @return array<string, self> */
    public function fields(): array
    {
        return $this->keys()->fields();
    }

    /** @return list<Shorthand> */
    public function shorthands(): array
    {
        return $this->keys()->shorthands();
    }

    /** The element of a list or set, the entry of a named map. */
    public function element(): self
    {
        return $this->element ?? throw new LogicException(\sprintf('A %s node has no element schema.', $this->policy->value));
    }

    public function names(): ?NameVocabulary
    {
        return $this->names;
    }

    public function emptyOverrideNotice(): ?string
    {
        return $this->wording->emptyOverrideNotice;
    }

    public function hint(): ?string
    {
        return $this->wording->hint;
    }
}
