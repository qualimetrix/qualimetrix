<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Allow;

use RuntimeException;

/**
 * Sealed VO representing one entry on either side of the {@code architecture.allow}
 * block — the source key or one of the targets.
 *
 * Three kinds, decided from the string content per the D4 grammar:
 *
 * - {@see SelectorKind::Exact} — bare layer name, e.g. {@code 'service'}.
 *   Matches one layer name literally.
 * - {@see SelectorKind::Glob} — contains glob metachars ({@code *}, {@code ?},
 *   {@code [}), e.g. {@code 'domain-*'}. Matches any concrete layer name whose
 *   characters satisfy the glob.
 * - {@see SelectorKind::Captured} — contains {@code {var}} placeholders, e.g.
 *   {@code 'app-{m}'}. Each placeholder captures a single namespace segment by
 *   default; {@code {var:**}} captures across separators.
 *
 * The constructor is private; instances are produced by {@see exact()},
 * {@see glob()}, {@see captured()}, or — for raw user input — by
 * {@see LayerSelectorParser::parse()}, the canonical entry point that
 * detects the kind and validates the grammar.
 *
 * **Match semantics** (split source / target):
 *
 * - {@see matchSource()} probes a concrete layer name on the LEFT side of an
 *   allow entry. On a successful match it returns the {@see CaptureBinding}
 *   produced by the source side (always empty for {@see SelectorKind::Exact}
 *   and {@see SelectorKind::Glob}); on no match it returns {@code null}.
 * - {@see matchesTarget()} probes a concrete layer name on the RIGHT side of
 *   an allow entry, passing the source-side binding so captured target
 *   selectors substitute the bound values into their captured segments
 *   before matching.
 *
 * The split is required by D4: a single {@code matches()} method cannot extract
 * the source-side binding (the source-side captures must be visible to the
 * target side, and there is no return slot for them in a boolean predicate).
 */
final readonly class LayerSelector
{
    /**
     * @var list<SelectorSegment>|null Parsed segments for captured selectors;
     *                                 null for exact / glob kinds (their lookup
     *                                 paths don't need the segment list).
     */
    private ?array $segments;

    /**
     * @param list<SelectorSegment>|null $segments
     */
    private function __construct(
        public SelectorKind $kind,
        public string $originalString,
        ?array $segments,
    ) {
        $this->segments = $segments;
    }

    /**
     * Builds an exact selector that matches the given layer name literally.
     * Caller is responsible for ensuring {@code $layerName} is a valid layer
     * identifier — the constructor does not re-validate.
     */
    public static function exact(string $layerName): self
    {
        return new self(SelectorKind::Exact, $layerName, null);
    }

    /**
     * Builds a glob selector. The pattern is interpreted at match time using
     * {@see fnmatch()} with no special flags (so {@code *} matches any run of
     * characters, {@code ?} matches exactly one, character classes use the
     * standard POSIX bracket form).
     */
    public static function glob(string $pattern): self
    {
        return new self(SelectorKind::Glob, $pattern, null);
    }

    /**
     * Builds a captured selector from a pre-parsed segment list. Typically the
     * factory is reached via {@see LayerSelectorParser::parse()}; tests can
     * call it directly with a handcrafted segment list.
     *
     * @param list<SelectorSegment> $segments
     */
    public static function captured(string $originalString, array $segments): self
    {
        return new self(SelectorKind::Captured, $originalString, $segments);
    }

    /**
     * Probes a concrete layer name on the source side of an allow entry.
     *
     * Returns {@code null} when the selector doesn't match. Returns a
     * {@see CaptureBinding} on success — empty for exact / glob kinds,
     * populated for captured kinds.
     */
    public function matchSource(string $layerName): ?CaptureBinding
    {
        return match ($this->kind) {
            SelectorKind::Exact => $this->originalString === $layerName
                ? CaptureBinding::empty()
                : null,
            SelectorKind::Glob => fnmatch($this->originalString, $layerName)
                ? CaptureBinding::empty()
                : null,
            SelectorKind::Captured => $this->matchCapturedSource($layerName),
        };
    }

    /**
     * Probes a concrete layer name on the target side, given the binding
     * produced by the source-side match. Captured target selectors substitute
     * the source-bound values into their captured segments before matching, so
     * {@code 'app-{m}' → 'domain-{m}'} accepts {@code app-Order → domain-Order}
     * but rejects {@code app-Order → domain-Inventory}.
     *
     * The binding may be empty (exact / glob source kinds emit an empty
     * binding); in that case captured target segments fall back to their
     * per-segment default pattern ({@code [^\\]+} per segment, {@code
     * [^\\]+(?:\\[^\\]+)*} for multi-segment) and match any layer name whose
     * literal parts agree and whose capture slots contain non-empty
     * identifier runs — this is the cross-instance escape hatch used by
     * {@see LayerPolicy::isAllowed()} when the entry's
     * {@code allow_cross_instance: true} flag is set.
     */
    public function matchesTarget(string $layerName, CaptureBinding $sourceBinding): bool
    {
        return match ($this->kind) {
            SelectorKind::Exact => $this->originalString === $layerName,
            SelectorKind::Glob => fnmatch($this->originalString, $layerName),
            SelectorKind::Captured => $this->matchCapturedTarget($layerName, $sourceBinding),
        };
    }

    /**
     * Returns the original selector string the user wrote — used for
     * diagnostics and the legacy "list of allowed targets" recommendation
     * surface in {@see \Qualimetrix\Rules\Architecture\LayerViolationRule}.
     */
    public function originalString(): string
    {
        return $this->originalString;
    }

    public function isExact(): bool
    {
        return $this->kind === SelectorKind::Exact;
    }

    public function isGlob(): bool
    {
        return $this->kind === SelectorKind::Glob;
    }

    public function isCaptured(): bool
    {
        return $this->kind === SelectorKind::Captured;
    }

    /**
     * Returns the capture variable names declared by this selector in
     * declaration order. Non-captured selectors return an empty list.
     *
     * Used by {@see \Qualimetrix\Architecture\Configuration\Validation\AllowValidator}
     * to cross-validate that every variable referenced on the allow entry's
     * target side is declared on the source side.
     *
     * @return list<string>
     */
    public function captureVariables(): array
    {
        if ($this->segments === null) {
            return [];
        }

        $names = [];
        foreach ($this->segments as $segment) {
            if (!$segment->isCapture) {
                continue;
            }
            \assert($segment->captureName !== null);
            $names[] = $segment->captureName;
        }

        return $names;
    }

    /**
     * Returns a map of capture variable name → {@code multiSegment} flag.
     * Non-captured selectors return an empty map.
     *
     * Used by {@see \Qualimetrix\Architecture\Configuration\Validation\AllowValidator}
     * to detect shape mismatches between source and target captures: a
     * variable declared {@code {m}} on the source but referenced
     * {@code {m:**}} on the target (or vice-versa) is rejected because the
     * runtime substitution silently ignores the target's shape annotation.
     *
     * @return array<string, bool> Variable name → true if multi-segment ({@code {var:**}}),
     *                             false if single-segment ({@code {var}}).
     */
    public function captureVariableShapes(): array
    {
        if ($this->segments === null) {
            return [];
        }

        $shapes = [];
        foreach ($this->segments as $segment) {
            if (!$segment->isCapture) {
                continue;
            }
            \assert($segment->captureName !== null);
            $shapes[$segment->captureName] = $segment->multiSegment;
        }

        return $shapes;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function matchCapturedSource(string $layerName): ?CaptureBinding
    {
        $regex = $this->buildRegex(null);
        $result = preg_match($regex, $layerName, $matches);
        if ($result === false) {
            throw self::pcreFailure($regex);
        }
        if ($result !== 1) {
            return null;
        }

        $values = [];
        \assert($this->segments !== null);
        foreach ($this->segments as $segment) {
            if (!$segment->isCapture) {
                continue;
            }
            $variable = $segment->captureName;
            \assert($variable !== null);
            if (isset($matches[$variable])) {
                $values[$variable] = $matches[$variable];
            }
        }

        return new CaptureBinding($values);
    }

    private function matchCapturedTarget(string $layerName, CaptureBinding $sourceBinding): bool
    {
        $regex = $this->buildRegex($sourceBinding);
        $result = preg_match($regex, $layerName);
        if ($result === false) {
            throw self::pcreFailure($regex);
        }

        return $result === 1;
    }

    /**
     * Surfaces a previously-silent PCRE compilation/runtime failure as a
     * loud {@see \RuntimeException}. The
     * {@see LayerSelectorParser::consumeCapture()} guard against duplicate
     * capture names already removes the known cause; this is a defence in
     * depth so future grammar additions can't quietly fall through to
     * `preg_match returning false` → policy entry silently dropped.
     */
    private static function pcreFailure(string $regex): RuntimeException
    {
        return new RuntimeException(\sprintf(
            'PCRE failure while matching layer selector regex %s: %s',
            $regex,
            preg_last_error_msg(),
        ));
    }

    /**
     * Builds a PCRE regex from the parsed segment list. If a binding is
     * supplied, captured segments whose variable is bound are replaced with the
     * literal bound value; unbound captures fall back to the default
     * {@code [^\\]+} per-segment pattern (multi-segment captures use
     * {@code [^\\]+(?:\\[^\\]+)*}).
     */
    private function buildRegex(?CaptureBinding $binding): string
    {
        \assert($this->segments !== null);

        $body = '';
        foreach ($this->segments as $segment) {
            $body .= $this->renderSegment($segment, $binding);
        }

        return '/^' . $body . '$/';
    }

    private function renderSegment(SelectorSegment $segment, ?CaptureBinding $binding): string
    {
        if (!$segment->isCapture) {
            return preg_quote($segment->literal, '/');
        }

        $variable = $segment->captureName;
        \assert($variable !== null);

        $boundValue = $binding?->get($variable);
        if ($boundValue !== null) {
            return preg_quote($boundValue, '/');
        }

        $segmentPattern = $segment->multiSegment
            ? '[^\\\\]+(?:\\\\[^\\\\]+)*'
            : '[^\\\\]+';

        return '(?P<' . $variable . '>' . $segmentPattern . ')';
    }
}
