<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Allow;

/**
 * Mutable parsing state for {@see LayerSelectorParser}. Kept
 * package-private to the {@code Allow/} namespace and used only as a transient
 * record between the parser's tokenisation helpers.
 *
 * Exposes three fields:
 *
 * - {@code segments} — accumulating list of parsed {@see SelectorSegment}s.
 * - {@code literalBuffer} — pending literal characters that have not yet been
 *   flushed into a {@see SelectorSegment::literal()}.
 * - {@code cursor} — current byte offset into the raw selector string.
 *
 * The class is intentionally not {@code readonly} — the parser mutates
 * {@code cursor} and {@code literalBuffer} as it walks the input.
 *
 * @internal
 */
final class ParseCapturedState
{
    /**
     * @var list<SelectorSegment>
     */
    public array $segments = [];

    public string $literalBuffer = '';

    public int $cursor = 0;

    /**
     * Capture variable names already used inside this selector. Tracked so
     * the parser can reject {@code '{m}-{m}'} at config-load time rather than
     * letting PCRE fail at runtime with a "two named subpatterns have the
     * same name" error and silently miss matches.
     *
     * @var array<string, true>
     */
    public array $seenCaptureNames = [];
}
