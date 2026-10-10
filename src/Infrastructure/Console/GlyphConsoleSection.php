<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Closure;
use Qualimetrix\Reporting\Formatter\Prose\GlyphMode;
use Qualimetrix\Reporting\Formatter\Prose\ProseText;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;

final class GlyphConsoleSection extends ConsoleSectionOutput
{
    /** @param resource $stream
     * @param array<int, ConsoleSectionOutput> $sections
     *
     * @param-out array<ConsoleSectionOutput> $sections
     *
     * @param Closure(): GlyphMode $mode
     *
     * @qmx-ignore code-smell.boolean-argument — Symfony's native section constructor requires the decoration flag.
     */
    public function __construct(
        $stream,
        array &$sections,
        int $verbosity,
        bool $decorated,
        OutputFormatterInterface $formatter,
        private readonly Closure $mode,
    ) {
        parent::__construct($stream, $sections, $verbosity, $decorated, $formatter);
    }

    /** @qmx-ignore code-smell.boolean-argument — Overrides Symfony's required output signature. */
    protected function doWrite(string $message, bool $newline): void
    {
        parent::doWrite(ProseText::publish($message, ($this->mode)())->body, $newline);
    }
}
