<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Configuration;

use Qualimetrix\Analysis\Finding\Contract\RuleOptionsDocument;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;

final readonly class FindingConfiguration
{
    public function __construct(
        public RuleOptionsDocument $ruleOptions,
        public FindingCliOverrides $cliOverrides,
        public RuleSelection $selection,
    ) {}

    /** No rule options, no command-line overrides, every rule selected. */
    public static function none(): self
    {
        return new self(new RuleOptionsDocument(), new FindingCliOverrides(), new RuleSelection());
    }

    /** @param array<string, mixed> $rules */
    public function withRuleOptions(array $rules): self
    {
        return new self(new RuleOptionsDocument($rules), $this->cliOverrides, $this->selection);
    }

    /** @param array<string, array<string, mixed>> $options */
    public function withCliOverrides(array $options): self
    {
        return new self($this->ruleOptions, new FindingCliOverrides($options), $this->selection);
    }

    public function withSelection(RuleSelection $selection): self
    {
        return new self($this->ruleOptions, $this->cliOverrides, $selection);
    }
}
