<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration\Pipeline\Stage;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\KnownRuleNamesProviderInterface;
use Qualimetrix\Configuration\Loader\ConfigLoaderInterface;
use Qualimetrix\Configuration\Pipeline\ConfigDataNormalizer;
use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\ConfigurationLayer;
use Qualimetrix\Configuration\Pipeline\RuleNameValidator;

/**
 * Loads configuration from config file (priority: 20).
 *
 * Searches for qmx.yaml or qmx.yml in working directory.
 */
final class ConfigFileStage implements ConfigurationStageInterface
{
    private const int PRIORITY = 20;

    /** @var list<string> */
    private const array CONFIG_FILE_NAMES = ['qmx.yaml', 'qmx.yml'];

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
     * Otherwise, auto-detects qmx.yaml or qmx.yml in the working directory.
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
        return ConfigDataNormalizer::normalize($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function warnAboutUnknownRuleNames(array $data, string $configSource): void
    {
        if ($this->knownRuleNamesProvider === null) {
            return;
        }

        RuleNameValidator::warnAboutUnknownRuleNames($data, $configSource, $this->knownRuleNamesProvider, $this->logger);
    }
}
