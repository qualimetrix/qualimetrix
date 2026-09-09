<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Refusal;

/** Where a rejected configuration contribution came from, and its locator within that source. */
final readonly class ConfigurationOrigin
{
    private function __construct(
        private ConfigurationSource $source,
        private ?string $locator,
    ) {}

    /** The only way to build one: a source plus its locator. */
    public static function of(ConfigurationSource $source, ?string $locator = null): self
    {
        return new self($source, $locator);
    }

    public function source(): ConfigurationSource
    {
        return $this->source;
    }

    /** File path, preset name, or CLI option name. Null for {@see ConfigurationSource::Resolved}. */
    public function locator(): ?string
    {
        return $this->locator;
    }
}
