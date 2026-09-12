<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;

/**
 * The closed set of words a configuration value may be, and how a refusal
 * names it.
 *
 * Its own type rather than a list inside {@see RuleOptionShape} because the
 * three questions a word set answers — is this one of them, how are they
 * printed, and how is the word that was not one of them printed — travel
 * together and belong to nobody else. The shape holds one of these and asks it;
 * it does not reimplement membership beside its container and union logic.
 *
 * Whether matching folds letter case is a property of the individual set, not
 * a blanket policy of this class: {@see self::of()} builds a case-sensitive
 * set and {@see self::foldingCase()} builds one that lowers both sides before
 * comparing. The choice tracks the reader the set stands for, and getting it
 * backwards is a promise that disagrees with the code behind it either way —
 * a folding set in front of a strict reader accepts a spelling the reader
 * then drops without a word, and a strict set in front of a folding reader
 * refuses a spelling the reader would have honoured.
 *
 * Three word sets inside `rules:` fold case before their own lookup and are
 * declared with {@see self::foldingCase()}: `architecture.layer-violation`'s
 * `severity`, `architecture.unassigned-class`'s `mode`, and
 * `annotation.directive`'s `unused-directive-severity`. `coupling.cbo`'s
 * `scope` compares strictly through a plain `in_array()` and stays declared
 * with {@see self::of()}. `fail_on` also compares strictly, through
 * `Severity::tryFrom()`, but declares no word set here at all — its accepted
 * values are read off the enum, not written a second time in this class.
 */
final readonly class RuleOptionWordSet
{
    /**
     * The plain carrier. {@see self::of()} and {@see self::foldingCase()} are
     * the validating way in; the empty set is what every shape that names no
     * words carries, and it is spelled here rather than as a factory because
     * a promoted readonly property needs a default the caller can write
     * inline.
     *
     * @param list<string> $words
     */
    public function __construct(
        public array $words,
        private bool $foldsCase = false,
    ) {}

    /**
     * A case-sensitive set: the reader compares the written value against
     * these words exactly as spelled.
     *
     * @throws LogicException when the set is empty or carries a blank word
     */
    public static function of(string ...$words): self
    {
        return new self(self::validated(...$words));
    }

    /**
     * A case-folding set: the reader lowers the written value before its own
     * lookup, so a declaration that did not fold too would refuse a spelling
     * the reader accepts.
     *
     * @throws LogicException when the set is empty or carries a blank word
     */
    public static function foldingCase(string ...$words): self
    {
        return new self(self::validated(...$words), foldsCase: true);
    }

    /**
     * @throws LogicException when the set is empty or carries a blank word
     *
     * @return list<string>
     */
    private static function validated(string ...$words): array
    {
        if ($words === []) {
            throw new LogicException('A closed set of words needs at least one word.');
        }

        foreach ($words as $word) {
            if (trim($word) === '') {
                throw new LogicException('A closed set of words cannot carry a blank word.');
            }
        }

        return array_values($words);
    }

    /**
     * Whether this set folds letter case before comparing — the fact a guard
     * pairing a declaration with its reader needs, read off the declaration
     * rather than assumed.
     */
    public function foldsCase(): bool
    {
        return $this->foldsCase;
    }

    public function contains(mixed $value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        if ($this->foldsCase) {
            return \in_array(strtolower($value), array_map(strtolower(...), $this->words), true);
        }

        // Case-SENSITIVE, and deliberately so for a set built by {@see self::of()}.
        // A case-insensitive set accepts `APPLICATION` for a reader that compares
        // strictly, and the value then falls back to the reader's default without
        // a word -- which is the exact defect declaring the set was meant to
        // close. A set built by {@see self::foldingCase()} takes the branch above
        // instead, because there the reader is the one that folds.
        return \in_array($value, $this->words, true);
    }

    /**
     * The words themselves, quoted, in the order they were declared — the
     * whole point of a closed set is that the refusal names them rather than
     * saying "a string".
     */
    public function describe(): string
    {
        return 'one of ' . implode(', ', array_map(static fn(string $word): string => '"' . $word . '"', $this->words));
    }

    /**
     * The written value as a MEMBERSHIP question names it: the word that was
     * not in the set. "got a string" is true and tells the author nothing,
     * where `got "warnin"` is the whole of what went wrong.
     *
     * Answers `null` for anything that is not a word at all — an empty string,
     * a number, a list — because there the form is the answer and
     * {@see RuleOptionValueForm::describeWritten()} is the authority.
     */
    public function describeWritten(mixed $written): ?string
    {
        if ($this->words === [] || !\is_string($written) || trim($written) === '') {
            return null;
        }

        return '"' . $written . '"';
    }
}
