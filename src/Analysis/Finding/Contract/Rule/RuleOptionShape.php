<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;

/**
 * The form a rule option's value may take, in the words the refusal prints.
 *
 * Declared beside the key in {@see RuleOptionKeySet} so that a wrongly shaped
 * value is answered by the declaration rather than by whichever cast happened
 * to reach it first. Before this existed the form was decided at the site that
 * consumed it — `(int)`, `(bool)`, `is_string()` — and a key whose value was
 * of no usable form was silently coerced, or refused only when its *name*
 * contained a substring from a list.
 *
 * There is deliberately no shape meaning "anything": a form that cannot be
 * named is not declared, and an undeclared key is refused as unknown. The
 * vocabulary therefore has to name every form the tree actually holds —
 * nullability, a free map, a nested block, the empty string, and the form of a
 * list's elements — which is what the factories below enumerate.
 *
 * A shape is derived from what the reading code does with the value, not from
 * what the key is called: the cast's target (`(int)` → {@see self::integer()},
 * `(float)` → {@see self::number()}, `(bool)` → {@see self::boolean()}) and the
 * guard's predicate (`is_string()` → {@see self::text()}, `array_is_list()` →
 * {@see self::listOf()}) are the authority.
 */
final readonly class RuleOptionShape
{
    private const string LIST_OF = 'list';
    private const string MAP_OF = 'map';
    private const string EITHER = 'either';

    /**
     * @param list<self> $alternatives non-empty only for {@see self::EITHER}
     */
    private function __construct(
        private RuleOptionValueForm|string $kind,
        private ?self $element,
        private array $alternatives,
        private bool $nullable,
    ) {}

    public static function boolean(): self
    {
        return self::plain(RuleOptionValueForm::Boolean);
    }

    /** A whole number: the form of every threshold read through an `(int)` cast. */
    public static function integer(): self
    {
        return self::plain(RuleOptionValueForm::WholeNumber);
    }

    /** An integer or a fraction: the form of every threshold read through a `(float)` cast. */
    public static function number(): self
    {
        return self::plain(RuleOptionValueForm::Number);
    }

    /** A string, the empty one included — the reading code accepts it. */
    public static function text(): self
    {
        return self::plain(RuleOptionValueForm::Text);
    }

    /** A string the reading code rejects when it is empty or blank. */
    public static function nonEmptyText(): self
    {
        return self::plain(RuleOptionValueForm::NonEmptyText);
    }

    /** A sequential list, every element of the given form. */
    public static function listOf(self $element): self
    {
        return new self(self::LIST_OF, $element, [], false);
    }

    /** A map the user names the keys of, every value of the given form. */
    public static function mapOf(self $value): self
    {
        return new self(self::MAP_OF, $value, [], false);
    }

    /**
     * A nested block of options whose own keys another declaration answers for
     * — a hierarchical rule's level slot, or a sub-document with its own key
     * recognition. This shape states that the value is a block and nothing
     * about what may stand inside it, so it does not restate a key set that is
     * declared elsewhere.
     */
    public static function block(): self
    {
        return self::plain(RuleOptionValueForm::Block);
    }

    /**
     * One of several named forms — the shape of a key the reading code branches
     * on, such as a pattern option taking either one string or a list of them.
     */
    public static function either(self ...$alternatives): self
    {
        if (\count($alternatives) < 2) {
            throw new LogicException('A union of forms needs at least two alternatives.');
        }

        return new self(self::EITHER, null, array_values($alternatives), false);
    }

    /**
     * The same form, with an explicit `null` accepted.
     *
     * What that means is decided by the site that reads the key, and outside
     * the `rules:` subtree the answer is uniform: normalization drops a `null`
     * entry before the reader sees it, so the key reads as unwritten and the
     * default applies. Inside `rules:` the entry survives normalization, and a
     * reader asking whether the key is *present* — which is how the threshold
     * mode and the flat form of a hierarchical rule are chosen — still sees it.
     * `null` is accepted there too, but it is not the same as silence.
     */
    public function orNull(): self
    {
        return new self($this->kind, $this->element, $this->alternatives, true);
    }

    public function matches(mixed $value): bool
    {
        if ($value === null) {
            return $this->nullable;
        }

        return match ($this->kind) {
            self::LIST_OF => \is_array($value) && array_is_list($value) && $this->everyElementMatches($value),
            self::MAP_OF => \is_array($value) && !array_is_list($value) && $this->everyElementMatches($value),
            self::EITHER => $this->anyAlternativeMatches($value),
            default => $this->plainForm()->accepts($value),
        };
    }

    /** The expected form, as the refusal names it. */
    public function describe(): string
    {
        $described = match ($this->kind) {
            self::LIST_OF => 'a list of ' . $this->describeElements(),
            self::MAP_OF => 'a map of ' . $this->describeElements(),
            self::EITHER => $this->describeAlternatives(),
            default => $this->plainForm()->describe(),
        };

        return $this->nullable ? $described . ' or null' : $described;
    }

    private static function plain(RuleOptionValueForm $form): self
    {
        return new self($form, null, [], false);
    }

    /** @throws LogicException when asked of a container, which has no plain form */
    private function plainForm(): RuleOptionValueForm
    {
        return $this->kind instanceof RuleOptionValueForm
            ? $this->kind
            : throw new LogicException('A container shape has no plain form of its own.');
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function everyElementMatches(array $value): bool
    {
        $element = $this->element;

        if ($element === null) {
            return true;
        }

        foreach ($value as $item) {
            if (!$element->matches($item)) {
                return false;
            }
        }

        return true;
    }

    private function anyAlternativeMatches(mixed $value): bool
    {
        foreach ($this->alternatives as $alternative) {
            if ($alternative->matches($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The element of a container, named in the plural the container reads in.
     * A container of containers keeps the singular: "a list of a map of
     * numbers" has no plural spelling that stays readable.
     */
    private function describeElements(): string
    {
        $element = $this->element;

        if ($element === null) {
            return 'values';
        }

        return $element->kind instanceof RuleOptionValueForm && !$element->nullable
            ? $element->kind->describeMany()
            : $element->describe();
    }

    private function describeAlternatives(): string
    {
        return implode(' or ', array_map(static fn(self $shape): string => $shape->describe(), $this->alternatives));
    }
}
