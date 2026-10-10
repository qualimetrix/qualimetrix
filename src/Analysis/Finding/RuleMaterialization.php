<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding;

use Closure;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\ProducerDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Rule\RuleInterface;
use Traversable;

/** Materializes the rules and validators admitted by one invocation snapshot. */
final class RuleMaterialization
{
    /** @var list<array{metadata: RuleMetadata, create: Closure(): RuleInterface}> */
    private readonly array $rules;

    /** @var list<ProducerDeclaration> */
    private readonly array $classlessProducers;

    /** @var array<string, list<Closure(): ConfigurationValidatorInterface>> */
    private readonly array $validatorsByProducer;

    private ?ResolvedRuleOptions $snapshot = null;

    /** @var array<int, RuleInterface> */
    private array $ruleInstances = [];

    /** @var array<string, list<ConfigurationValidatorInterface>> */
    private array $validatorInstances = [];

    /**
     * @param iterable<array{metadata: RuleMetadata, create: Closure(): RuleInterface}> $rules
     * @param iterable<array{producer: string, create: Closure(): ConfigurationValidatorInterface}> $validators
     * @param iterable<ProducerDeclaration> $classlessProducers
     */
    public function __construct(iterable $rules, iterable $validators, iterable $classlessProducers)
    {
        $this->rules = $rules instanceof Traversable ? iterator_to_array($rules, false) : array_values($rules);
        $this->classlessProducers = $classlessProducers instanceof Traversable
            ? iterator_to_array($classlessProducers, false)
            : array_values($classlessProducers);

        $grouped = [];
        foreach ($validators as $validator) {
            $grouped[$validator['producer']][] = $validator['create'];
        }
        $this->validatorsByProducer = $grouped;
    }

    public function begin(ResolvedRuleOptions $snapshot): void
    {
        if ($this->snapshot === $snapshot) {
            return;
        }

        $this->ruleInstances = [];
        $this->validatorInstances = [];
        $this->snapshot = $snapshot;
    }

    /** @return list<RuleMetadata> */
    public function allProducers(?RuleEnablement $enablement): array
    {
        $producers = [];
        foreach ($this->rules as $lookup) {
            $metadata = $lookup['metadata'];
            $producers[] = new RuleMetadata(
                name: $metadata->name,
                optionsClass: $metadata->optionsClass,
                description: $metadata->description,
                aliases: $metadata->aliases,
                active: $enablement?->runs($metadata->name) ?? true,
            );
        }

        foreach ($this->classlessProducers as $producer) {
            $producers[] = new RuleMetadata(
                name: $producer->name,
                optionsClass: $producer->optionsClass,
                description: $producer->description,
                aliases: $producer->aliases,
                active: $enablement?->runs($producer->name) ?? true,
            );
        }

        return $producers;
    }

    /**
     * A host runs when its own producer or one of its classless producers is
     * enabled under the exact producer restriction.
     *
     * @return list<RuleInterface>
     */
    public function activeRules(RuleEnablement $enablement, ?string $restrictToProducer): array
    {
        $active = [];
        foreach ($this->rules as $index => $lookup) {
            $name = $lookup['metadata']->name;
            $ownEnabled = $enablement->runs($name) && ($restrictToProducer === null || $name === $restrictToProducer);
            if ($ownEnabled || $this->hostsAnEnabledProducer($name, $enablement, $restrictToProducer)) {
                $active[] = $this->ruleInstances[$index] ??= ($lookup['create'])();
            }
        }

        return $active;
    }

    /** @return list<ConfigurationValidatorInterface> */
    public function validatorsFor(string $producer): array
    {
        if (!isset($this->validatorInstances[$producer])) {
            $this->validatorInstances[$producer] = [];
            foreach ($this->validatorsByProducer[$producer] ?? [] as $create) {
                $this->validatorInstances[$producer][] = $create();
            }
        }

        return $this->validatorInstances[$producer];
    }

    private function hostsAnEnabledProducer(
        string $hostRuleName,
        RuleEnablement $enablement,
        ?string $restrictToProducer,
    ): bool {
        foreach ($this->classlessProducers as $producer) {
            if (
                $producer->hostRuleName === $hostRuleName
                && $enablement->runs($producer->name)
                && ($restrictToProducer === null || $producer->name === $restrictToProducer)
            ) {
                return true;
            }
        }

        return false;
    }
}
