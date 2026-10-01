<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

use LogicException;

/** Constraints that belong to a scalar node's value. */
final readonly class NodeScalarFacts
{
    /** @param list<ScalarForm> $forms */
    public function __construct(
        public array $forms = [],
        public int|float|null $minimum = null,
        public ?SchemaWordSet $words = null,
        public TextRequirement $textRequirement = TextRequirement::Any,
    ) {}

    public function atLeast(int|float $minimum): self
    {
        if (!\in_array(ScalarForm::Integer, $this->forms, true) && !\in_array(ScalarForm::Number, $this->forms, true)) {
            throw new LogicException('A numeric floor requires a numeric scalar.');
        }
        return new self($this->forms, $minimum, $this->words, $this->textRequirement);
    }

    public function words(SchemaWordSet $words): self
    {
        if (!\in_array(ScalarForm::String, $this->forms, true)) {
            throw new LogicException('A word vocabulary requires a string scalar and at least one word.');
        }
        return new self($this->forms, $this->minimum, $words, $this->textRequirement);
    }

    public function nonEmpty(): self
    {
        if (!\in_array(ScalarForm::String, $this->forms, true)) {
            throw new LogicException('Non-empty text requires a string scalar.');
        }
        return new self($this->forms, $this->minimum, $this->words, TextRequirement::NonBlank);
    }

    public function describe(): string
    {
        $form = $this->forms === [] ? 'a scalar' : implode(' or ', array_map(static fn(ScalarForm $form): string => $form->value, $this->forms));
        if ($this->minimum !== null) {
            $form .= \sprintf(' at least %s', $this->minimum);
        }
        if ($this->textRequirement === TextRequirement::NonBlank) {
            $form = 'non-empty ' . $form;
        }
        if ($this->words !== null) {
            $form .= \sprintf(' (one of %s%s)', implode(', ', $this->words->words), $this->words->comparison === WordComparison::Folding ? ', case-insensitive' : '');
        }

        return $form;
    }
}
