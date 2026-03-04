<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Factory for creating RuleOptions instances with merged configuration.
 *
 * Priority: defaults → config file → CLI options
 */
final class RuleOptionsFactory
{
    /**
     * @var array<string, array<string, mixed>> Rule options from config file
     */
    private array $configFileOptions = [];

    /**
     * @var array<string, array<string, mixed>> Rule options from CLI
     */
    private array $cliOptions = [];

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

        // 2. Merge with config file options
        $fileOptions = $this->configFileOptions[$ruleName] ?? [];
        $merged = $this->deepMerge($defaults, $this->normalizeKeys($fileOptions));

        // 3. Merge with CLI options (highest priority)
        // Expand dot notation (e.g., 'method.warning' => ['method' => ['warning' => ...]])
        $cliRuleOptions = $this->expandDotNotation($this->cliOptions[$ruleName] ?? []);
        $merged = $this->deepMerge($merged, $cliRuleOptions);

        // 4. Create instance using fromArray
        return $optionsClass::fromArray($merged);
    }

    /**
     * Sets rule options from config file.
     *
     * @param array<string, array<string, mixed>> $options
     */
    public function setConfigFileOptions(array $options): void
    {
        $this->configFileOptions = $options;
    }

    /**
     * Gets rule options from config file.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getConfigFileOptions(): array
    {
        return $this->configFileOptions;
    }

    /**
     * Adds a CLI option for a specific rule.
     */
    public function addCliOption(string $ruleName, string $option, mixed $value): void
    {
        if (!isset($this->cliOptions[$ruleName])) {
            $this->cliOptions[$ruleName] = [];
        }

        $this->cliOptions[$ruleName][$option] = $value;
    }

    /**
     * Sets multiple CLI options for a rule.
     *
     * @param array<string, mixed> $options
     */
    public function setCliOptions(string $ruleName, array $options): void
    {
        $this->cliOptions[$ruleName] = $options;
    }

    /**
     * Gets all CLI options.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getCliOptions(): array
    {
        return $this->cliOptions;
    }

    /**
     * Clears all options (useful for testing).
     */
    public function reset(): void
    {
        $this->configFileOptions = [];
        $this->cliOptions = [];
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
