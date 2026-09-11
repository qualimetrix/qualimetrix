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
 */
enum RuleOptionValueForm
{
    case Boolean;
    case WholeNumber;
    case Number;
    case Text;
    case NonEmptyText;
    case Block;

    public function accepts(mixed $value): bool
    {
        return match ($this) {
            self::Boolean => \is_bool($value),
            self::WholeNumber => \is_int($value),
            self::Number => \is_int($value) || \is_float($value),
            self::Text => \is_string($value),
            self::NonEmptyText => \is_string($value) && trim($value) !== '',
            self::Block => \is_array($value),
        };
    }

    /** One value of this form, as the refusal names it. */
    public function describe(): string
    {
        return match ($this) {
            self::Boolean => 'a boolean',
            self::WholeNumber => 'a whole number',
            self::Number => 'a number',
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
            self::WholeNumber => 'whole numbers',
            self::Number => 'numbers',
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
