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
 * nullability, a free map, a nested block, the empty string, the form of a
 * list's elements, and a closed set of words — which is what the factories
 * below enumerate.
 *
 * A closed set is {@see self::oneOf()} or {@see self::oneOfIgnoringCase()} and
 * only those — the two differ in whether the reader folds letter case, not in
 * what kind of question they answer. Two mechanisms that look like a closed
 * set are deliberately left with their readers rather than spelled as a
 * shape — a set whose members exist only at run time, and a value constrained
 * by a pattern instead of by membership. {@see self::oneOf()} says why.
 *
 * A shape is derived from what the reading code does with the value, not from
 * what the key is called: the cast's target (`(int)` → {@see self::integer()},
 * `(float)` → {@see self::number()}, `(bool)` → {@see self::boolean()}) and the
 * guard's predicate (`is_string()` → {@see self::text()}, `array_is_list()` →
 * {@see self::listOf()}) are the authority.
 */
final readonly class RuleOptionShape
{
    use RuleOptionShapeCompoundForms;

    private const string LIST_OF = 'list';
    private const string MAP_OF = 'map';
    private const string EITHER = 'either';
    private const string ONE_OF = 'one-of';

    /**
     * @param list<self> $alternatives non-empty only for {@see self::EITHER}
     */
    private function __construct(
        private RuleOptionValueForm|string $kind,
        private ?self $element = null,
        private array $alternatives = [],
        private bool $nullable = false,
        private RuleOptionWordSet $words = new RuleOptionWordSet([]),
    ) {}

    public static function boolean(): self
    {
        return new self(RuleOptionValueForm::Boolean);
    }

    /**
     * A whole number, 0 or more: the form of every threshold and count read
     * through an `(int)` cast. The floor is part of the form — see
     * {@see RuleOptionValueForm} for why a negative boundary is refused.
     */
    public static function integer(): self
    {
        return new self(RuleOptionValueForm::WholeNumber);
    }

    /**
     * An integer or a fraction, 0 or more: the form of every threshold read
     * through a `(float)` cast.
     */
    public static function number(): self
    {
        return new self(RuleOptionValueForm::Number);
    }

    /**
     * An integer or a fraction of either sign — for a boundary on a value the
     * user defines, such as a computed metric's formula, where nothing makes
     * a negative value impossible. Not for a boundary on a built-in
     * measurement: there {@see self::number()}'s floor is what refuses an
     * inverted rule.
     */
    public static function signedNumber(): self
    {
        return new self(RuleOptionValueForm::SignedNumber);
    }

    /** A string, the empty one included — the reading code accepts it. */
    public static function text(): self
    {
        return new self(RuleOptionValueForm::Text);
    }

    /** A string the reading code rejects when it is empty or blank. */
    public static function nonEmptyText(): self
    {
        return new self(RuleOptionValueForm::NonEmptyText);
    }

    /** A sequential list, every element of the given form. */
    public static function listOf(self $element): self
    {
        return new self(self::LIST_OF, $element);
    }

    /** A map the user names the keys of, every value of the given form. */
    public static function mapOf(self $value): self
    {
        return new self(self::MAP_OF, $value);
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
        return new self(RuleOptionValueForm::Block);
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

        return new self(self::EITHER, null, array_values($alternatives));
    }

    /**
     * One word out of a closed, statically known set — and the refusal prints
     * the set.
     *
     * Distinct from {@see self::text()} on purpose. `text()` says "the reading
     * code accepts any string", and a key whose reader then looks the value up
     * in an enum was declared loosely: the refusal arrived, but from the
     * reader, in the reader's own words, while the declaration went on
     * claiming every string was welcome.
     *
     * This and {@see self::oneOfIgnoringCase()} are the ONLY closed-set
     * mechanisms the vocabulary carries, and the boundary is deliberate — two
     * neighbouring mechanisms are NOT this shape and must not be spelled as it:
     *
     * - a set whose members are known only at run time (the output format is
     *   whatever the formatter registry holds) has no words to write here, and
     *   restating them would be a second copy that drifts;
     * - a value constrained by a PATTERN rather than by membership (a memory
     *   limit is digits with an optional K/M/G suffix) is not a word set at
     *   all, and "one of ..." could not print it.
     *
     * Matching is case-SENSITIVE: a declaration must not accept a spelling its
     * reader will not, or the value falls back to a default without a word.
     * Where a reader folds case before its own lookup, declare
     * {@see self::oneOfIgnoringCase()} instead.
     *
     * `coupling.cbo`'s `scope` declares one of these — its reader compares
     * strictly through a plain `in_array()`. `computed_metrics.<name>.levels`
     * declares neither factory: it deliberately separates "a real level word
     * that does not report" from "not a level at all", and one set of words
     * would flatten those two refusals into one.
     *
     * @throws LogicException when the set is empty or carries a blank word
     */
    public static function oneOf(string ...$words): self
    {
        return new self(self::ONE_OF, words: RuleOptionWordSet::of(...$words));
    }

    /**
     * The same closed-set shape, for a reader that folds letter case before
     * its own lookup rather than comparing the value as written.
     *
     * Declaring {@see self::oneOf()} in front of such a reader would be
     * NARROWER than it: the reader answers `Warning`, and a case-sensitive
     * declaration would refuse it at the seam before the reader ever gets the
     * chance. Three readers inside `rules:` fold case on purpose, pinned by
     * tests, and declare this factory: `annotation.directive`'s
     * `unused-directive-severity`, `architecture.unassigned-class`'s `mode`,
     * and `architecture.layer-violation`'s `severity`.
     *
     * @throws LogicException when the set is empty or carries a blank word
     */
    public static function oneOfIgnoringCase(string ...$words): self
    {
        return new self(self::ONE_OF, words: RuleOptionWordSet::foldingCase(...$words));
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
        return new self($this->kind, $this->element, $this->alternatives, true, $this->words);
    }

    public function matches(mixed $value): bool
    {
        if ($value === null) {
            return $this->nullable;
        }

        return match ($this->kind) {
            self::LIST_OF, self::MAP_OF => $this->matchesContainer($value),
            self::EITHER => $this->anyAlternativeMatches($value),
            self::ONE_OF => $this->words->contains($value),
            default => $this->plainForm()->accepts($value),
        };
    }

    /**
     * The word set this shape was declared with, for a closed-set shape; null
     * for every other kind.
     *
     * Exists so that a guard proving a declaration and its reader still agree
     * can read the declared words and their fold from the declaration itself,
     * rather than keeping a second hand-written copy beside it.
     */
    public function wordsDeclared(): ?RuleOptionWordSet
    {
        return $this->kind === self::ONE_OF ? $this->words : null;
    }

    /** The expected form, as the refusal names it. */
    public function describe(): string
    {
        $described = match ($this->kind) {
            self::LIST_OF => 'a list of ' . $this->describeElements(),
            self::MAP_OF => 'a map of ' . $this->describeElements(),
            self::EITHER => implode(' or ', array_map(static fn(self $shape): string => $shape->describe(), $this->alternatives)),
            self::ONE_OF => $this->words->describe(),
            default => $this->plainForm()->describe(),
        };

        return $this->nullable ? $described . ' or null' : $described;
    }

    /**
     * The written value, as THIS shape's refusal names it.
     *
     * A closed set asks a membership question, and the answer is the word that
     * was not in the set: "got a string" is true and tells the author nothing,
     * where `got "warnin"` is the whole of what went wrong. Every other shape
     * asks about form, and there the form is the answer, so
     * {@see RuleOptionValueForm::describeWritten()} stays the authority and
     * the two halves of the sentence still cannot agree by accident.
     */
    public function describeWritten(mixed $written): string
    {
        return $this->words->describeWritten($written)
            ?? $this->describeOutOfRange($written)
            ?? RuleOptionValueForm::describeWritten($written);
    }

    /** @throws LogicException when asked of a container, which has no plain form */
    private function plainForm(): RuleOptionValueForm
    {
        return $this->kind instanceof RuleOptionValueForm
            ? $this->kind
            : throw new LogicException('A container shape has no plain form of its own.');
    }

}
