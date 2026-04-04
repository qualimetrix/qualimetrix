<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

use InvalidArgumentException;
use Qualimetrix\Core\Rule\RuleMatcher;
use Qualimetrix\Core\Violation\Severity;

/**
 * Value object containing general analysis configuration (not rule-specific).
 *
 * @qmx-threshold code-smell.constructor-overinjection error=20
 * @qmx-threshold code-smell.long-parameter-list error=20
 * Flat readonly VO with named arguments — not a service with too many
 * dependencies. Splitting into sub-objects would add indirection without
 * improving cohesion. Threshold raised to accommodate config growth.
 */
final readonly class AnalysisConfiguration
{
    public const string DEFAULT_CACHE_DIR = '.qmx-cache';
    public const string DEFAULT_FORMAT = 'summary';
    public const string DEFAULT_NAMESPACE_STRATEGY = 'chain';

    /** @var list<string> */
    private const array ALLOWED_NAMESPACE_STRATEGIES = ['chain', 'psr4', 'tokenizer'];

    /**
     * Special value for workers: sequential processing (no parallelism).
     * Auto-detect CPU cores is represented by null.
     */
    public const int WORKERS_SEQUENTIAL = 0;

    /**
     * @param string $cacheDir Directory for cache files
     * @param bool $cacheEnabled Whether caching is enabled
     * @param string $format Output format (text)
     * @param string $namespaceStrategy Namespace detection strategy (psr4, tokenizer, chain)
     * @param string|null $composerJsonPath Path to composer.json for PSR-4 detection
     * @param list<string> $aggregationPrefixes Namespace prefixes for aggregation
     * @param int|null $aggregationAutoDepth Auto-detect depth for namespace aggregation
     * @param list<string> $disabledRules List of disabled rule names
     * @param list<string> $onlyRules List of rules to run (empty = all enabled)
     * @param list<string> $excludePaths Path patterns to suppress violations for
     * @param list<string> $excludeNamespaces Namespace prefixes to suppress violations for
     * @param int|null $workers Number of parallel workers (null = auto-detect, 1 = sequential)
     * @param string $projectRoot Project root directory (for parallel workers)
     * @param Severity|false|null $failOn Minimum severity to trigger non-zero exit code (null = default/error, false = none/never fail)
     * @param list<string> $excludeHealth Health dimensions to exclude from scoring (e.g., 'typing', 'complexity')
     * @param bool $includeGenerated Whether to include files marked with @generated annotation
     * @param list<string> $frameworkNamespaces Framework namespace prefixes for CBO_APP/CE_FRAMEWORK metrics
     * @param string|null $memoryLimit PHP memory limit override (e.g., '512M', '1G', '-1' for unlimited)
     */
    public function __construct(
        public string $cacheDir = self::DEFAULT_CACHE_DIR,
        public bool $cacheEnabled = true,
        public string $format = self::DEFAULT_FORMAT,
        public string $namespaceStrategy = self::DEFAULT_NAMESPACE_STRATEGY,
        public ?string $composerJsonPath = null,
        public array $aggregationPrefixes = [],
        public ?int $aggregationAutoDepth = null,
        public array $disabledRules = [],
        public array $onlyRules = [],
        public array $excludePaths = [],
        public array $excludeNamespaces = [],
        public ?int $workers = null,
        public string $projectRoot = '.',
        public Severity|false|null $failOn = null,
        public array $excludeHealth = [],
        public bool $includeGenerated = false,
        public array $frameworkNamespaces = [],
        public ?string $memoryLimit = null,
    ) {}

    /**
     * Creates configuration from array.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $namespaceStrategy = self::getString($config, ConfigSchema::NAMESPACE_STRATEGY, self::DEFAULT_NAMESPACE_STRATEGY);
        if (!\in_array($namespaceStrategy, self::ALLOWED_NAMESPACE_STRATEGIES, true)) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid value "%s" for "%s". Allowed values: %s',
                $namespaceStrategy,
                ConfigSchema::NAMESPACE_STRATEGY,
                implode(', ', self::ALLOWED_NAMESPACE_STRATEGIES),
            ));
        }

        $workers = self::getIntOrNull($config, ConfigSchema::PARALLEL_WORKERS);
        if ($workers !== null && $workers < 0) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid value "%d" for "%s": must be non-negative',
                $workers,
                ConfigSchema::PARALLEL_WORKERS,
            ));
        }

        $aggregationAutoDepth = self::getIntOrNull($config, ConfigSchema::AGGREGATION_AUTO_DEPTH);
        if ($aggregationAutoDepth !== null && $aggregationAutoDepth <= 0) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid value "%d" for "%s": must be positive',
                $aggregationAutoDepth,
                ConfigSchema::AGGREGATION_AUTO_DEPTH,
            ));
        }

        return new self(
            cacheDir: self::getString($config, ConfigSchema::CACHE_DIR, self::DEFAULT_CACHE_DIR),
            cacheEnabled: self::getBool($config, ConfigSchema::CACHE_ENABLED, true),
            format: self::getString($config, ConfigSchema::FORMAT, self::DEFAULT_FORMAT),
            namespaceStrategy: $namespaceStrategy,
            composerJsonPath: self::getStringOrNull($config, ConfigSchema::NAMESPACE_COMPOSER_JSON),
            aggregationPrefixes: self::getStringList($config, ConfigSchema::AGGREGATION_PREFIXES),
            aggregationAutoDepth: $aggregationAutoDepth,
            disabledRules: self::getStringList($config, ConfigSchema::DISABLED_RULES),
            onlyRules: self::getStringList($config, ConfigSchema::ONLY_RULES),
            excludePaths: self::getStringList($config, ConfigSchema::EXCLUDE_PATHS),
            excludeNamespaces: self::getStringList($config, ConfigSchema::EXCLUDE_NAMESPACES),
            workers: $workers,
            projectRoot: self::getString($config, ConfigSchema::PROJECT_ROOT, '.'),
            failOn: self::getFailOn($config, ConfigSchema::FAIL_ON),
            excludeHealth: self::getStringList($config, ConfigSchema::EXCLUDE_HEALTH),
            includeGenerated: self::getBool($config, ConfigSchema::INCLUDE_GENERATED, false),
            frameworkNamespaces: self::getStringList($config, ConfigSchema::COUPLING_FRAMEWORK_NAMESPACES),
            memoryLimit: self::getMemoryLimit($config, ConfigSchema::MEMORY_LIMIT),
        );
    }

    /**
     * Creates new configuration with values merged from another config array.
     *
     * @param array<string, mixed> $overrides
     */
    public function merge(array $overrides): self
    {
        return new self(
            cacheDir: self::getString($overrides, ConfigSchema::CACHE_DIR, $this->cacheDir),
            cacheEnabled: self::getBool($overrides, ConfigSchema::CACHE_ENABLED, $this->cacheEnabled),
            format: self::getString($overrides, ConfigSchema::FORMAT, $this->format),
            namespaceStrategy: self::getString($overrides, ConfigSchema::NAMESPACE_STRATEGY, $this->namespaceStrategy),
            composerJsonPath: self::getStringOrNull($overrides, ConfigSchema::NAMESPACE_COMPOSER_JSON) ?? $this->composerJsonPath,
            aggregationPrefixes: self::hasNestedValue($overrides, ConfigSchema::AGGREGATION_PREFIXES)
                ? self::getStringList($overrides, ConfigSchema::AGGREGATION_PREFIXES)
                : $this->aggregationPrefixes,
            aggregationAutoDepth: self::getIntOrNull($overrides, ConfigSchema::AGGREGATION_AUTO_DEPTH) ?? $this->aggregationAutoDepth,
            disabledRules: array_values(array_unique([...$this->disabledRules, ...self::getStringList($overrides, ConfigSchema::DISABLED_RULES)])),
            onlyRules: self::hasNestedValue($overrides, ConfigSchema::ONLY_RULES)
                ? self::getStringList($overrides, ConfigSchema::ONLY_RULES)
                : $this->onlyRules,
            excludePaths: array_values(array_unique([...$this->excludePaths, ...self::getStringList($overrides, ConfigSchema::EXCLUDE_PATHS)])),
            excludeNamespaces: array_values(array_unique([...$this->excludeNamespaces, ...self::getStringList($overrides, ConfigSchema::EXCLUDE_NAMESPACES)])),
            workers: self::getIntOrNull($overrides, ConfigSchema::PARALLEL_WORKERS) ?? $this->workers,
            projectRoot: self::getString($overrides, ConfigSchema::PROJECT_ROOT, $this->projectRoot),
            failOn: self::getFailOn($overrides, ConfigSchema::FAIL_ON) ?? $this->failOn,
            excludeHealth: self::hasNestedValue($overrides, ConfigSchema::EXCLUDE_HEALTH)
                ? self::getStringList($overrides, ConfigSchema::EXCLUDE_HEALTH)
                : $this->excludeHealth,
            includeGenerated: self::getBool($overrides, ConfigSchema::INCLUDE_GENERATED, $this->includeGenerated),
            frameworkNamespaces: self::hasNestedValue($overrides, ConfigSchema::COUPLING_FRAMEWORK_NAMESPACES)
                ? self::getStringList($overrides, ConfigSchema::COUPLING_FRAMEWORK_NAMESPACES)
                : $this->frameworkNamespaces,
            memoryLimit: self::getMemoryLimit($overrides, ConfigSchema::MEMORY_LIMIT) ?? $this->memoryLimit,
        );
    }

    /**
     * Checks if a rule should be executed based on disabled_rules and only_rules settings.
     *
     * Uses prefix matching: 'complexity' matches 'complexity.cyclomatic', 'complexity.npath', etc.
     * Also enables rules when onlyRules contain more specific patterns (e.g., 'complexity.method'
     * enables rule 'complexity' so that its violations can be filtered by violationCode).
     */
    public function isRuleEnabled(string $ruleName): bool
    {
        if (RuleMatcher::anyMatches($this->disabledRules, $ruleName)) {
            return false;
        }

        if ($this->onlyRules === []) {
            return true;
        }

        // Check both directions: pattern matches ruleName OR ruleName is prefix of pattern
        return RuleMatcher::anyMatches($this->onlyRules, $ruleName)
            || RuleMatcher::anyReverseMatches($this->onlyRules, $ruleName);
    }

    /**
     * Checks if a violation code should be included in results.
     *
     * Uses prefix matching: 'complexity' matches 'complexity.cyclomatic.method', etc.
     * This is used to filter individual violations after rule execution.
     */
    public function isViolationCodeEnabled(string $violationCode): bool
    {
        if (RuleMatcher::anyMatches($this->disabledRules, $violationCode)) {
            return false;
        }

        if ($this->onlyRules === []) {
            return true;
        }

        return RuleMatcher::anyMatches($this->onlyRules, $violationCode);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function getString(array $config, string $path, string $default): string
    {
        $value = self::getNestedValue($config, $path);

        // Absent key or explicit null (YAML `~`) — use default
        if ($value === null) {
            return $default;
        }

        if (!\is_string($value)) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid value for "%s": expected string, got %s',
                $path,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function getStringOrNull(array $config, string $path): ?string
    {
        $value = self::getNestedValue($config, $path);

        if ($value === null) {
            return null;
        }

        if (!\is_string($value)) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid value for "%s": expected string, got %s',
                $path,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function getBool(array $config, string $path, bool $default): bool
    {
        $value = self::getNestedValue($config, $path);

        // Absent key or explicit null (YAML `~`) — use default
        if ($value === null) {
            return $default;
        }

        if (!\is_bool($value)) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid value for "%s": expected boolean, got %s',
                $path,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function getIntOrNull(array $config, string $path): ?int
    {
        $value = self::getNestedValue($config, $path);

        if ($value === null) {
            return null;
        }

        if (!\is_int($value)) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid value for "%s": expected integer or null, got %s',
                $path,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function getFailOn(array $config, string $path): Severity|false|null
    {
        $value = self::getNestedValue($config, $path);

        if ($value === false) {
            return false;
        }

        if ($value instanceof Severity) {
            return $value;
        }

        if (\is_string($value)) {
            if ($value === 'none') {
                return false;
            }

            $severity = Severity::tryFrom($value);

            if ($severity === null) {
                throw new InvalidArgumentException(\sprintf(
                    'Invalid value "%s" for "%s". Allowed values: none, %s',
                    $value,
                    $path,
                    implode(', ', array_map(static fn(Severity $s) => $s->value, Severity::cases())),
                ));
            }

            return $severity;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private static function getStringList(array $config, string $path): array
    {
        $value = self::getNestedValue($config, $path);

        // Absent key or explicit null (YAML `~`) — use default
        if ($value === null) {
            return [];
        }

        if (!\is_array($value)) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid value for "%s": expected array, got %s',
                $path,
                get_debug_type($value),
            ));
        }

        foreach ($value as $i => $item) {
            if (!\is_string($item)) {
                throw new InvalidArgumentException(\sprintf(
                    'Invalid element at index %d in "%s": expected string, got %s',
                    $i,
                    $path,
                    get_debug_type($item),
                ));
            }
        }

        return array_values($value);
    }

    /**
     * Extracts and validates a PHP memory limit value.
     *
     * Accepts: '-1' (unlimited), integer bytes, or string with K/M/G suffix.
     * Integer values from YAML (e.g., `memory_limit: 512`) are treated as bytes.
     *
     * @param array<string, mixed> $config
     */
    private static function getMemoryLimit(array $config, string $path): ?string
    {
        $value = self::getNestedValue($config, $path);

        if ($value === null) {
            return null;
        }

        // Handle integer values from YAML (e.g., memory_limit: 512)
        if (\is_int($value)) {
            return (string) $value;
        }

        if (!\is_string($value)) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid value for "%s": expected string, integer or null, got %s',
                $path,
                get_debug_type($value),
            ));
        }

        // Validate format: -1, or digits optionally followed by K/M/G
        if (!preg_match('/\A(?:-1|[1-9]\d*[KMGkmg]?)\z/', $value)) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid value "%s" for "%s". Expected: -1 (unlimited), integer bytes, or integer with K/M/G suffix (e.g., 512M, 1G)',
                $value,
                $path,
            ));
        }

        return $value;
    }

    /**
     * Checks if a value exists in the config array (flat or nested key).
     *
     * @param array<string, mixed> $config
     */
    private static function hasNestedValue(array $config, string $path): bool
    {
        // Check flat key first (e.g., 'only_rules' from CLI stage)
        if (\array_key_exists($path, $config)) {
            return true;
        }

        // Then try nested access (e.g., config['aggregation']['prefixes'] from YAML)
        $keys = explode('.', $path);
        $current = $config;

        foreach ($keys as $key) {
            if (!\is_array($current) || !\array_key_exists($key, $current)) {
                return false;
            }
            $current = $current[$key];
        }

        return true;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function getNestedValue(array $config, string $path): mixed
    {
        // First, check for flat key (e.g., 'parallel.workers' from CLI stage)
        if (\array_key_exists($path, $config)) {
            return $config[$path];
        }

        // Then try nested access (e.g., config['parallel']['workers'] from YAML)
        $keys = explode('.', $path);
        $current = $config;

        foreach ($keys as $key) {
            if (!\is_array($current) || !\array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }
}
