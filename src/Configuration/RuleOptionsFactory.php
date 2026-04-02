<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Core\Rule\RuleOptionsInterface;
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

        // 2. Merge with config file options (normalize scalars to arrays)
        $configFileOptions = $this->registry->getConfigFileOptions();
        $fileOptions = $this->normalizeScalarConfig($configFileOptions[$ruleName] ?? []);
        $merged = $this->deepMerge($defaults, $this->normalizeKeys($fileOptions));

        // 3. Merge with CLI options (highest priority)
        // Expand dot notation (e.g., 'method.warning' => ['method' => ['warning' => ...]])
        $cliOptions = $this->registry->getCliOptions();
        $cliRuleOptions = $this->expandDotNotation($cliOptions[$ruleName] ?? []);
        $merged = $this->deepMerge($merged, $cliRuleOptions);

        // 4. Extract and store exclude_namespaces at framework level
        $this->extractExcludeNamespaces($ruleName, $merged);

        // 4b. Extract and store exclude_paths at framework level
        $this->extractExcludePaths($ruleName, $merged);

        // 5. Warn about unknown option keys
        $this->warnAboutUnknownKeys($merged, $defaults, $ruleName);

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
        $raw = $merged['excludeNamespaces'] ?? $merged['exclude_namespaces'] ?? null;

        unset($merged['excludeNamespaces'], $merged['exclude_namespaces']);

        if (\is_string($raw)) {
            $prefixes = [$raw];
        } elseif (\is_array($raw)) {
            $prefixes = array_values($raw);
        } else {
            return;
        }

        if ($prefixes !== []) {
            $this->registry->getExclusionProvider()->setExclusions($ruleName, $prefixes);
        }
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
        $raw = $merged['excludePaths'] ?? $merged['exclude_paths'] ?? null;

        unset($merged['excludePaths'], $merged['exclude_paths']);

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
            return ['enabled' => false];
        }

        if ($config === true) {
            return ['enabled' => true];
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
     * E.g., ['method.warning' => 5] becomes ['method' => ['warning' => 5]]
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
     * Compares merged config keys against known constructor parameters.
     * Framework-level keys (excludeNamespaces, excludePaths) are excluded
     * since they are extracted before fromArray().
     *
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $defaults
     */
    private function warnAboutUnknownKeys(array $merged, array $defaults, string $ruleName): void
    {
        // Framework-level keys that are valid but not in the options constructor
        static $frameworkKeys = ['excludeNamespaces', 'exclude_namespaces', 'excludePaths', 'exclude_paths'];

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

        foreach (array_keys($merged) as $key) {
            if (\in_array($key, $knownKeys, true)) {
                continue;
            }

            $this->logger->warning(\sprintf(
                'Unknown option "%s" for rule "%s". Available options: %s',
                $key,
                $ruleName,
                implode(', ', array_keys($defaults)),
            ));
        }
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
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function deepMerge(array $base, array $override): array
    {
        $result = $base;

        foreach ($override as $key => $value) {
            if (\is_array($value) && isset($result[$key]) && \is_array($result[$key])) {
                $result[$key] = $this->deepMerge($result[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
