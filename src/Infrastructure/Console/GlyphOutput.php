<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Closure;
use Qualimetrix\Reporting\Formatter\Prose\GlyphMode;
use Qualimetrix\Reporting\Formatter\Prose\ProseText;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Keeps every diagnostic caller on the owner's publication mode. */
final readonly class GlyphOutput implements OutputInterface
{
    /** @param Closure(): GlyphMode $mode */
    public function __construct(private OutputInterface $output, private Closure $mode) {}

    /**
     * @param string|iterable<mixed> $messages
     *
     * @qmx-ignore code-smell.boolean-argument — Implements Symfony's required OutputInterface signature.
     */
    public function write(string|iterable $messages, bool $newline = false, int $options = 0): void
    {
        foreach (is_iterable($messages) ? $messages : [$messages] as $message) {
            $published = \is_string($message) ? ProseText::publish($message, ($this->mode)())->body : $message;
            $this->output->write([$published], $newline, $options);
        }
    }

    /** @param string|iterable<mixed> $messages */
    public function writeln(string|iterable $messages, int $options = 0): void
    {
        $this->write($messages, true, $options);
    }

    public function setVerbosity(int $level): void
    {
        $this->output->setVerbosity($level);
    }

    public function getVerbosity(): int
    {
        return $this->output->getVerbosity();
    }

    public function isSilent(): bool
    {
        return $this->output->isSilent();
    }

    public function isQuiet(): bool
    {
        return $this->output->isQuiet();
    }

    public function isVerbose(): bool
    {
        return $this->output->isVerbose();
    }

    public function isVeryVerbose(): bool
    {
        return $this->output->isVeryVerbose();
    }

    public function isDebug(): bool
    {
        return $this->output->isDebug();
    }

    /** @qmx-ignore code-smell.boolean-argument — Implements Symfony's required OutputInterface signature. */
    public function setDecorated(bool $decorated): void
    {
        $this->output->setDecorated($decorated);
    }

    public function isDecorated(): bool
    {
        return $this->output->isDecorated();
    }

    public function setFormatter(OutputFormatterInterface $formatter): void
    {
        $this->output->setFormatter($formatter);
    }

    public function getFormatter(): OutputFormatterInterface
    {
        return $this->output->getFormatter();
    }
}
