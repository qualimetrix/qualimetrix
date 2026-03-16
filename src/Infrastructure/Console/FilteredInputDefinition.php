<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console;

use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * An InputDefinition that hides certain options from display.
 *
 * Hidden options are still fully functional (hasOption, getOption, getOptionForShortcut,
 * getOptionDefaults all work normally). Only getOptions() filters them out, which affects
 * help output and synopsis rendering.
 */
final class FilteredInputDefinition extends InputDefinition
{
    /** @var array<string, true> */
    private array $hiddenOptionNames = [];

    /**
     * Sets the option names that should be hidden from display.
     *
     * @param list<string> $names
     */
    public function setHiddenOptionNames(array $names): void
    {
        $this->hiddenOptionNames = array_fill_keys($names, true);
    }

    /**
     * Returns only visible options (excludes hidden ones).
     *
     * @return InputOption[]
     */
    public function getOptions(): array
    {
        return array_filter(
            parent::getOptions(),
            fn(InputOption $option): bool => !isset($this->hiddenOptionNames[$option->getName()]),
        );
    }
}
