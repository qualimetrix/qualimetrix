<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Allow;

/**
 * One segment of a parsed captured selector. Either a literal run of characters
 * (with backslash escapes already unescaped) or a capture placeholder carrying
 * the variable name and the multi-segment flag.
 *
 * Construction is via the static factories {@see literal()} and {@see capture()};
 * callers never use the raw constructor.
 */
final readonly class SelectorSegment
{
    /**
     * @param bool $isCapture Whether this segment is a {@code {var}} capture.
     * @param string $literal Literal text (unused when {@code $isCapture} is true).
     * @param string|null $captureName Capture variable name (only set when {@code $isCapture} is true).
     * @param bool $multiSegment Whether the capture spans namespace separators ({@code {var:**}}).
     */
    private function __construct(
        public bool $isCapture,
        public string $literal,
        public ?string $captureName,
        public bool $multiSegment,
    ) {}

    public static function literal(string $text): self
    {
        return new self(false, $text, null, false);
    }

    public static function capture(string $name, bool $multiSegment = false): self
    {
        return new self(true, '', $name, $multiSegment);
    }
}
