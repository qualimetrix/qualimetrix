<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

/**
 * The plain forms a configuration value takes, and the words a refusal names
 * them by.
 *
 * This is the whole vocabulary, used from both ends of one sentence: the form a
 * key was declared with ({@see describe()}) and the form the author actually
 * wrote ({@see describeWritten()}). Keeping the two halves in one place is what
 * stops them drifting into two dialects — a refusal saying `got array` where
 * its own expectation says `a map` describes the same document in two
 * languages.
 *
 * A composite form — a list, a map of a named value, a union of alternatives —
 * is not a case here: it has no words of its own until its element has some, so
 * {@see RuleOptionShape} composes it out of these.
 *
 * A number carries its range, not only its type. {@see self::WholeNumber} and
 * {@see self::Number} are never negative: every numeric option they declare is
 * a count or a boundary on a measurement that is never negative, and a negative
 * boundary does not tighten such a rule — it inverts it (`value >= -1` holds
 * for every symbol, `value < -1` for none). {@see self::SignedNumber} is the
 * one form without a floor, for a boundary on a user-written formula whose
 * value may be negative. The range lives on the case rather than beside it so
 * that every reader asking "is this value of the declared form" — the
 * recognition walk and the threshold-shorthand unfolding alike — gets the same
 * answer.
 */
enum RuleOptionValueForm
{
    case Boolean;
    case WholeNumber;
    case Number;
    case SignedNumber;
    case Text;
    case NonEmptyText;
    case Block;

    public function accepts(mixed $value): bool
    {
        return $this->hasTheType($value) && $this->isInRange($value);
    }

    /**
     * The written value itself, when it is of this form's type and outside
     * its range — `-1` rather than "a whole number", which is true of `-1`
     * and would contradict the expectation printed beside it. Null for every
     * value whose type is the answer.
     */
    public function describeOutOfRange(mixed $value): ?string
    {
        if (!$this->hasTheType($value) || $this->isInRange($value)) {
            return null;
        }

        return var_export($value, true);
    }

    private function hasTheType(mixed $value): bool
    {
        return match ($this) {
            self::Boolean => \is_bool($value),
            self::WholeNumber => \is_int($value),
            self::Number, self::SignedNumber => \in_array(get_debug_type($value), ['int', 'float'], true),
            self::Text => \is_string($value),
            self::NonEmptyText => \is_string($value) && trim($value) !== '',
            self::Block => \is_array($value),
        };
    }

    private function isInRange(mixed $value): bool
    {
        return match ($this) {
            self::WholeNumber, self::Number => $value >= 0,
            default => true,
        };
    }

    /** One value of this form, as the refusal names it. */
    public function describe(): string
    {
        return match ($this) {
            self::Boolean => 'a boolean',
            self::WholeNumber => 'a non-negative whole number',
            self::Number => 'a non-negative number',
            self::SignedNumber => 'a number',
            self::Text => 'a string',
            self::NonEmptyText => 'a non-empty string',
            self::Block => 'a block of options',
        };
    }

    /**
     * Many values of this form, for the container that holds them: "a list of
     * non-empty strings" rather than "a list of a non-empty string", which is
     * the article of a single value left standing after a plural.
     */
    public function describeMany(): string
    {
        return match ($this) {
            self::Boolean => 'booleans',
            self::WholeNumber => 'non-negative whole numbers',
            self::Number => 'non-negative numbers',
            self::SignedNumber => 'numbers',
            self::Text => 'strings',
            self::NonEmptyText => 'non-empty strings',
            self::Block => 'blocks of options',
        };
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
}
