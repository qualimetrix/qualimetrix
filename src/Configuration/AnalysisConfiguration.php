<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

use InvalidArgumentException;
use Qualimetrix\Core\Rule\RuleMatcher;
use Qualimetrix\Core\Violation\Severity;

/**
 * Value object containing general analysis configuration (not rule-specific).
 */
final readonly class AnalysisConfiguration
{
    public const string DEFAULT_CACHE_DIR = '.qmx-cache';
    public const string DEFAULT_FORMAT = 'summary';
    public const string DEFAULT_NAMESPACE_STRATEGY = 'chain';

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
     * @param int|null $workers Number of parallel workers (null = auto-detect, 1 = sequential)
     * @param string $projectRoot Project root directory (for parallel workers)
     * @param Severity|false|null $failOn Minimum severity to trigger non-zero exit code (null = default/error, false = none/never fail)
     * @param list<string> $excludeHealth Health dimensions to exclude from scoring (e.g., 'typing', 'complexity')
     * @param bool $includeGenerated Whether to include files marked with @generated annotation
     * @param list<string> $frameworkNamespaces Framework namespace prefixes for CBO_APP/CE_FRAMEWORK metrics
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
        public ?int $workers = null,
        public string $projectRoot = '.',
        public Severity|false|null $failOn = null,
        public array $excludeHealth = [],
        public bool $includeGenerated = false,
        public array $frameworkNamespaces = [],
    ) {}

    /**
     * Creates configuration from array.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            cacheDir: self::getString($config, 'cache.dir', self::DEFAULT_CACHE_DIR),
            cacheEnabled: self::getBool($config, 'cache.enabled', true),
            format: self::getString($config, 'format', self::DEFAULT_FORMAT),
            namespaceStrategy: self::getString($config, 'namespace.strategy', self::DEFAULT_NAMESPACE_STRATEGY),
            composerJsonPath: self::getStringOrNull($config, 'namespace.composer_json'),
            aggregationPrefixes: self::getStringList($config, 'aggregation.prefixes'),
            aggregationAutoDepth: self::getIntOrNull($config, 'aggregation.auto_depth'),
            disabledRules: self::getStringList($config, 'disabled_rules'),
            onlyRules: self::getStringList($config, 'only_rules'),
            excludePaths: self::getStringList($config, 'exclude_paths'),
            workers: self::getIntOrNull($config, 'parallel.workers'),
            projectRoot: self::getString($config, 'project_root', '.'),
            failOn: self::getFailOn($config, 'fail_on'),
            excludeHealth: self::getStringList($config, 'exclude_health'),
            includeGenerated: self::getBool($config, 'include_generated', false),
            frameworkNamespaces: self::getStringList($config, 'coupling.framework_namespaces'),
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
            cacheDir: self::getString($overrides, 'cache.dir', $this->cacheDir),
            cacheEnabled: self::getBool($overrides, 'cache.enabled', $this->cacheEnabled),
            format: self::getString($overrides, 'format', $this->format),
            namespaceStrategy: self::getString($overrides, 'namespace.strategy', $this->namespaceStrategy),
            composerJsonPath: self::getStringOrNull($overrides, 'namespace.composer_json') ?? $this->composerJsonPath,
            aggregationPrefixes: self::hasNestedValue($overrides, 'aggregation.prefixes')
                ? self::getStringList($overrides, 'aggregation.prefixes')
                : $this->aggregationPrefixes,
            aggregationAutoDepth: self::getIntOrNull($overrides, 'aggregation.auto_depth') ?? $this->aggregationAutoDepth,
            disabledRules: array_values(array_unique([...$this->disabledRules, ...self::getStringList($overrides, 'disabled_rules')])),
            onlyRules: self::hasNestedValue($overrides, 'only_rules')
                ? self::getStringList($overrides, 'only_rules')
                : $this->onlyRules,
            excludePaths: array_values(array_unique([...$this->excludePaths, ...self::getStringList($overrides, 'exclude_paths')])),
            workers: self::getIntOrNull($overrides, 'parallel.workers') ?? $this->workers,
            projectRoot: self::getString($overrides, 'project_root', $this->projectRoot),
            failOn: self::getFailOn($overrides, 'fail_on') ?? $this->failOn,
            excludeHealth: self::hasNestedValue($overrides, 'exclude_health')
                ? self::getStringList($overrides, 'exclude_health')
                : $this->excludeHealth,
            includeGenerated: self::getBool($overrides, 'include_generated', $this->includeGenerated),
            frameworkNamespaces: self::hasNestedValue($overrides, 'coupling.framework_namespaces')
                ? self::getStringList($overrides, 'coupling.framework_namespaces')
                : $this->frameworkNamespaces,
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

        return \is_string($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function getStringOrNull(array $config, string $path): ?string
    {
        $value = self::getNestedValue($config, $path);

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function getBool(array $config, string $path, bool $default): bool
    {
        $value = self::getNestedValue($config, $path);

        return \is_bool($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function getIntOrNull(array $config, string $path): ?int
    {
        $value = self::getNestedValue($config, $path);

        return \is_int($value) ? $value : null;
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

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
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
