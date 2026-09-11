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
    private const string BOOLEAN = 'boolean';
    private const string INTEGER = 'integer';
    private const string NUMBER = 'number';
    private const string TEXT = 'text';
    private const string NON_EMPTY_TEXT = 'non-empty-text';
    private const string LIST_OF = 'list';
    private const string MAP_OF = 'map';
    private const string BLOCK = 'block';
    private const string EITHER = 'either';

    /**
     * @param list<self> $alternatives non-empty only for {@see self::EITHER}
     */
    private function __construct(
        private string $kind,
        private ?self $element,
        private array $alternatives,
        private bool $nullable,
    ) {}

    public static function boolean(): self
    {
        return new self(self::BOOLEAN, null, [], false);
    }

    /** A whole number: the form of every threshold read through an `(int)` cast. */
    public static function integer(): self
    {
        return new self(self::INTEGER, null, [], false);
    }

    /** An integer or a fraction: the form of every threshold read through a `(float)` cast. */
    public static function number(): self
    {
        return new self(self::NUMBER, null, [], false);
    }

    /** A string, the empty one included — the reading code accepts it. */
    public static function text(): self
    {
        return new self(self::TEXT, null, [], false);
    }

    /** A string the reading code rejects when it is empty or blank. */
    public static function nonEmptyText(): self
    {
        return new self(self::NON_EMPTY_TEXT, null, [], false);
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
        return new self(self::BLOCK, null, [], false);
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
     * The same form, with an explicit `null` accepted — the YAML `~` that every
     * reading site here treats as "the key was not written".
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
            self::BOOLEAN => \is_bool($value),
            self::INTEGER => \is_int($value),
            self::NUMBER => \is_int($value) || \is_float($value),
            self::TEXT => \is_string($value),
            self::NON_EMPTY_TEXT => \is_string($value) && trim($value) !== '',
            self::LIST_OF => \is_array($value) && array_is_list($value) && $this->everyElementMatches($value),
            self::MAP_OF => \is_array($value) && !array_is_list($value) && $this->everyElementMatches($value),
            self::BLOCK => \is_array($value),
            default => $this->anyAlternativeMatches($value),
        };
    }

    /** The expected form, as the refusal names it. */
    public function describe(): string
    {
        $described = match ($this->kind) {
            self::BOOLEAN => 'a boolean',
            self::INTEGER => 'a whole number',
            self::NUMBER => 'a number',
            self::TEXT => 'a string',
            self::NON_EMPTY_TEXT => 'a non-empty string',
            self::LIST_OF => 'a list of ' . $this->describeElement(),
            self::MAP_OF => 'a map of ' . $this->describeElement(),
            self::BLOCK => 'a block of options',
            default => $this->describeAlternatives(),
        };

        return $this->nullable ? $described . ' or null' : $described;
    }

    /**
     * The form that was written, as the refusal names it. A value is described
     * by what it is, never by what it was expected to be, so that the two
     * halves of the sentence cannot agree by accident.
     */
    public static function describeWritten(mixed $value): string
    {
        if (\is_array($value)) {
            return array_is_list($value) ? 'a list' : 'a map';
        }

        if (\is_string($value)) {
            return trim($value) === '' ? 'an empty string' : 'a string';
        }

        return match (true) {
            \is_bool($value) => 'a boolean',
            \is_int($value) => 'a whole number',
            \is_float($value) => 'a number',
            $value === null => 'null',
            default => get_debug_type($value),
        };
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

    private function describeElement(): string
    {
        return $this->element?->describe() ?? 'values';
    }

    private function describeAlternatives(): string
    {
        return implode(' or ', array_map(static fn(self $shape): string => $shape->describe(), $this->alternatives));
    }
}
