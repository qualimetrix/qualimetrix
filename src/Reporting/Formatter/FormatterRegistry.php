<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter;

use InvalidArgumentException;

final class FormatterRegistry implements FormatterRegistryInterface
{
    /** @var list<string> Formatter names excluded from public listings (deprecated) */
    private const array HIDDEN_FORMATTERS = ['text-verbose'];

    /**
     * @var array<string, FormatterInterface>
     */
    private array $formatters = [];

    /**
     * @param iterable<FormatterInterface> $formatters
     */
    public function __construct(iterable $formatters = [])
    {
        foreach ($formatters as $formatter) {
            $this->register($formatter);
        }
    }

    /**
     * Registers a formatter.
     *
     * @throws InvalidArgumentException if formatter name is empty
     */
    public function register(FormatterInterface $formatter): void
    {
        $name = $formatter->getName();

        if (trim($name) === '') {
            throw new InvalidArgumentException(\sprintf(
                'Formatter %s has empty name',
                $formatter::class,
            ));
        }

        $this->formatters[$name] = $formatter;
    }

    public function get(string $name): FormatterInterface
    {
        if (!isset($this->formatters[$name])) {
            throw new InvalidArgumentException(\sprintf(
                'Formatter "%s" not found. Available formatters: %s',
                $name,
                $this->formatters !== []
                    ? implode(', ', array_keys($this->formatters))
                    : 'none',
            ));
        }

        return $this->formatters[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->formatters[$name]);
    }

    /**
     * @return list<string>
     */
    public function getAvailableNames(): array
    {
        $names = array_filter(
            array_keys($this->formatters),
            static fn(string $name): bool => !\in_array($name, self::HIDDEN_FORMATTERS, true),
        );
        sort($names);

        return $names;
    }
}
