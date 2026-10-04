<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Normalization;

use Qualimetrix\Analysis\Evidence\Duplication\DuplicationDetector;

/**
 * Marks tokens that lie inside a constant declaration or a property's
 * array-literal initializer as "data" — as opposed to executable code.
 *
 * Rationale: {@see DuplicationDetector} operates on a token stream, not an
 * AST (see the class docblock there for why — streaming memory profile).
 * Distinguishing "data" from "code" purely from tokens therefore has to be a
 * forward-scanning pattern match rather than a full parse. Two patterns are
 * recognized:
 *
 * 1. `const NAME = <value>;` (optionally `public|protected|private const`,
 *    at class/interface/enum or namespace level) — always data, no matter
 *    the value shape, since `const` cannot legally appear inside a method
 *    body.
 * 2. `<visibility> [static] [readonly] [<type>] $prop = [...];` or
 *    `= array(...);` — a property declared with an array-literal default.
 *    A bare `static $x = [...];` (no visibility keyword) is deliberately
 *    NOT matched: syntactically identical to a function-local static
 *    variable, which is normal executable-state, not a data table. Missing
 *    that rarer property form is a false negative (still gets flagged),
 *    never a false positive (never wrongly suppressed) — the safe side to
 *    err on.
 *
 * Both patterns terminate the forward scan by tracking a *local* bracket
 * depth (reset to 0 at the start of each candidate statement) and requiring
 * the statement to end in `;` at that depth. For the property pattern, this
 * also rules out constructor-promoted parameters with array defaults (e.g.
 * `public function __construct(private array $x = ['a'])`): the token
 * right after the array literal closes is `,` or `)` there, never `;`, so
 * the match is rejected and nothing is tagged.
 *
 * A single trailing modifier keyword right before a matched property (e.g.
 * `static` in `static private array $x = [...];`, an unusual but legal
 * order) is picked up too, by walking back over any contiguous run of
 * modifier keywords immediately preceding the trigger token.
 *
 * Multi-property statements sharing one type/modifier prefix
 * (`private array $a = [1], $b = [2];`) are not matched at all: the token
 * right after the first array literal's closing bracket is `,`, not `;`,
 * so {@see matchPropertyArrayDeclaration()} rejects the whole statement —
 * a known, accepted gap (false negative, not a false positive).
 */
final class DataDeclarationTagger
{
    /**
     * Sentinel token type {@see TokenNormalizer} uses to mark the exact
     * position of a `?>` PHP-close-tag boundary, which otherwise leaves no
     * trace in the token stream (`TokenNormalizer` discards the real
     * `T_CLOSE_TAG`/`T_OPEN_TAG` tokens and retains inline HTML as a digest).
     * Without a marker, a forward scan started before the
     * boundary (e.g. {@see findStatementEnd()} for an unterminated `const`)
     * would run straight through it and mis-tag unrelated code in the next
     * PHP block as data — a false negative for real duplication there (the
     * worse failure direction, silently missed copy-paste).
     *
     * Never a real PHP token type: all real multi-char token constants are
     * positive ints, and single-character tokens are represented as `0` in
     * {@see TokenNormalizer} (see `TokenNormalizer::normalize()`), so `-1`
     * cannot collide. `TokenNormalizer` strips every barrier token from the
     * stream again right after tagging — callers of `normalize()` never see
     * one.
     */
    public const int PHP_CLOSE_TAG_BARRIER = -1;

    /**
     * @var list<int>
     */
    private const array MODIFIER_TYPES = [
        \T_PUBLIC,
        \T_PROTECTED,
        \T_PRIVATE,
        \T_STATIC,
        \T_READONLY,
        \T_VAR,
        \T_ABSTRACT,
        \T_FINAL,
    ];

    /**
     * Token types that can start a property declaration statement — the
     * entry point for {@see matchPropertyArrayDeclaration()}:
     *
     * - Plain visibility keywords (`public`, `protected`, `private`).
     * - `T_VAR` — legacy `var array $x = [...];` syntax. Unambiguous:
     *   `var` cannot legally appear anywhere except a property
     *   declaration, so extending the trigger to it is a safe, no-cost
     *   change (it was already in {@see MODIFIER_TYPES} for the backward
     *   modifier-walk, just never reachable as an entry point).
     * - `T_PUBLIC_SET`/`T_PROTECTED_SET`/`T_PRIVATE_SET` — PHP 8.4
     *   asymmetric visibility (`public(set)`, ...). PHP tokenizes each of
     *   these as one single token distinct from the plain visibility ones
     *   (confirmed via `token_get_all()`), so without adding them here they
     *   never matched at all. Adding them costs nothing extra downstream:
     *   {@see matchPropertyArrayDeclaration()}'s existing terminator check
     *   (`;` vs `,`/`)`) already rejects a constructor-promoted parameter
     *   written as `private(set) array $x = [...]` the same way it rejects
     *   the plain-visibility form (verified via `token_get_all()`).
     *
     * PHP 8.4 property hooks (`public array $x = [...] { get => ...; }`)
     * are deliberately NOT specially handled and remain a documented gap
     * alongside the multi-property and bare-static-local-variable gaps
     * above: the hook's `{` immediately after the array literal's closing
     * bracket already fails the "must be followed by `;`" terminator
     * check, so a hooked property with an array default is safely left
     * untagged (false negative) rather than risking a false positive from
     * a half-matched getter/setter body.
     *
     * @var list<int>
     */
    private const array PROPERTY_DECLARATION_TRIGGER_TYPES = [
        \T_PUBLIC,
        \T_PROTECTED,
        \T_PRIVATE,
        \T_VAR,
        \T_PUBLIC_SET,
        \T_PROTECTED_SET,
        \T_PRIVATE_SET,
    ];

    /**
     * @var list<int>
     */
    private const array TYPE_HINT_TYPES = [
        \T_STRING,
        \T_NS_SEPARATOR,
        \T_ARRAY,
        \T_NAME_QUALIFIED,
        \T_NAME_FULLY_QUALIFIED,
        \T_NAME_RELATIVE,
    ];

    /**
     * @var list<string>
     */
    private const array TYPE_HINT_VALUES = ['?', '|', '&'];

    /**
     * @param list<int> $types Transient raw types, including PHP-close barriers
     * @param list<string> $values
     */
    public function tag(array $types, array $values): string
    {
        $count = \count($values);
        $isData = str_repeat('0', $count);
        $i = 0;

        while ($i < $count) {
            if ($types[$i] === \T_CONST) {
                $end = $this->findStatementEnd($types, $values, $i);
                if ($end !== null) {
                    $this->markRange($isData, $this->modifierRunStart($types, $i), $end);
                    $i = $end + 1;

                    continue;
                }
            } elseif (\in_array($types[$i], self::PROPERTY_DECLARATION_TRIGGER_TYPES, true)) {
                $end = $this->matchPropertyArrayDeclaration($types, $values, $i);
                if ($end !== null) {
                    $this->markRange($isData, $this->modifierRunStart($types, $i), $end);
                    $i = $end + 1;

                    continue;
                }
            }

            $i++;
        }

        return $isData;
    }

    private function markRange(string &$isData, int $start, int $end): void
    {
        for ($k = $start; $k <= $end; $k++) {
            $isData[$k] = '1';
        }
    }

    /**
     * @param list<int> $types
     * @param list<string> $values
     */
    private function matchPropertyArrayDeclaration(array $types, array $values, int $i): ?int
    {
        $count = \count($values);
        $j = $this->advancePastAssignment($types, $values, $i + 1);
        if ($j >= $count) {
            return null;
        }

        $arrayStart = $j;
        if ($values[$j] !== '[') {
            if ($types[$j] !== \T_ARRAY) {
                return null;
            }
            $j++;
            if ($j >= $count || $values[$j] !== '(') {
                return null;
            }
        }

        $closeIdx = $this->findMatchingClose($types, $values, $arrayStart);
        if ($closeIdx === null) {
            return null;
        }

        $afterIdx = $closeIdx + 1;
        if ($afterIdx >= $count) {
            return null;
        }

        // Promoted defaults and multi-property declarations have a different terminator.
        if ($values[$afterIdx] !== ';' && $types[$afterIdx] !== self::PHP_CLOSE_TAG_BARRIER) {
            return null;
        }

        return $afterIdx;
    }

    /**
     * @param list<int> $types
     * @param list<string> $values
     */
    private function advancePastAssignment(array $types, array $values, int $j): int
    {
        $count = \count($values);

        while ($j < $count && \in_array($types[$j], self::MODIFIER_TYPES, true)) {
            $j++;
        }

        while ($j < $count && $this->isTypeHintToken($types[$j], $values[$j])) {
            $j++;
        }

        if ($j >= $count || $types[$j] !== \T_VARIABLE) {
            return $count;
        }
        $j++;

        if ($j >= $count || $values[$j] !== '=') {
            return $count;
        }

        return $j + 1;
    }

    private function isTypeHintToken(int $type, string $value): bool
    {
        return \in_array($value, self::TYPE_HINT_VALUES, true)
            || \in_array($type, self::TYPE_HINT_TYPES, true);
    }

    /**
     * @param list<int> $types
     */
    private function modifierRunStart(array $types, int $i): int
    {
        $start = $i;
        while ($start > 0 && \in_array($types[$start - 1], self::MODIFIER_TYPES, true)) {
            $start--;
        }

        return $start;
    }

    /**
     * A closing PHP block is an implicit terminator only at local depth zero.
     * A variable cannot belong to a legal constant expression.
     *
     * @param list<int> $types
     * @param list<string> $values
     */
    private function findStatementEnd(array $types, array $values, int $startIdx): ?int
    {
        $count = \count($values);
        $depth = 0;

        for ($k = $startIdx; $k < $count; $k++) {
            if ($types[$k] === self::PHP_CLOSE_TAG_BARRIER) {
                return $depth === 0 ? $k : null;
            } elseif ($this->isOpenToken($types[$k], $values[$k])) {
                $depth++;
            } elseif ($this->isCloseToken($values[$k])) {
                $depth = max(0, $depth - 1);
            } elseif ($types[$k] === \T_VARIABLE) {
                return null;
            } elseif ($values[$k] === ';' && $depth === 0) {
                return $k;
            }
        }

        return null;
    }

    /**
     * A literal cannot continue across a closing PHP block.
     *
     * @param list<int> $types
     * @param list<string> $values
     */
    private function findMatchingClose(array $types, array $values, int $openIdx): ?int
    {
        $count = \count($values);
        $depth = 0;

        for ($k = $openIdx; $k < $count; $k++) {
            if ($types[$k] === self::PHP_CLOSE_TAG_BARRIER) {
                return null;
            } elseif ($this->isOpenToken($types[$k], $values[$k])) {
                $depth++;
            } elseif ($this->isCloseToken($values[$k])) {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }

        return null;
    }

    private function isOpenToken(int $type, string $value): bool
    {
        return \in_array($value, ['[', '(', '{'], true)
            || $type === \T_CURLY_OPEN
            || $type === \T_DOLLAR_OPEN_CURLY_BRACES;
    }

    private function isCloseToken(string $value): bool
    {
        return \in_array($value, [']', ')', '}'], true);
    }
}
