<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionBand;

/** Band, shorthand, override and retired-key facts for one declaration. */
final readonly class RuleOptionKeyMetadata
{
    /**
     * @param list<RuleOptionBand> $bands
     * @param array<string, non-empty-list<string>> $spreading
     * @param array<string, string> $overrideAxes
     * @param array<string, string> $retired
     */
    public function __construct(
        public array $bands = [],
        public array $spreading = [],
        public array $overrideAxes = [],
        public array $retired = [],
    ) {}

    public function withBand(RuleOptionBand $band): self
    {
        return new self([...$this->bands, $band], $this->spreading, $this->overrideAxes, $this->retired);
    }

    /** @param non-empty-list<string> $paths */
    public function spreadingInto(string $key, array $paths): self
    {
        return new self($this->bands, [...$this->spreading, $key => $paths], $this->overrideAxes, $this->retired);
    }

    /** @param array<string, string> $axes */
    public function overriddenAs(array $axes): self
    {
        return new self($this->bands, $this->spreading, $axes, $this->retired);
    }

    public function retiring(string $key, string $summary): self
    {
        return new self($this->bands, $this->spreading, $this->overrideAxes, [...$this->retired, $key => $summary]);
    }
}
