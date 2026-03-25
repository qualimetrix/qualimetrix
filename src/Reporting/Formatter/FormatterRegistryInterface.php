<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use InvalidArgumentException;

interface FormatterRegistryInterface
{
    /**
     * Returns formatter by name.
     *
     * @throws InvalidArgumentException If formatter not found
     */
    public function get(string $name): FormatterInterface;

    /**
     * Checks if formatter exists.
     */
    public function has(string $name): bool;

    /**
     * Returns list of available formatter names.
     *
     * @return list<string>
     */
    public function getAvailableNames(): array;
}
