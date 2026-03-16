<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration\Pipeline\Stage;

use AiMessDetector\Configuration\Exception\ConfigLoadException;
use AiMessDetector\Configuration\KnownRuleNamesProviderInterface;
use AiMessDetector\Configuration\Loader\ConfigLoaderInterface;
use AiMessDetector\Configuration\Pipeline\ConfigurationContext;
use AiMessDetector\Configuration\Pipeline\ConfigurationLayer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Loads configuration from config file (priority: 20).
 *
 * Searches for aimd.yaml or aimd.yml in working directory.
 */
final class ConfigFileStage implements ConfigurationStageInterface
{
    private const int PRIORITY = 20;

    /** @var list<string> */
    private const array CONFIG_FILE_NAMES = ['aimd.yaml', 'aimd.yml'];

    public function __construct(
        private readonly ConfigLoaderInterface $loader,
        private readonly ?KnownRuleNamesProviderInterface $knownRuleNamesProvider = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function name(): string
    {
        return 'config_file';
    }

    public function apply(ConfigurationContext $context): ?ConfigurationLayer
    {
        $configPath = $this->resolveConfigPath($context);

        if ($configPath === null) {
            return null;
        }

        $data = $this->loader->load($configPath);

        $this->warnAboutUnknownRuleNames($data, basename($configPath));

        return new ConfigurationLayer(
            basename($configPath),
            $this->normalizeConfigData($data),
        );
    }

    /**
     * Resolves the config file path.
     *
     * If an explicit path was provided via --config, uses that (throws on missing file).
     * Otherwise, auto-detects aimd.yaml or aimd.yml in the working directory.
     */
    private function resolveConfigPath(ConfigurationContext $context): ?string
    {
        if ($context->configFilePath !== null) {
            if (!file_exists($context->configFilePath)) {
                throw ConfigLoadException::fileNotFound($context->configFilePath);
            }

            return $context->configFilePath;
        }

        return $this->findConfigFile($context->workingDirectory);
    }

    private function findConfigFile(string $dir): ?string
    {
        foreach (self::CONFIG_FILE_NAMES as $fileName) {
            $path = $dir . '/' . $fileName;
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Normalizes config data to flat dot-notation keys.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalizeConfigData(array $data): array
    {
        $result = [];

        // Direct keys: paths and exclude
        if (isset($data['paths'])) {
            $result['paths'] = $data['paths'];
        }
        if (isset($data['exclude'])) {
            $result['excludes'] = $data['exclude'];
        }

        // Cache section
        if (isset($data['cache']['dir'])) {
            $result['cache.dir'] = $data['cache']['dir'];
        }
        if (isset($data['cache']['enabled'])) {
            $result['cache.enabled'] = $data['cache']['enabled'];
        }

        // Format
        if (isset($data['format'])) {
            $result['format'] = $data['format'];
        }

        // Namespace section
        if (isset($data['namespace']['strategy'])) {
            $result['namespace.strategy'] = $data['namespace']['strategy'];
        }
        if (isset($data['namespace']['composerJson'])) {
            $result['namespace.composer_json'] = $data['namespace']['composerJson'];
        }

        // Aggregation section
        if (isset($data['aggregation']['prefixes'])) {
            $result['aggregation.prefixes'] = $data['aggregation']['prefixes'];
        }
        if (isset($data['aggregation']['autoDepth'])) {
            $result['aggregation.auto_depth'] = $data['aggregation']['autoDepth'];
        }

        // Rules section (pass as-is)
        if (isset($data['rules'])) {
            $result['rules'] = $data['rules'];
        }

        // Disabled/only rules
        if (isset($data['disabledRules'])) {
            $result['disabled_rules'] = $data['disabledRules'];
        }
        if (isset($data['onlyRules'])) {
            $result['only_rules'] = $data['onlyRules'];
        }

        // Exclude paths (violation suppression patterns)
        if (isset($data['excludePaths'])) {
            $result['exclude_paths'] = $data['excludePaths'];
        }

        // Computed metrics (pass as-is, resolved later by ComputedMetricsConfigResolver)
        if (isset($data['computed_metrics']) || isset($data['computedMetrics'])) {
            $result['computed_metrics'] = $data['computed_metrics'] ?? $data['computedMetrics'];
        }

        // Fail-on severity
        if (isset($data['failOn'])) {
            $result['fail_on'] = $data['failOn'];
        }

        // Exclude health dimensions
        if (isset($data['excludeHealth']) || isset($data['exclude_health'])) {
            $result['exclude_health'] = $data['excludeHealth'] ?? $data['exclude_health'];
        }

        return $result;
    }

    /**
     * Warns about unknown rule names in the "rules:" config section.
     *
     * Emits a PSR-3 warning for each rule name that does not match any registered rule.
     * Matching follows the same prefix logic as CLI --disable-rule / --only-rule:
     * exact match, prefix match ("complexity" matches "complexity.cyclomatic"), and
     * reverse prefix match ("complexity.cyclomatic.method" refines "complexity.cyclomatic").
     *
     * @param array<string, mixed> $data raw config data (before normalization)
     * @param string $configSource filename for warning messages
     */
    private function warnAboutUnknownRuleNames(array $data, string $configSource): void
    {
        if ($this->knownRuleNamesProvider === null) {
            return;
        }

        $rulesSection = $data['rules'] ?? null;
        if (!\is_array($rulesSection) || $rulesSection === []) {
            return;
        }

        $knownNames = $this->knownRuleNamesProvider->getKnownRuleNames();

        foreach (array_keys($rulesSection) as $configuredName) {
            $name = (string) $configuredName;
            $matched = false;
            foreach ($knownNames as $known) {
                if ($name === $known
                    || str_starts_with($known, $name . '.')
                    || str_starts_with($name, $known . '.')
                ) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $this->logger->warning(
                    'Unknown rule name "{rule}" in config file "{source}" — does not match any registered rule.',
                    ['rule' => $name, 'source' => $configSource],
                );
            }
        }
    }
}
