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

    /**
     * File path, preset name, or CLI/config-key name. Usually null for
     * {@see ConfigurationSource::Resolved} — a merged value with no single
     * originating file or option left to name — except where a caller
     * still has the resolved key name at hand and passes it as a
     * diagnostic hint (e.g. `ConfigSchema::FAIL_ON`, `ConfigSchema::MEMORY_LIMIT`).
     */
    public function locator(): ?string
    {
        return $this->locator;
    }
}
