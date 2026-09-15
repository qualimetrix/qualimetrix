<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

/**
 * One located rule option: the depth it is written at, and the canonical
 * spelling of its key there.
 *
 * A pair rather than a string because the two halves answer different
 * questions and a caller needs both separately — `class.max-warning` says
 * "inside the class slot" and "the key max-warning", and a consumer printing
 * the first without the second, or comparing the second across depths, would
 * be wrong in a way a single string hides.
 */
final readonly class RuleOptionAddress
{
    /**
     * @param string|null $level the slot name, canonical spelling; null for the rule's own depth
     * @param string $key canonical kebab spelling, as the declaration writes it
     */
    public function __construct(
        public ?string $level,
        public string $key,
    ) {}

    /**
     * The address as a user writes it into `--rule-opt` or a `rules:` document:
     * `level.key` inside a slot, the bare key at the rule's own depth.
     *
     * Here rather than in each reader, because the dot is the grammar the
     * address is made of — a caller that joins the two halves itself has to
     * know that grammar to do it, and two callers knowing it is how they come
     * to disagree about it.
     */
    public function written(): string
    {
        return $this->level === null ? $this->key : $this->level . '.' . $this->key;
    }
}
