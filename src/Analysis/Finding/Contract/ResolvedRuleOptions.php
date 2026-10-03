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
     * @param array<string, array<string, OptionActivity>> $activity producer => level or empty key => switch
     */
    public function __construct(private array $options, private array $suppressions, private array $activity = []) {}

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

    public function activityOf(string $producer, ?\Qualimetrix\Core\Symbol\SymbolLevel $level): OptionActivity
    {
        $this->for($producer);
        $slot = $level === null ? '' : $level->value;
        return $this->activity[$producer][$slot]
            ?? throw new LogicException(\sprintf('No resolved activity for producer "%s" at level "%s".', $producer, $slot));
    }
}
