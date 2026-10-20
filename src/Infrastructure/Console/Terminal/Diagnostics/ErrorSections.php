<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Terminal\Diagnostics;

use Closure;
use Qualimetrix\Infrastructure\Console\Terminal\Glyphs\GlyphConsoleSection;
use Qualimetrix\Reporting\Formatter\Prose\GlyphMode;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/** Owns the shared section list so diagnostics and progress redraw one error stream. */
final class ErrorSections
{
    /** @var array<int, ConsoleSectionOutput> */
    private array $sections = [];
    private ?OutputInterface $boundTo = null;
    private OutputInterface $diagnostics;
    private ?StreamOutput $sectionable = null;
    private ?ConsoleSectionOutput $progress = null;

    /** @param Closure(): GlyphMode $glyphMode */
    public function __construct(private readonly Closure $glyphMode)
    {
        $this->diagnostics = new NullOutput();
    }

    public function reset(): void
    {
        $this->sections = [];
        $this->boundTo = null;
        $this->diagnostics = new NullOutput();
        $this->sectionable = null;
        $this->progress = null;
    }

    public function writer(OutputInterface $output): OutputInterface
    {
        $this->bind($output);
        return $this->diagnostics;
    }

    public function currentWriter(): OutputInterface
    {
        return $this->diagnostics;
    }

    public function hasWriter(): bool
    {
        return !$this->diagnostics instanceof NullOutput;
    }

    public function boundWriter(OutputInterface $fallback): OutputInterface
    {
        return $this->hasWriter() ? $this->diagnostics : $fallback;
    }

    public function progressSection(OutputInterface $output): ?ConsoleSectionOutput
    {
        $this->bind($output);
        if ($this->sectionable === null) {
            return null;
        }
        return $this->progress ??= $this->sectionOn($this->sectionable);
    }

    public function stopProgress(): void
    {
        $this->progress?->clear();
        $this->progress = null;
    }

    private function bind(OutputInterface $output): void
    {
        if ($this->boundTo === $output) {
            return;
        }
        $this->sections = [];
        $this->progress = null;
        $this->boundTo = $output;
        if (!$output instanceof ConsoleOutputInterface) {
            $this->sectionable = null;
            $this->diagnostics = new NullOutput();
            return;
        }
        $error = $output->getErrorOutput();
        if (!$error instanceof StreamOutput || $error instanceof ConsoleSectionOutput) {
            $this->sectionable = null;
            $this->diagnostics = $error;
            return;
        }
        $this->sectionable = $error;
        $this->diagnostics = $this->sectionOn($error);
    }

    private function sectionOn(StreamOutput $error): ConsoleSectionOutput
    {
        return new GlyphConsoleSection(
            $error->getStream(),
            $this->sections,
            $error->getVerbosity(),
            $error->isDecorated(),
            $error->getFormatter(),
            $this->glyphMode,
        );
    }
}
