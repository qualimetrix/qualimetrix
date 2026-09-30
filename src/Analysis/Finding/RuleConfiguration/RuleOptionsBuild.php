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
            $file = $this->normalizeKeys($this->normalizeScalarConfig($configuration->ruleOptions->rules[$ruleName] ?? []));
            $cli = $this->expandDotNotation($configuration->cliOverrides->options[$ruleName] ?? []);
            $userConfig = $this->deepMerge($file, $cli, $ruleName);
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

    /**
     * Expands dot notation keys into nested arrays.
     *
     * E.g., ['callable.warning' => 5] becomes ['callable' => ['warning' => 5]]
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function expandDotNotation(array $options): array
    {
        $result = [];

        foreach ($options as $key => $value) {
            $keys = explode('.', (string) $key);

            if (\count($keys) === 1) {
                // No dot notation
                $result[$key] = $value;
                continue;
            }

            // Build nested array
            $current = &$result;
            foreach ($keys as $i => $part) {
                if ($i === \count($keys) - 1) {
                    $current[$part] = $value;
                } else {
                    if (!isset($current[$part]) || !\is_array($current[$part])) {
                        $current[$part] = [];
                    }
                    $current = &$current[$part];
                }
            }
        }

        return $result;
    }

    /**
     * Deep merges arrays recursively.
     *
     * Before merging, unfolds a `threshold` shorthand into the graduated
     * `warning`/`error` pair it stands for — in EACH layer, independently —
     * see {@see RuleOptionThresholdShorthand} for why a shorthand must be
     * gone from both sides before they merge rather than evicted from one of
     * them. Applied recursively, so hierarchical rule levels (e.g.
     * `callable:`/`class:`) get unfolded at the level the shorthand actually
     * lives at — `$path` tracks the dot-joined nesting (`''`, `'method'`,
     * `'class'`, ...) consulted by {@see RuleThresholdKeyGroupRegistry}. A
     * conflict where $override itself sets both a shorthand and a graduated
     * key (same source) is left untouched by unfolding and still reaches
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser} as a genuine
     * configuration error.
     *
     * $override is always the CLI layer here (see {@see self::build()}), and
     * no CLI door can write a bare `null`: `--rule-opt`'s
     * {@see RuleOptionsParser::parseRuleOption()} and every short alias's
     * `refuseEmptyAliasValue()` both refuse an empty value before a value
     * ever reaches this method. So "does an overlay's `~` erase a value the
     * layer below wrote" is not this merge site's question to answer — it is
     * answered once, at {@see \Qualimetrix\Analysis\Finding\Configuration\FindingConfigurationResolver},
     * where a YAML `~` genuinely can reach a layer this way.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function deepMerge(array $base, array $override, string $ruleName, string $path = ''): array
    {
        $result = RuleOptionThresholdShorthand::unfold($base, $ruleName, $path);
        $override = RuleOptionThresholdShorthand::unfold($override, $ruleName, $path);

        foreach ($override as $key => $value) {
            if (\is_array($value) && isset($result[$key]) && \is_array($result[$key])) {
                $childPath = $path === '' ? (string) $key : $path . '.' . $key;
                $result[$key] = $this->deepMerge($result[$key], $value, $ruleName, $childPath);
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
