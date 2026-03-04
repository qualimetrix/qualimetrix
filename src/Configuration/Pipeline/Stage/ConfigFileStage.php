<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration\Pipeline\Stage;

use AiMessDetector\Configuration\Loader\ConfigLoaderInterface;
use AiMessDetector\Configuration\Pipeline\ConfigurationContext;
use AiMessDetector\Configuration\Pipeline\ConfigurationLayer;

/**
 * Loads configuration from config file (priority: 20).
 *
 * Searches for aimd.yaml, aimd.yml, .aimd.yaml, .aimd.yml in working directory.
 */
final class ConfigFileStage implements ConfigurationStageInterface
{
    private const int PRIORITY = 20;

    /** @var list<string> */
    private const array CONFIG_FILE_NAMES = [
        'aimd.yaml',
        'aimd.yml',
        '.aimd.yaml',
        '.aimd.yml',
    ];

    public function __construct(
        private readonly ConfigLoaderInterface $loader,
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
        $configPath = $this->findConfigFile($context->workingDirectory);

        if ($configPath === null) {
            return null;
        }

        $data = $this->loader->load($configPath);

        return new ConfigurationLayer(
            basename($configPath),
            $this->normalizeConfigData($data),
        );
    }

    private function findConfigFile(string $dir): ?string
    {
        foreach (self::CONFIG_FILE_NAMES as $name) {
            $path = $dir . '/' . $name;
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

        return $result;
    }
}
