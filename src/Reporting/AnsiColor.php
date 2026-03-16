<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting;

/**
 * Lightweight ANSI color wrapper for formatter output.
 *
 * When disabled, all methods return the input text unchanged.
 */
final readonly class AnsiColor
{
    public function __construct(
        private bool $enabled,
    ) {}

    public function red(string $text): string
    {
        return $this->wrap($text, '31');
    }

    public function yellow(string $text): string
    {
        return $this->wrap($text, '33');
    }

    public function green(string $text): string
    {
        return $this->wrap($text, '32');
    }

    public function cyan(string $text): string
    {
        return $this->wrap($text, '36');
    }

    public function bold(string $text): string
    {
        return $this->wrap($text, '1');
    }

    public function dim(string $text): string
    {
        return $this->wrap($text, '38;5;249');
    }

    public function boldRed(string $text): string
    {
        return $this->wrap($text, '1;31');
    }

    public function boldYellow(string $text): string
    {
        return $this->wrap($text, '1;33');
    }

    public function boldGreen(string $text): string
    {
        return $this->wrap($text, '1;32');
    }

    private function wrap(string $text, string $code): string
    {
        if (!$this->enabled) {
            return $text;
        }

        return \sprintf("\e[%sm%s\e[0m", $code, $text);
    }
}
