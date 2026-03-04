<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration;

use AiMessDetector\Core\Rule\RuleLevel;

/**
 * Value object containing general analysis configuration (not rule-specific).
 */
final readonly class AnalysisConfiguration
{
    public const string DEFAULT_CACHE_DIR = '.aimd-cache';
    public const string DEFAULT_FORMAT = 'text';
    public const string DEFAULT_NAMESPACE_STRATEGY = 'chain';

    /**
     * Special value for workers: auto-detect CPU cores.
     */
    public const int WORKERS_AUTO = 0;

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
     * @param int|null $workers Number of parallel workers (null = auto-detect, 1 = sequential)
     * @param string $projectRoot Project root directory (for parallel workers)
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
        public ?int $workers = null,
        public string $projectRoot = '.',
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
            workers: self::getIntOrNull($config, 'parallel.workers'),
            projectRoot: self::getString($config, 'project_root', '.'),
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
            aggregationPrefixes: self::getStringList($overrides, 'aggregation.prefixes') ?: $this->aggregationPrefixes,
            aggregationAutoDepth: self::getIntOrNull($overrides, 'aggregation.auto_depth') ?? $this->aggregationAutoDepth,
            disabledRules: array_values(array_unique([...$this->disabledRules, ...self::getStringList($overrides, 'disabled_rules')])),
            onlyRules: self::getStringList($overrides, 'only_rules') ?: $this->onlyRules,
            workers: self::getIntOrNull($overrides, 'parallel.workers') ?? $this->workers,
            projectRoot: self::getString($overrides, 'project_root', $this->projectRoot),
        );
    }

    /**
     * Checks if a rule should be executed based on disabled_rules and only_rules settings.
     */
    public function isRuleEnabled(string $ruleName): bool
    {
        if (\in_array($ruleName, $this->disabledRules, true)) {
            return false;
        }

        if ($this->onlyRules !== [] && !\in_array($ruleName, $this->onlyRules, true)) {
            return false;
        }

        return true;
    }

    /**
     * Checks if a rule at a specific level should be executed.
     *
     * Supports dot notation in disabled_rules:
     * - 'complexity' disables all levels of complexity rule
     * - 'complexity.class' disables only class level
     *
     * For only_rules:
     * - 'complexity' enables all levels
     * - 'complexity.method' enables only method level
     */
    public function isRuleLevelEnabled(string $ruleName, RuleLevel $level): bool
    {
        $levelKey = $ruleName . '.' . $level->value;

        // Check disabled_rules: both 'rule' and 'rule.level' can disable
        if (\in_array($ruleName, $this->disabledRules, true)) {
            return false;
        }
        if (\in_array($levelKey, $this->disabledRules, true)) {
            return false;
        }

        // Check only_rules: if specified, 'rule' or 'rule.level' must be present
        if ($this->onlyRules !== []) {
            $ruleMatches = \in_array($ruleName, $this->onlyRules, true);
            $levelMatches = \in_array($levelKey, $this->onlyRules, true);

            // Check if any level of this rule is in only_rules (e.g., complexity.method)
            $anyLevelMatches = false;
            foreach ($this->onlyRules as $only) {
                if (str_starts_with($only, $ruleName . '.')) {
                    $anyLevelMatches = true;
                    break;
                }
            }

            // If rule itself is in only_rules, all levels are enabled
            if ($ruleMatches && !$anyLevelMatches) {
                return true;
            }

            // If specific levels are specified, only those are enabled
            if ($anyLevelMatches) {
                return $levelMatches;
            }

            // Neither rule nor any level matches
            return false;
        }

        return true;
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
