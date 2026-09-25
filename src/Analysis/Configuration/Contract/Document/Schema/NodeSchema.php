<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;

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
    /** @param list<ScalarForm> $scalarForms */
    private function __construct(
        public MergePolicy $policy,
        private array $scalarForms = [],
        private ?KeyDictionary $keys = null,
        private ?self $element = null,
        private ?NameVocabulary $names = null,
        private ?string $emptyOverrideNotice = null,
        private ?string $hint = null,
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

        return new self($this->policy, $this->scalarForms, $this->keys, $this->element, $this->names, $notice, $this->hint);
    }

    /**
     * A sentence the engine adds when it refuses the form of a value written
     * here — what the author most likely meant, where the form alone does not
     * say it: an unquoted `2024` read as a number where a path is due.
     */
    public function withHint(string $hint): self
    {
        return new self($this->policy, $this->scalarForms, $this->keys, $this->element, $this->names, $this->emptyOverrideNotice, $hint);
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
        return $this->emptyOverrideNotice;
    }

    public function hint(): ?string
    {
        return $this->hint;
    }
}
