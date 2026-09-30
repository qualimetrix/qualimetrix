<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\RetiredSuppressionOptions;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleSuppression;
use Qualimetrix\Analysis\Finding\Exclusion\ConfiguredSuppression;

/** Builds every producer's immutable options before runtime configuration is committed. */
final readonly class RuleOptionsBuild
{
    public function __construct(
        private RuleExecutionInterface $execution,
        private RuleSuppressionSelectorDecoder $suppressionSelectors = new RuleSuppressionSelectorDecoder(),
    ) {}

    public function build(FindingConfiguration $configuration): ResolvedRuleOptions
    {
        $options = [];
        $suppressions = [];
        foreach ($this->execution->allRules() as $producer) {
            $ruleName = $producer->name;
            $optionsClass = $producer->optionsClass;
            if (!class_exists($optionsClass)) {
                throw new InvalidArgumentException(\sprintf('Options class %s does not exist', $optionsClass));
            }
            if (!is_a($optionsClass, RuleOptionsInterface::class, true)) {
                throw new InvalidArgumentException(\sprintf('Options class %s must implement %s', $optionsClass, RuleOptionsInterface::class));
            }
            $userConfig = $this->normalizeKeys($this->normalizeScalarConfig($configuration->ruleOptions->rules[$ruleName] ?? []));
            RetiredSuppressionOptions::refuseRuleOption($userConfig, ConfigurationOrigin::of(ConfigurationSource::Resolved));
            RuleOptionKeyRecognition::refuseMalformedFrameworkKeys($userConfig, $ruleName);
            $namespaces = ConfiguredSuppression::take($userConfig, FrameworkOptionKeys::NAMESPACES);
            $channels = ConfiguredSuppression::take($userConfig, FrameworkOptionKeys::NAMESPACE_CHANNELS);
            $paths = ConfiguredSuppression::take($userConfig, FrameworkOptionKeys::PATHS);
            $suppressions[$ruleName] = new RuleSuppression(
                paths: $this->suppressionSelectors->optionalPaths($ruleName, 'suppress_paths', $paths),
                namespaces: $this->suppressionSelectors->optionalNamespaces($ruleName, 'suppress_namespaces', $namespaces),
                namespaceChannels: $this->suppressionSelectors->channels($ruleName, $channels),
            );
            RuleOptionKeyRecognition::refuseUnknownKeys($userConfig, $ruleName, $optionsClass);
            $options[$ruleName] = $optionsClass::fromArray($userConfig);
        }
        return new ResolvedRuleOptions($options, $suppressions);
    }

    /**
     * Normalizes scalar rule config values to arrays.
     *
     * In YAML, a rule can be set to `false`, `true`, or `null` instead of an array.
     * This normalizes those scalars to proper config arrays.
     *
     * @return array<string, mixed>
     */
    private function normalizeScalarConfig(mixed $config): array
    {
        if (\is_array($config)) {
            return $config;
        }

        if ($config === false) {
            return [RuleOptionKey::ENABLED => false];
        }

        if ($config === true) {
            return [RuleOptionKey::ENABLED => true];
        }

        // null or any other scalar — treat as empty config (use defaults)
        return [];
    }

    /**
     * Normalizes snake_case keys to camelCase.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function normalizeKeys(array $options): array
    {
        $result = [];

        foreach ($options as $key => $value) {
            $normalizedKey = ConfigKeySpelling::normalize((string) $key);
            $result[$normalizedKey] = $value;
        }

        return $result;
    }
}
