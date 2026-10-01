<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline\Stage;

use DirectoryIterator;
use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;

use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Loader\ConfigLoaderInterface;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigDataNormalizer;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationLayer;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationStageInterface;
use UnexpectedValueException;

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
    ) {}

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function name(): string
    {
        return 'config_file';
    }

    public function apply(ConfigurationResolutionRequest $request): ?ConfigurationLayer
    {
        [$configPath, $diagnostics] = $this->resolveConfigPath($request);

        if ($configPath === null) {
            return $diagnostics === [] ? null : new ConfigurationLayer('config_file', [], diagnostics: $diagnostics);
        }

        $sourceName = $request->configFilePath ?? basename($configPath);
        $loaded = $this->loader->read($configPath, $sourceName);

        return new ConfigurationLayer(
            basename($configPath),
            $this->normalizeConfigData($loaded->values),
            authored: [new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $sourceName), $loaded->authored)],
            deferredRefusals: $loaded->deferredRefusal === null ? [] : [$loaded->deferredRefusal],
            diagnostics: $diagnostics,
        );
    }

    /**
     * Resolves the config file path.
     *
     * If an explicit path was provided via --config, uses that (throws on missing file).
     * Otherwise, auto-detects qmx.yaml or qmx.yml in the working directory.
     *
     * @return array{?string, list<ConfigurationDiagnostic>}
     */
    private function resolveConfigPath(ConfigurationResolutionRequest $request): array
    {
        if ($request->configFilePath !== null) {
            if (!file_exists($request->configFilePath)) {
                throw ConfigurationRefusal::aboutConfigFileDocument(
                    $request->configFilePath,
                    \sprintf('Configuration file not found: %s', $request->configFilePath),
                );
            }

            return [$request->configFilePath, []];
        }

        return $this->findConfigFile($request->workingDirectory->value());
    }

    /** @return array{?string, list<ConfigurationDiagnostic>} */
    private function findConfigFile(string $dir): array
    {
        try {
            $entries = [];
            foreach (new DirectoryIterator($dir) as $entry) {
                $entries[] = $entry->getFilename();
            }
            sort($entries, \SORT_STRING);
        } catch (UnexpectedValueException) {
            throw ConfigurationRefusal::aboutConfigFileDocument(
                '.',
                'Configuration directory cannot be listed: .',
            );
        }

        $exact = array_values(array_intersect($entries, self::CONFIG_FILE_NAMES));
        if (\count($exact) > 1) {
            throw ConfigurationRefusal::aboutConfigFileDocument(
                '.',
                'Both qmx.yaml and qmx.yml exist; keep exactly one configuration file.',
            );
        }

        if ($exact !== []) {
            return [$dir . '/' . $exact[0], []];
        }

        $diagnostics = [];
        foreach ($entries as $entry) {
            if (!self::isNearConfigName($entry)) {
                continue;
            }

            $diagnostics[] = new ConfigurationDiagnostic(
                \sprintf(
                    'Ignored configuration-like filename "%s"; auto-discovery accepts only exact qmx.yaml or qmx.yml.',
                    $entry,
                ),
                [new Provenance(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $entry), null, 0)],
            );
        }

        return [null, $diagnostics];
    }

    private static function isNearConfigName(string $entry): bool
    {
        $name = strtolower($entry);
        if ($name === 'qmx') {
            return true;
        }

        return preg_match('/(?:^|[._-])qmx(?:[._-]|$)/', $name) === 1
            && (str_contains($name, '.yaml') || str_contains($name, '.yml'));
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

}
