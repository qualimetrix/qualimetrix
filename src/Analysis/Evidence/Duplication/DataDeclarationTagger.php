<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication;

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
     * `T_CLOSE_TAG`/`T_OPEN_TAG`/`T_INLINE_HTML` tokens entirely — see its
     * class docblock). Without a marker, a forward scan started before the
     * boundary (e.g. {@see findStatementEnd()} for an unterminated `const`)
     * would run straight through it and mis-tag unrelated code in the next
     * PHP block as data — a false negative for real duplication there (the
     * worse failure direction, silently missed copy-paste).
     *
     * Never a real PHP token type: all real multi-char token constants are
     * positive ints, and single-character tokens are represented as `0` in
     * {@see NormalizedToken} (see `TokenNormalizer::normalize()`), so `-1`
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
            } elseif (\in_array($token->type, self::PROPERTY_DECLARATION_TRIGGER_TYPES, true)) {
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
        if ($afterIdx >= $count) {
            return null;
        }

        if (!$this->isDeclarationTerminator($tokens[$afterIdx])) {
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
     * Scans forward from $startIdx for the end of the statement at local
     * bracket depth 0 — either a literal `;`, or a
     * {@see PHP_CLOSE_TAG_BARRIER} token acting as one.
     *
     * Used for `const` declarations, where $startIdx is the `const`
     * keyword itself (not an opening bracket).
     *
     * A barrier counts as the statement's end, exactly like a literal `;`
     * would: `?>` is a legal implicit statement terminator in PHP (verified
     * via `php -l`: `const X = [1] ?>` parses cleanly and is equivalent to
     * `const X = [1];`), and {@see TokenNormalizer} preserves that boundary
     * as a barrier token specifically so this scan can recognize it. Before
     * that barrier existed, a `const` statement terminated by `?>` (rather
     * than a literal `;`) would leave the scan nothing to stop on, running
     * straight into the next PHP block and mis-tagging unrelated executable
     * code as data — a false negative for real duplication in that code
     * (the worse failure direction, silently missed copy-paste), rather
     * than a false positive.
     *
     * A barrier seen at nonzero depth bails out (returns null) instead: a
     * bracket can never legally stay open across a `?>`/`<?php` boundary
     * (verified via `php -l`: `const X = [1, ?>` ... `<?php 2];` is a parse
     * error), so nonzero depth there means the input is malformed and there
     * is no reliable statement end to report.
     *
     * Also bails out (returns null, i.e. "not a properly terminated
     * declaration") the moment a `$variable` token is seen, at any depth,
     * as a second, independent line of defense: a `const` expression can
     * never legally contain a variable (RHS must be a compile-time
     * constant expression), so seeing one means the scan has already run
     * past the statement's real end, regardless of the barrier check
     * above.
     *
     * @param list<NormalizedToken> $tokens
     */
    private function findStatementEnd(array $tokens, int $startIdx): ?int
    {
        $count = \count($tokens);
        $depth = 0;

        for ($k = $startIdx; $k < $count; $k++) {
            $t = $tokens[$k];

            if ($this->isBarrier($t)) {
                return $depth === 0 ? $k : null;
            } elseif ($this->isOpenToken($t)) {
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
     * Bails out (returns null) at a {@see PHP_CLOSE_TAG_BARRIER}: an array
     * literal can never legally span a `?>`/`<?php` boundary (PHP requires
     * expressions to be fully closed before leaving PHP mode), so hitting
     * one means the bracket never closed in this PHP block. Without this
     * guard, the depth-tracking loop would simply skip over the barrier
     * (its value matches none of {@see isOpenToken()}/{@see isCloseToken()})
     * and could match a bracket in the following PHP block instead.
     *
     * @param list<NormalizedToken> $tokens
     */
    private function findMatchingClose(array $tokens, int $openIdx): ?int
    {
        $count = \count($tokens);
        $depth = 0;

        for ($k = $openIdx; $k < $count; $k++) {
            $t = $tokens[$k];

            if ($this->isBarrier($t)) {
                return null;
            } elseif ($this->isOpenToken($t)) {
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

    private function isBarrier(NormalizedToken $t): bool
    {
        return $t->type === self::PHP_CLOSE_TAG_BARRIER;
    }

    /**
     * Whether $t legally ends a `const`/property declaration statement: a
     * literal `;`, or a {@see PHP_CLOSE_TAG_BARRIER} standing in for a PHP
     * closing tag, which is a legal implicit statement terminator (verified
     * with `php -l`: a property array default immediately followed by a
     * closing tag parses cleanly — same for a `const` array declaration).
     *
     * NB: do not write the closing tag literally inside a `//` comment in
     * this file — it prematurely ends the comment and exits PHP mode
     * (caught by `php -l` while authoring this fix).
     */
    private function isDeclarationTerminator(NormalizedToken $t): bool
    {
        return $t->value === ';' || $this->isBarrier($t);
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
