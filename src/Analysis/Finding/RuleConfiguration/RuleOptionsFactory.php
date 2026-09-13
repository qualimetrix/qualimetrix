<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\RetiredSuppressionOptions;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Factory for creating RuleOptions instances with merged configuration.
 *
 * Priority: defaults → config file → CLI options
 *
 * Reads option values from RuleOptionsRegistry (storage concern) and performs
 * merging and normalization (creation concern). Deciding which keys a rule
 * answers for, what form each of their values may take, and refusing the rest,
 * is {@see RuleOptionKeyRecognition}.
 */
final class RuleOptionsFactory
{
    public function __construct(
        private readonly RuleOptionsRegistry $registry,
    ) {}

    /**
     * Creates rule options with merged configuration.
     *
     * @param string $ruleName The rule name (slug)
     * @param class-string<RuleOptionsInterface> $optionsClass The options class
     */
    public function create(string $ruleName, string $optionsClass): RuleOptionsInterface
    {
        if (!class_exists($optionsClass)) {
            throw new InvalidArgumentException(\sprintf('Options class %s does not exist', $optionsClass));
        }

        $reflection = new ReflectionClass($optionsClass);

        if (!$reflection->implementsInterface(RuleOptionsInterface::class)) {
            throw new InvalidArgumentException(\sprintf(
                'Options class %s must implement %s',
                $optionsClass,
                RuleOptionsInterface::class,
            ));
        }

        // 1. Get defaults from constructor parameters
        $defaults = $this->extractDefaults($reflection);

        // 2. Merge config file options with CLI options (highest priority),
        // WITHOUT seeding constructor defaults yet. Keeping this merge
        // strictly user-supplied lets Options::fromArray() (and, in turn,
        // ThresholdParser::parse()) tell "the user explicitly set this key"
        // apart from "this is just the constructor default" — see the
        // "userConfig vs defaults" note on $merged below for why that
        // distinction matters.
        $configFileOptions = $this->registry->configFileOptions();
        $fileOptions = $this->normalizeScalarConfig($configFileOptions[$ruleName] ?? []);
        $normalizedFileOptions = $this->normalizeKeys($fileOptions);

        // Expand dot notation (e.g., 'callable.warning' => ['callable' => ['warning' => ...]])
        $cliOptions = $this->registry->cliOptions();
        $cliRuleOptions = $this->expandDotNotation($cliOptions[$ruleName] ?? []);

        $userConfig = $this->deepMerge($normalizedFileOptions, $cliRuleOptions, $ruleName);

        // 3. Extract and store framework-level keys (suppress_namespaces,
        // suppress_namespace_channels, suppress_paths) BEFORE deciding whether $userConfig counts as
        // "empty" below. These keys are consumed by the framework — they
        // never reach Options::fromArray() — so a rule configured with
        // ONLY these keys (e.g. `{ suppress_namespaces: [App\Tests] }` and
        // nothing else) must still be treated as "unconfigured" for the
        // fromArray() input, not as "configured with an empty rest-of-
        // config". Stripping them first and THEN checking for emptiness is
        // what makes that distinction correctly; checking emptiness first
        // (as an earlier version of this method did) let a
        // framework-only config slip through as "non-empty", so the
        // extraction below emptied it out AFTER the check already decided
        // not to fall back to defaults — the rule then received `[]` in
        // fromArray() and several Options classes special-case that as
        // "disabled" (see the note on $merged below), silently turning the
        // rule off.
        RetiredSuppressionOptions::refuseRuleOption($userConfig, ConfigurationOrigin::of(ConfigurationSource::Resolved));
        RuleOptionKeyRecognition::refuseMalformedFrameworkKeys($userConfig, $ruleName);
        $this->extractSuppressNamespaces($ruleName, $userConfig);
        $this->extractSuppressPaths($ruleName, $userConfig);

        // 4. $merged is what Options::fromArray() actually receives.
        //
        // When the user configured nothing at all for this rule (after the
        // framework-level extraction above), fall back to the full
        // constructor-defaults array so fromArray() still sees a non-empty
        // config — several Options classes special-case an empty array as
        // "definitely no config given" (used by direct fromArray([])
        // callers outside the factory, e.g. AnalysisPipeline's threshold-
        // override-support probe) and would otherwise report as disabled.
        //
        // When the user configured *something* else, pass that through
        // as-is instead of pre-merging it over $defaults. Every
        // Options::fromArray() already applies its own per-field
        // defaulting (constructor defaults, `?? default`, or
        // ThresholdParser's $defaultWarning/$defaultError arguments) for
        // keys the user didn't set, so nothing is lost.
        //
        // This is not just a cosmetic simplification: pre-seeding ALL
        // defaults used to make e.g. `warning`/`error` appear "explicitly
        // set" to ThresholdParser even when only their constructor default
        // was injected, so a bare `threshold: N` shorthand (which never
        // touches warning/error) was flagged as "mixed with warning/error"
        // — a false positive, since the user only ever wrote `threshold`.
        // Passing through only what the user actually wrote restores the
        // ability to tell "explicitly set" from "defaulted".
        $merged = $userConfig === [] ? $defaults : $userConfig;

        // 5. Refuse every option key nothing at its depth answers for
        RuleOptionKeyRecognition::refuseUnknownKeys($userConfig, $ruleName, $optionsClass);

        // 6. Create instance using fromArray
        return $optionsClass::fromArray($merged);
    }

    /**
     * Extracts suppress_namespaces from merged options and stores them in the provider.
     *
     * Supports both snake_case (from config file) and camelCase (from CLI).
     * Removes the key from $merged so it doesn't leak into Options::fromArray().
     *
     * @param array<string, mixed> $merged
     */
    private function extractSuppressNamespaces(string $ruleName, array &$merged): void
    {
        $namespaces = $this->takeAliasedOption($merged, 'suppressNamespaces', 'suppress_namespaces');
        $this->registry->configureNamespaceExclusions($ruleName, $namespaces);

        $channels = $this->takeAliasedOption($merged, 'suppressNamespaceChannels', 'suppress_namespace_channels');
        $this->registry->configureNamespaceChannelExclusions($ruleName, $channels);
    }

    /**
     * Extracts suppress_paths from merged options and stores them in the provider.
     *
     * Supports both snake_case (from config file) and camelCase (from CLI).
     * Removes the key from $merged so it doesn't leak into Options::fromArray().
     *
     * @param array<string, mixed> $merged
     */
    private function extractSuppressPaths(string $ruleName, array &$merged): void
    {
        $raw = $this->takeAliasedOption($merged, 'suppressPaths', 'suppress_paths');

        if (\is_string($raw)) {
            $patterns = [$raw];
        } elseif (\is_array($raw)) {
            $patterns = array_values(array_filter($raw, 'is_string'));
        } else {
            return;
        }

        if ($patterns !== []) {
            $this->registry->configurePathExclusions($ruleName, $patterns);
        }
    }

    /**
     * Reads and removes one option exposed under camelCase and snake_case aliases.
     *
     * @param array<string, mixed> $options
     */
    private function takeAliasedOption(array &$options, string $camelKey, string $snakeKey): mixed
    {
        $value = $options[$camelKey] ?? $options[$snakeKey] ?? null;

        unset($options[$camelKey], $options[$snakeKey]);

        return $value;
    }

    /**
     * Extracts default values from constructor parameters.
     *
     * @param ReflectionClass<RuleOptionsInterface> $reflection
     *
     * @return array<string, mixed>
     */
    private function extractDefaults(ReflectionClass $reflection): array
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $defaults = [];
        foreach ($constructor->getParameters() as $param) {
            if ($param->isDefaultValueAvailable()) {
                $defaults[$param->getName()] = $param->getDefaultValue();
            } else {
                // For parameters without defaults, use type-based defaults
                $defaults[$param->getName()] = $this->getTypeDefault($param);
            }
        }

        return $defaults;
    }

    /**
     * Gets default value based on parameter type.
     */
    private function getTypeDefault(ReflectionParameter $param): mixed
    {
        $type = $param->getType();

        if (!$type instanceof ReflectionNamedType) {
            return null;
        }

        if ($type->allowsNull()) {
            return null;
        }

        return match ($type->getName()) {
            'bool' => true,
            'int' => 0,
            'float' => 0.0,
            'string' => '',
            'array' => [],
            default => null,
        };
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
     * $override is always the CLI layer here (see {@see self::create()}), and
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
