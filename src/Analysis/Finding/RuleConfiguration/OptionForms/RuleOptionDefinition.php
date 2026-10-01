<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionValueForm;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionWordSet;

/** The immutable facts behind one declared option value. */
final readonly class RuleOptionDefinition
{
    /** @param ?Closure(ResolvedValueInterface, list<string>): void $layerJudge */
    public function __construct(
        public ?RuleOptionValueForm $plain = null,
        public ?CompoundRuleOptionForm $compound = null,
        public ?RuleOptionWordSet $words = null,
        public bool $nullable = false,
        public int|float|null $minimum = null,
        public ?Closure $layerJudge = null,
    ) {}

    public function orNull(): self
    {
        return new self($this->plain, $this->compound, $this->words, true, $this->minimum, $this->layerJudge);
    }

    public function atLeast(int|float $minimum): self
    {
        return new self($this->plain, $this->compound, $this->words, $this->nullable, $minimum, $this->layerJudge);
    }

    /** @param Closure(ResolvedValueInterface, list<string>): void $judge */
    public function judgedInEachLayer(Closure $judge): self
    {
        return new self($this->plain, $this->compound, $this->words, $this->nullable, $this->minimum, $judge);
    }

    public function matches(mixed $value): bool
    {
        if ($value === null) {
            return $this->nullable;
        }
        if ($this->minimum !== null && (\is_int($value) || \is_float($value)) && $value < $this->minimum) {
            return false;
        }
        return match (true) {
            $this->plain !== null => $this->plain->accepts($value),
            $this->words !== null => $this->words->contains($value),
            $this->compound !== null => $this->compound->matches($value),
            default => throw new LogicException('Unknown rule option shape.'),
        };
    }
}
