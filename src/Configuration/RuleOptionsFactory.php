<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Core\Rule\AdditionalOptionKeysInterface;
use Qualimetrix\Core\Rule\RuleOptionKey;
use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Rule\ShorthandOptionKeysInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * Factory for creating RuleOptions instances with merged configuration.
 *
 * Priority: defaults → config file → CLI options
 *
 * Reads option values from RuleOptionsRegistry (storage concern)
 * and performs merging, normalization, and validation (creation concern).
 */
final class RuleOptionsFactory
{
    public function __construct(
        private readonly RuleOptionsRegistry $registry,
        private readonly LoggerInterface $logger = new NullLogger(),
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
        $configFileOptions = $this->registry->getConfigFileOptions();
        $fileOptions = $this->normalizeScalarConfig($configFileOptions[$ruleName] ?? []);
        $normalizedFileOptions = $this->normalizeKeys($fileOptions);

        // Expand dot notation (e.g., 'callable.warning' => ['callable' => ['warning' => ...]])
        $cliOptions = $this->registry->getCliOptions();
        $cliRuleOptions = $this->expandDotNotation($cliOptions[$ruleName] ?? []);

        $userConfig = $this->deepMerge($normalizedFileOptions, $cliRuleOptions, $ruleName);

        // 3. Extract and store framework-level keys (exclude_namespaces,
        // exclude_namespace_channels, exclude_paths) BEFORE deciding whether $userConfig counts as
        // "empty" below. These keys are consumed by the framework — they
        // never reach Options::fromArray() — so a rule configured with
        // ONLY these keys (e.g. `{ exclude_namespaces: [App\Tests] }` and
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
        // rule off. This was a real regression, caught by external review.
        $this->extractExcludeNamespaces($ruleName, $userConfig);
        $this->extractExcludePaths($ruleName, $userConfig);

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

        // 5. Warn about unknown option keys
        $this->warnAboutUnknownKeys($merged, $defaults, $ruleName, $optionsClass);

        // 6. Validate numeric fields before instantiation
        $this->validateNumericFields($merged, $ruleName);

        // 7. Create instance using fromArray
        return $optionsClass::fromArray($merged);
    }

    /**
     * Extracts exclude_namespaces from merged options and stores them in the provider.
     *
     * Supports both snake_case (from config file) and camelCase (from CLI).
     * Removes the key from $merged so it doesn't leak into Options::fromArray().
     *
     * @param array<string, mixed> $merged
     */
    private function extractExcludeNamespaces(string $ruleName, array &$merged): void
    {
        $provider = $this->registry->getExclusionProvider();
        $provider->configureExclusions(
            $ruleName,
            $this->takeAliasedOption($merged, 'excludeNamespaces', 'exclude_namespaces'),
        );
        $provider->configureChannelExclusions(
            $ruleName,
            $this->takeAliasedOption($merged, 'excludeNamespaceChannels', 'exclude_namespace_channels'),
        );
    }

    /**
     * Extracts exclude_paths from merged options and stores them in the provider.
     *
     * Supports both snake_case (from config file) and camelCase (from CLI).
     * Removes the key from $merged so it doesn't leak into Options::fromArray().
     *
     * @param array<string, mixed> $merged
     */
    private function extractExcludePaths(string $ruleName, array &$merged): void
    {
        $raw = $this->takeAliasedOption($merged, 'excludePaths', 'exclude_paths');

        if (\is_string($raw)) {
            $patterns = [$raw];
        } elseif (\is_array($raw)) {
            $patterns = array_values(array_filter($raw, 'is_string'));
        } else {
            return;
        }

        if ($patterns !== []) {
            $this->registry->getPathExclusionProvider()->setExclusions($ruleName, $patterns);
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
            $normalizedKey = lcfirst(str_replace(['_', '-'], '', ucwords((string) $key, '_-')));
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
     * Warns about unknown option keys in rule configuration.
     *
     * Compares merged config keys against known constructor parameters, plus
     * any extra keys the Options class declares via
     * {@see ShorthandOptionKeysInterface} or
     * {@see AdditionalOptionKeysInterface}. Framework-level keys
     * (excludeNamespaces, excludePaths) are excluded since they are
     * extracted before fromArray().
     *
     * > **Note:** reflection only sees constructor parameter names. Any
     * > {@see \Qualimetrix\Rules\Support\ThresholdParser} shorthand key that
     * > isn't also a constructor parameter — e.g. the bare `threshold` key,
     * > or rule-specific ones like `param-threshold` on `design.type-coverage`
     * > or `vo-threshold` on `code-smell.long-parameter-list` — is invisible
     * > to reflection. `ShorthandOptionKeysInterface` closes that gap: an
     * > Options class implements it to declare the extra keys its
     * > `fromArray()` actually accepts, and this method merges them into the
     * > known-keys set. Options classes that don't implement it (e.g. most
     * > `CodeSmellOptions`-based rules, which have no threshold concept at
     * > all beyond `enabled`) keep the old constructor-only behavior, so a
     * > `threshold` key on one of them correctly still warns. This also
     * > covers hierarchical rules: `CboOptions`/`InstabilityOptions` DO
     * > implement it, because their own top-level `fromArray()` also parses
     * > a bare `threshold` (applied uniformly to every nested level) — only
     * > a hierarchical wrapper whose top level routes nothing at all would
     * > stay unimplementing.
     *
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $defaults
     * @param class-string<RuleOptionsInterface> $optionsClass
     */
    private function warnAboutUnknownKeys(array $merged, array $defaults, string $ruleName, string $optionsClass): void
    {
        // Framework-level keys that are valid but not in the options constructor
        static $frameworkKeys = [
            'excludeNamespaces',
            'exclude_namespaces',
            'excludeNamespaceChannels',
            'exclude_namespace_channels',
            'excludePaths',
            'exclude_paths',
        ];

        $acceptedExtraKeys = $this->acceptedExtraOptionKeysFor($optionsClass);

        // Build known keys in both snake_case and camelCase forms
        $knownKeys = [...$frameworkKeys];
        foreach (array_keys($defaults) as $key) {
            $knownKeys[] = $key;
            // Also accept camelCase version of snake_case keys
            $camelKey = lcfirst(str_replace(['_', '-'], '', ucwords($key, '_-')));
            if ($camelKey !== $key) {
                $knownKeys[] = $camelKey;
            }
        }

        foreach ($acceptedExtraKeys as $acceptedExtraKey) {
            // Declared keys are canonical kebab-case; $merged keys are always
            // camelCase by the time they reach here (normalizeKeys()/
            // RuleOptionsParser::normalizeOptionName() already ran), so both
            // spellings must be accepted.
            $knownKeys[] = $acceptedExtraKey;
            $camelAcceptedExtraKey = lcfirst(str_replace(['_', '-'], '', ucwords($acceptedExtraKey, '_-')));
            if ($camelAcceptedExtraKey !== $acceptedExtraKey) {
                $knownKeys[] = $camelAcceptedExtraKey;
            }
        }

        foreach (array_keys($merged) as $key) {
            if (\in_array($key, $knownKeys, true)) {
                continue;
            }

            $availableOptions = [
                ...array_map($this->toCanonicalDisplayName(...), array_keys($defaults)),
                ...$acceptedExtraKeys,
            ];

            $this->logger->warning(\sprintf(
                'Unknown option "%s" for rule "%s". Available options: %s',
                $this->toCanonicalDisplayName((string) $key),
                $ruleName,
                implode(', ', $availableOptions),
            ));
        }
    }

    /**
     * Returns every top-level key accepted beyond constructor parameters.
     *
     * Threshold shorthand and non-threshold options remain separate contracts,
     * while the factory consumes their declarations through one cohesive seam.
     *
     * @param class-string<RuleOptionsInterface> $optionsClass
     *
     * @return list<string>
     */
    private function acceptedExtraOptionKeysFor(string $optionsClass): array
    {
        $keys = [];

        if (is_a($optionsClass, ShorthandOptionKeysInterface::class, true)) {
            $keys = $optionsClass::getShorthandOptionKeys();
        }

        if (is_a($optionsClass, AdditionalOptionKeysInterface::class, true)) {
            $keys = [...$keys, ...$optionsClass::getAdditionalOptionKeys()];
        }

        return $keys;
    }

    /**
     * Converts a constructor parameter name (always camelCase in PHP) to the
     * kebab-case spelling users actually type in `qmx.yaml`, presets, and
     * `--rule-opt` — the canonical, user-facing spelling for composite
     * (multi-word) option names (see CLAUDE.md rule-option naming policy).
     *
     * Both camelCase and kebab/snake_case input are always accepted (see
     * {@see normalizeKeys()} and `RuleOptionsParser::normalizeOptionName()`),
     * but the "Available options" hint must show a single, typeable spelling
     * rather than the internal PHP property name.
     */
    private function toCanonicalDisplayName(string $camelCaseKey): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $camelCaseKey));
    }

    /**
     * Validates that numeric option fields contain actual numeric values.
     *
     * Detects when YAML config contains a non-numeric string for a field whose name
     * suggests it should be numeric (e.g. threshold, warning, error, count, limit, etc.).
     * PHP's (int) cast would silently coerce "not_a_number" to 0, hiding misconfiguration.
     *
     * @param array<string, mixed> $options
     *
     * @throws RuntimeException when a numeric field contains a non-numeric string value
     */
    private function validateNumericFields(array $options, string $ruleName, string $path = ''): void
    {
        // Key name suffixes/substrings that indicate a numeric value is expected.
        static $numericPatterns = ['threshold', 'warning', 'error', 'count', 'limit', 'depth', 'min', 'max', 'size', 'length', 'weight', 'ratio', 'score'];

        foreach ($options as $key => $value) {
            $fullKey = $path !== '' ? "{$path}.{$key}" : (string) $key;

            if (\is_array($value)) {
                $this->validateNumericFields($value, $ruleName, $fullKey);

                continue;
            }

            if (!\is_string($value)) {
                continue;
            }

            $lowerKey = strtolower((string) $key);
            $isNumericField = false;
            foreach ($numericPatterns as $pattern) {
                if (str_contains($lowerKey, $pattern)) {
                    $isNumericField = true;
                    break;
                }
            }

            if ($isNumericField && (!is_numeric($value) || !is_finite((float) $value))) {
                throw new RuntimeException(
                    \sprintf(
                        'Invalid configuration for rule "%s": option "%s" must be numeric, got "%s".',
                        $ruleName,
                        $fullKey,
                        $value,
                    ),
                );
            }
        }
    }

    /**
     * Deep merges arrays recursively.
     *
     * Before merging, evicts `threshold` vs `warning`/`error` mode
     * conflicts across the merge boundary — see
     * {@see RuleOptionThresholdModeResolver} for why a later layer's
     * `threshold` must displace an earlier layer's `warning`/`error` (and
     * vice versa) instead of letting both survive into the array
     * {@see \Qualimetrix\Core\Rule\RuleOptionsInterface::fromArray()}
     * receives. Applied recursively, so hierarchical rule levels (e.g.
     * `callable:`/`class:`) get eviction scoped to the level the conflicting
     * keys actually live at — `$path` tracks the dot-joined nesting (`''`,
     * `'method'`, `'class'`, ...) consulted by
     * {@see RuleThresholdKeyGroupRegistry}. A conflict where $override
     * itself sets both keys (same source) is left untouched, since only
     * $base is ever modified, and still surfaces as a genuine configuration
     * error.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function deepMerge(array $base, array $override, string $ruleName, string $path = ''): array
    {
        $result = RuleOptionThresholdModeResolver::evictOverriddenMode($base, $override, $ruleName, $path);

        foreach ($override as $key => $value) {
            if (\is_array($value) && isset($result[$key]) && \is_array($result[$key])) {
                $childPath = $path === '' ? (string) $key : $path . '.' . $key;
                $result[$key] = $this->deepMerge($result[$key], $value, $ruleName, $childPath);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
