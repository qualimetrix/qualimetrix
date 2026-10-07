<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Reporting\Formatter\Prose\ProseText;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;

final class GlyphConsoleSection extends ConsoleSectionOutput
{
    /** @param resource $stream
     * @param array<int, ConsoleSectionOutput> $sections
     *
     * @param-out array<ConsoleSectionOutput> $sections
     */
    public function __construct(
        $stream,
        array &$sections,
        int $verbosity,
        bool $decorated,
        OutputFormatterInterface $formatter,
        private readonly ErrorStream $owner,
    ) {
        parent::__construct($stream, $sections, $verbosity, $decorated, $formatter);
    }

    protected function doWrite(string $message, bool $newline): void
    {
        parent::doWrite(ProseText::publish($message, $this->owner->glyphMode())->body, $newline);
    }
}
