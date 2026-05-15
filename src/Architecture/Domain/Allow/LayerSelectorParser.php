<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Allow;

/**
 * Tokenises a raw selector string into the appropriate {@see LayerSelector}
 * per the D4 grammar. Owns the brace / escape / quantifier parsing logic that
 * would otherwise crowd {@see LayerSelector} with implementation details
 * unrelated to its value-object surface.
 *
 * The parser is purely static and stateless — {@see parse()} is the single
 * entry point for raw-string construction. {@see LayerSelector} exposes only
 * the kind-specific factories ({@see LayerSelector::exact()}, {@see LayerSelector::glob()},
 * {@see LayerSelector::captured()}); user-facing string input always flows
 * through this class first.
 *
 * @internal
 */
final class LayerSelectorParser
{
    /**
     * Regex defining a capture-variable identifier (D4 grammar).
     */
    public const string VARIABLE_NAME_REGEX = '[A-Za-z_][A-Za-z0-9_]*';

    /**
     * Top-level entry point. Detects the selector kind from content per the
     * grammar (captured > glob > exact) and constructs the appropriate
     * {@see LayerSelector} variant.
     *
     * Any unescaped {@code [} is rejected up-front with an actionable hint.
     * Without this guard a confused {@code 'domain-[m]'} (user typing
     * {@code [m]} expecting capture syntax) would silently dispatch to glob
     * and be interpreted as a character class matching the single letter
     * {@code m}, producing semantic divergence from the user's intent with
     * no warning. The D4 grammar does not require character classes; the
     * remaining glob metacharacters ({@code *}, {@code ?}) cover the
     * documented use cases.
     *
     * @throws InvalidSelectorException If the grammar is violated.
     */
    public static function parse(string $raw): LayerSelector
    {
        if ($raw === '') {
            throw new InvalidSelectorException('selector must be a non-empty string.');
        }

        $bracketAt = self::firstUnescapedBracket($raw);
        if ($bracketAt !== null) {
            throw new InvalidSelectorException(\sprintf(
                "unsupported '[' at offset %d in selector \"%s\" — character classes are not part of the selector grammar. " .
                'Did you mean a capture variable? Use {var} for single-segment captures (e.g. \'domain-{m}\') or {var:**} for cross-segment captures.',
                $bracketAt,
                $raw,
            ));
        }

        if (self::containsBrace($raw)) {
            return self::parseCaptured($raw);
        }

        if (self::containsGlobMetachar($raw)) {
            return LayerSelector::glob($raw);
        }

        return LayerSelector::exact($raw);
    }

    /**
     * Parses a captured selector string into a segment list.
     */
    private static function parseCaptured(string $raw): LayerSelector
    {
        $state = new ParseCapturedState();
        $length = \strlen($raw);

        while ($state->cursor < $length) {
            $char = $raw[$state->cursor];

            if ($char === '\\') {
                if ($state->cursor + 1 >= $length) {
                    throw new InvalidSelectorException(\sprintf(
                        "dangling '\\' at end of selector \"%s\".",
                        $raw,
                    ));
                }
                self::consumeEscape($raw, $state);

                continue;
            }

            if ($char === '}') {
                throw self::unbalancedClose($raw, $state->cursor);
            }

            if ($char === '{') {
                self::consumeCapture($raw, $state);

                continue;
            }

            $state->literalBuffer .= $char;
            $state->cursor++;
        }

        self::flushLiteralBuffer($state);

        // Invariant: containsBrace() returned true to dispatch here, and every
        // code path through the while-loop either records at least one capture
        // segment (via consumeCapture's buildCaptureSegment call) or throws
        // (unbalancedClose / unbalancedOpen / empty capture). The segment list
        // is therefore non-empty AND contains at least one capture by the time
        // we reach this point — asserts make the invariant enforceable in
        // debug builds without paying a production-mode cost.
        \assert($state->segments !== [], 'parseCaptured invariant: segments must be non-empty');
        \assert(
            array_any(
                $state->segments,
                static fn(SelectorSegment $segment): bool => $segment->captureName !== null,
            ),
            'parseCaptured invariant: at least one segment must be a capture',
        );

        return LayerSelector::captured($raw, $state->segments);
    }

    /**
     * Consumes a backslash-prefixed character. Recognised escapes ({@code \\{},
     * {@code \\}}, {@code \\\\}) emit the unescaped character into the literal
     * buffer; unknown escapes pass through verbatim so callers can still see
     * the original intent.
     */
    private static function consumeEscape(string $raw, ParseCapturedState $state): void
    {
        $next = $raw[$state->cursor + 1];
        if ($next === '{' || $next === '}' || $next === '\\') {
            $state->literalBuffer .= $next;
        } else {
            $state->literalBuffer .= '\\' . $next;
        }
        $state->cursor += 2;
    }

    /**
     * Consumes one {@code {name[:**]}} capture, flushing any pending literal
     * first and advancing the cursor past the closing brace. Rejects a
     * repeated capture name within the same selector — without this guard
     * PCRE would compile {@code (?P<m>…)(?P<m>…)} and fail at runtime with
     * "two named subpatterns have the same name", silently dropping the
     * allow-list entry.
     */
    private static function consumeCapture(string $raw, ParseCapturedState $state): void
    {
        if ($state->literalBuffer !== '') {
            $state->segments[] = SelectorSegment::literal($state->literalBuffer);
            $state->literalBuffer = '';
        }

        $openAt = $state->cursor;
        $closeAt = self::findBraceClose($raw, $openAt);
        $captureBody = substr($raw, $openAt + 1, $closeAt - $openAt - 1);
        $segment = self::buildCaptureSegment($captureBody, $raw, $openAt);

        \assert($segment->captureName !== null);
        if (isset($state->seenCaptureNames[$segment->captureName])) {
            throw new InvalidSelectorException(\sprintf(
                "duplicate capture name '%s' in selector \"%s\" — each variable may only appear once.",
                $segment->captureName,
                $raw,
            ));
        }
        $state->seenCaptureNames[$segment->captureName] = true;

        $state->segments[] = $segment;
        $state->cursor = $closeAt + 1;
    }

    private static function flushLiteralBuffer(ParseCapturedState $state): void
    {
        if ($state->literalBuffer !== '') {
            $state->segments[] = SelectorSegment::literal($state->literalBuffer);
            $state->literalBuffer = '';
        }
    }

    private static function unbalancedClose(string $raw, int $offset): InvalidSelectorException
    {
        return new InvalidSelectorException(\sprintf(
            "unbalanced '}' at offset %d in selector \"%s\".",
            $offset,
            $raw,
        ));
    }

    private static function findBraceClose(string $raw, int $openAt): int
    {
        $length = \strlen($raw);
        for ($i = $openAt + 1; $i < $length; $i++) {
            $char = $raw[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $i++;

                continue;
            }
            if ($char === '{') {
                throw new InvalidSelectorException(\sprintf(
                    "nested '{' at offset %d in selector \"%s\" — captures cannot contain other captures.",
                    $i,
                    $raw,
                ));
            }
            if ($char === '}') {
                return $i;
            }
        }

        throw new InvalidSelectorException(\sprintf(
            "unbalanced '{' at offset %d in selector \"%s\".",
            $openAt,
            $raw,
        ));
    }

    private static function buildCaptureSegment(string $body, string $raw, int $openAt): SelectorSegment
    {
        if ($body === '') {
            throw new InvalidSelectorException(\sprintf(
                "empty capture '{}' at offset %d in selector \"%s\".",
                $openAt,
                $raw,
            ));
        }

        $multiSegment = false;
        $name = $body;
        if (str_contains($body, ':')) {
            [$name, $quantifier] = explode(':', $body, 2);
            if ($quantifier !== '**') {
                throw new InvalidSelectorException(\sprintf(
                    "unknown capture quantifier ':%s' in selector \"%s\" (only ':**' is supported).",
                    $quantifier,
                    $raw,
                ));
            }
            $multiSegment = true;
        }

        if (preg_match('/^' . self::VARIABLE_NAME_REGEX . '$/', $name) !== 1) {
            throw new InvalidSelectorException(\sprintf(
                "invalid capture name '%s' in selector \"%s\" (must match %s).",
                $name,
                $raw,
                self::VARIABLE_NAME_REGEX,
            ));
        }

        return SelectorSegment::capture($name, $multiSegment);
    }

    /**
     * Quick scan honouring backslash-escaped braces. Returns true if at least
     * one unescaped {@code {} or {@code }} is present.
     */
    private static function containsBrace(string $raw): bool
    {
        $length = \strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $i++;

                continue;
            }
            if ($char === '{' || $char === '}') {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the byte offset of the first unescaped {@code [} in the raw
     * selector, or {@code null} if none is present. Honours backslash escapes
     * so a future {@code '\\['} syntax (if added) does not trip this guard.
     */
    private static function firstUnescapedBracket(string $raw): ?int
    {
        $length = \strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $i++;

                continue;
            }
            if ($char === '[') {
                return $i;
            }
        }

        return null;
    }

    private static function containsGlobMetachar(string $raw): bool
    {
        return strpbrk($raw, '*?') !== false;
    }
}
