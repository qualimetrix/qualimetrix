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

    /**
     * Every `--format-opt` key any registered formatter reads, sorted and deduplicated.
     *
     * Spans hidden formatters too: a key stays real while any formatter reads it,
     * whether or not that formatter is offered in listings.
     *
     * @return list<string>
     */
    public function declaredFormatOptionKeys(): array;
}
