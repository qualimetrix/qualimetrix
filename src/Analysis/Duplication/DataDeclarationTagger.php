<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Duplication;

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
     * @var list<int>
     */
    private const array VISIBILITY_TYPES = [\T_PUBLIC, \T_PROTECTED, \T_PRIVATE];

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
     * @param list<NormalizedToken> $tokens
     *
     * @return list<NormalizedToken>
     */
    public function tag(array $tokens): array
    {
        $isData = $this->scanForDataDeclarations($tokens);

        if (!\in_array(true, $isData, true)) {
            return $tokens;
        }

        // Rebuild the token list, replacing each flagged index with a copy
        // carrying isData: true.
        $result = [];
        foreach ($tokens as $idx => $t) {
            $result[] = $isData[$idx] ? new NormalizedToken($t->type, $t->value, $t->line, true) : $t;
        }

        return $result;
    }

    /**
     * Walks the token stream once, marking every index that lies inside a
     * `const` declaration or a matched property array-literal initializer.
     *
     * @param list<NormalizedToken> $tokens
     *
     * @return array<int, bool>
     */
    private function scanForDataDeclarations(array $tokens): array
    {
        $count = \count($tokens);
        $isData = array_fill(0, $count, false);

        $i = 0;
        while ($i < $count) {
            $token = $tokens[$i];

            if ($token->type === \T_CONST) {
                $end = $this->findStatementEnd($tokens, $i);
                if ($end !== null) {
                    // Walk back over modifiers too (`private const ...`),
                    // matching the property branch below — a `const`
                    // declaration can carry the same visibility/final
                    // prefix, and both branches should treat it the same
                    // way (see the class docblock's cross-file note: this
                    // does not, and is not meant to, change the
                    // extendMatch()-driven cross-file table match).
                    $start = $this->modifierRunStart($tokens, $i);
                    $this->markRange($isData, $start, $end);
                    $i = $end + 1;

                    continue;
                }
            } elseif (\in_array($token->type, self::VISIBILITY_TYPES, true)) {
                $declEnd = $this->matchPropertyArrayDeclaration($tokens, $i);
                if ($declEnd !== null) {
                    $start = $this->modifierRunStart($tokens, $i);
                    $this->markRange($isData, $start, $declEnd);
                    $i = $declEnd + 1;

                    continue;
                }
            }

            $i++;
        }

        return $isData;
    }

    /**
     * Marks tokens in-place, by reference, rather than copying the full
     * `$isData` array per call. With one array_replace()+array_fill() copy
     * per declaration, tagging is quadratic in the number of declarations
     * in a file (measured: 250 constants ~0.006s, 2000 constants ~0.100s —
     * an 8x input growth costing ~17x the time). Marking in place makes the
     * whole scan linear in file size.
     *
     * @param array<int, bool> $isData
     */
    private function markRange(array &$isData, int $start, int $end): void
    {
        for ($k = $start; $k <= $end; $k++) {
            $isData[$k] = true;
        }
    }

    /**
     * Matches `<visibility> [modifiers] [type] $var = <array literal>;`
     * starting at the visibility token index $i.
     *
     * @param list<NormalizedToken> $tokens
     *
     * @return int|null index of the terminating `;`, or null if the token
     *                  sequence starting at $i is not this shape
     */
    private function matchPropertyArrayDeclaration(array $tokens, int $i): ?int
    {
        $count = \count($tokens);
        // A sentinel of $count (an always-out-of-range index) folds every
        // failure mode of advancePastAssignment() into the same "ran out of
        // tokens" bounds check below — no separate null-gate needed between
        // the two methods.
        $j = $this->advancePastAssignment($tokens, $i + 1);

        if ($j >= $count) {
            return null;
        }

        $arrayStart = $j;

        if ($tokens[$j]->value === '[') {
            // short array syntax — nothing further to consume before the depth scan
        } elseif ($tokens[$j]->type === \T_ARRAY) {
            $j++;
            if ($j >= $count || $tokens[$j]->value !== '(') {
                return null;
            }
        } else {
            return null;
        }

        $closeIdx = $this->findMatchingClose($tokens, $arrayStart);
        if ($closeIdx === null) {
            return null;
        }

        $afterIdx = $closeIdx + 1;
        if ($afterIdx >= $count || $tokens[$afterIdx]->value !== ';') {
            // Not a standalone statement — e.g. a constructor-promoted
            // parameter default, terminated by ',' or ')' instead.
            return null;
        }

        return $afterIdx;
    }

    /**
     * Advances past a contiguous run of modifier keywords (`public`,
     * `static`, `readonly`, ...), a type hint, and a `$var = ` assignment
     * target, starting at index $j.
     *
     * @param list<NormalizedToken> $tokens
     *
     * @return int index right after the `=`, or `count($tokens)` (an
     *             always-out-of-range sentinel) if that shape isn't present
     */
    private function advancePastAssignment(array $tokens, int $j): int
    {
        $count = \count($tokens);

        while ($j < $count && \in_array($tokens[$j]->type, self::MODIFIER_TYPES, true)) {
            $j++;
        }

        while ($j < $count && $this->isTypeHintToken($tokens[$j])) {
            $j++;
        }

        if ($j >= $count || $tokens[$j]->type !== \T_VARIABLE) {
            return $count;
        }
        $j++;

        if ($j >= $count || $tokens[$j]->value !== '=') {
            return $count;
        }

        return $j + 1;
    }

    private function isTypeHintToken(NormalizedToken $token): bool
    {
        if (\in_array($token->value, self::TYPE_HINT_VALUES, true)) {
            return true;
        }

        return \in_array($token->type, self::TYPE_HINT_TYPES, true);
    }

    /**
     * Walks backward over any contiguous run of modifier keywords
     * immediately preceding index $i (e.g. `static` before `private` in
     * `static private array $x = [...]`).
     *
     * @param list<NormalizedToken> $tokens
     */
    private function modifierRunStart(array $tokens, int $i): int
    {
        $start = $i;
        while ($start > 0 && \in_array($tokens[$start - 1]->type, self::MODIFIER_TYPES, true)) {
            $start--;
        }

        return $start;
    }

    /**
     * Scans forward from $startIdx for a `;` at local bracket depth 0.
     *
     * Used for `const` declarations, where $startIdx is the `const`
     * keyword itself (not an opening bracket).
     *
     * Also bails out (returns null) the moment a `$variable` token is seen,
     * at any depth: a `const` expression can never legally contain a
     * variable (RHS must be a compile-time constant expression), so seeing
     * one means the scan has run past the statement's real end — most
     * commonly across a `?>`/`<?php` boundary, since {@see TokenNormalizer}
     * strips `T_CLOSE_TAG`/`T_OPEN_TAG`/`T_INLINE_HTML` entirely, leaving
     * nothing in the stream to mark that boundary. Without this guard, a
     * `const` statement terminated by `?>` (rather than `;`) would have
     * the scan continue into the next PHP block and mis-tag unrelated
     * executable code as data — a false negative for real duplication in
     * that code (the worse failure direction, silently missed
     * copy-paste), rather than a false positive.
     *
     * @param list<NormalizedToken> $tokens
     */
    private function findStatementEnd(array $tokens, int $startIdx): ?int
    {
        $count = \count($tokens);
        $depth = 0;

        for ($k = $startIdx; $k < $count; $k++) {
            $t = $tokens[$k];

            if ($this->isOpenToken($t)) {
                $depth++;
            } elseif ($this->isCloseToken($t)) {
                $depth = max(0, $depth - 1);
            } elseif ($t->type === \T_VARIABLE) {
                return null;
            } elseif ($t->value === ';' && $depth === 0) {
                return $k;
            }
        }

        return null;
    }

    /**
     * Finds the index of the bracket matching the opening bracket at
     * $openIdx.
     *
     * @param list<NormalizedToken> $tokens
     */
    private function findMatchingClose(array $tokens, int $openIdx): ?int
    {
        $count = \count($tokens);
        $depth = 0;

        for ($k = $openIdx; $k < $count; $k++) {
            $t = $tokens[$k];

            if ($this->isOpenToken($t)) {
                $depth++;
            } elseif ($this->isCloseToken($t)) {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }

        return null;
    }

    private function isOpenToken(NormalizedToken $t): bool
    {
        return \in_array($t->value, ['[', '(', '{'], true)
            || $t->type === \T_CURLY_OPEN
            || $t->type === \T_DOLLAR_OPEN_CURLY_BRACES;
    }

    private function isCloseToken(NormalizedToken $t): bool
    {
        return \in_array($t->value, [']', ')', '}'], true);
    }
}
