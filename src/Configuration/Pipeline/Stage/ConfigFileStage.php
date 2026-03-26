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
