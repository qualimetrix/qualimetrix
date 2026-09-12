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
 * Matching ignores letter case, and that is a choice about WHICH readers this
 * set is meant for. The four inside `rules:` — a layer-violation `severity`, an
 * unassigned-class `mode`, an unused-directive severity, a layer `match` — all
 * fold case before their own lookup, so a case-sensitive set would refuse
 * values they accept. Two readers outside that group do NOT fold (`fail_on`
 * through `Severity::tryFrom()`, and a CBO `scope` through a strict
 * `in_array()`); adopting this set for either of them means folding there
 * first, or it would accept a spelling the reader then drops.
 */
final readonly class RuleOptionWordSet
{
    /**
     * The plain carrier. {@see self::of()} is the validating way in; the empty
     * set is what every shape that names no words carries, and it is spelled
     * here rather than as a factory because a promoted readonly property needs
     * a default the caller can write inline.
     *
     * @param list<string> $words
     */
    public function __construct(public array $words) {}

    /**
     * @throws LogicException when the set is empty or carries a blank word
     */
    public static function of(string ...$words): self
    {
        if ($words === []) {
            throw new LogicException('A closed set of words needs at least one word.');
        }

        foreach ($words as $word) {
            if (trim($word) === '') {
                throw new LogicException('A closed set of words cannot carry a blank word.');
            }
        }

        return new self(array_values($words));
    }

    public function contains(mixed $value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        // Case-SENSITIVE, and deliberately so. A case-insensitive set accepts
        // `APPLICATION` for a reader that compares strictly, and the value then
        // falls back to the reader's default without a word -- which is the
        // exact defect declaring the set was meant to close. The declaration
        // must not be wider than the reader it stands for; where a reader does
        // fold case, the set it declares has to say so rather than be assumed.
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
