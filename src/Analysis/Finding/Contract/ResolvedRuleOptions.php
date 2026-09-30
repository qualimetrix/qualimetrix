<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;

final readonly class ResolvedRuleOptions
{
    /**
     * @param array<string, RuleOptionsInterface> $options
     * @param array<string, RuleSuppression> $suppressions
     */
    public function __construct(private array $options, private array $suppressions) {}

    public function for(string $producer): RuleOptionsInterface
    {
        return $this->options[$producer] ?? throw new LogicException(\sprintf('No resolved options for producer "%s".', $producer));
    }

    /** @return array<string, RuleOptionsInterface> */
    public function all(): array
    {
        return $this->options;
    }

    public function suppressionFor(string $producer): RuleSuppression
    {
        $this->for($producer);
        return $this->suppressions[$producer] ?? throw new LogicException(\sprintf('No resolved suppression for producer "%s".', $producer));
    }
}
