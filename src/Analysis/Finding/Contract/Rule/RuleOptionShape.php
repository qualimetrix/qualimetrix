<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;

/** The immutable declaration of a rule option value. */
final readonly class RuleOptionShape
{
    public const string PLAIN = 'plain';
    public const string WORDS = 'words';
    public const string LIST = 'list';
    public const string MAP = 'map';
    public const string UNION = 'union';

    /**
     * @param list<self> $alternatives
     * @param ?Closure(ResolvedValueInterface, list<string>): void $layerJudge
     */
    private function __construct(
        public string $kind,
        public ?RuleOptionValueForm $plain = null,
        public ?RuleOptionWordSet $words = null,
        public ?self $element = null,
        public array $alternatives = [],
        public bool $nullable = false,
        public int|float|null $minimum = null,
        public ?Closure $layerJudge = null,
    ) {}

    public static function boolean(): self
    {
        return new self(self::PLAIN, plain: RuleOptionValueForm::Boolean);
    }

    public static function integer(): self
    {
        return new self(self::PLAIN, plain: RuleOptionValueForm::WholeNumber);
    }

    public static function number(): self
    {
        return new self(self::PLAIN, plain: RuleOptionValueForm::Number);
    }

    public static function signedNumber(): self
    {
        return new self(self::PLAIN, plain: RuleOptionValueForm::SignedNumber);
    }

    public static function text(): self
    {
        return new self(self::PLAIN, plain: RuleOptionValueForm::Text);
    }

    public static function nonEmptyText(): self
    {
        return new self(self::PLAIN, plain: RuleOptionValueForm::NonEmptyText);
    }

    public static function listOf(self $element): self
    {
        return new self(self::LIST, element: $element);
    }

    public static function mapOf(self $value): self
    {
        return new self(self::MAP, element: $value);
    }

    public static function block(): self
    {
        return new self(self::PLAIN, plain: RuleOptionValueForm::Block);
    }

    public static function either(self ...$alternatives): self
    {
        if (\count($alternatives) < 2) {
            throw new LogicException('A union of forms needs at least two alternatives.');
        }

        return new self(self::UNION, alternatives: array_values($alternatives));
    }

    public static function words(RuleOptionWordSet $words): self
    {
        if ($words->words === []) {
            throw new LogicException('A closed set of words needs at least one word.');
        }
        foreach ($words->words as $word) {
            if (trim($word) === '') {
                throw new LogicException('A closed set of words cannot carry a blank word.');
            }
        }

        return new self(self::WORDS, words: $words);
    }

    public function orNull(): self
    {
        return new self($this->kind, $this->plain, $this->words, $this->element, $this->alternatives, true, $this->minimum, $this->layerJudge);
    }

    public function atLeast(int|float $minimum): self
    {
        if (!\in_array($this->plain, [RuleOptionValueForm::WholeNumber, RuleOptionValueForm::Number, RuleOptionValueForm::SignedNumber], true)) {
            throw new LogicException('A numeric floor requires a numeric rule option form.');
        }

        return new self($this->kind, $this->plain, $this->words, $this->element, $this->alternatives, $this->nullable, $minimum, $this->layerJudge);
    }

    /** @param Closure(ResolvedValueInterface, list<string>): void $judge */
    public function judgedInEachLayer(Closure $judge): self
    {
        return new self($this->kind, $this->plain, $this->words, $this->element, $this->alternatives, $this->nullable, $this->minimum, $judge);
    }
}
